<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\AdminReport;
use App\Data\AdminReportDay;
use App\Data\AdminReportStuckGeneration;
use App\Data\AdminReportTotals;
use App\Data\AdminReportUser;
use App\Data\ProviderUsage;
use App\Enums\GenerationStatus;
use App\Services\Pixelforge\PixelforgeBusy;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgeRequestLimiter;
use App\Support\Money\Money;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Charged, provider cost and profit per UTC day and per user, from the billing
 * ledger, reconciled to the nano against Pixelforge's GET /v1/usage. Days are the
 * UTC date the generation completed at Pixelforge (the day Pixelforge bills it),
 * not the day the ledger row was written.
 */
final class BuildAdminReport
{
    public const int USAGE_CACHE_SECONDS = 60;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly PixelforgeRequestLimiter $limiter,
    ) {}

    public function handle(CarbonImmutable $start, CarbonImmutable $end): AdminReport
    {
        $start = $start->utc()->startOfDay();
        $end = $end->utc()->startOfDay();

        $localByDay = $this->localByDay($start, $end);
        $usage = $this->providerUsage($start, $end);

        $days = [];
        $totalGenerations = 0;
        $totalCharged = Money::zero();
        $totalProviderCost = Money::zero();
        $totalPixelforge = Money::zero();
        $allMatch = true;

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            [$count, $charged, $providerCost] = $localByDay[$key] ?? [0, Money::zero(), Money::zero()];

            $pixelforgeCost = $usage === null ? null : ($usage->costByDate[$key] ?? Money::zero());
            $difference = $pixelforgeCost === null ? null : $providerCost->minus($pixelforgeCost);
            $matches = $difference?->equals(Money::zero());

            if ($matches === false) {
                $allMatch = false;
            }

            if ($pixelforgeCost !== null) {
                $totalPixelforge = $totalPixelforge->plus($pixelforgeCost);
            }

            $days[] = new AdminReportDay(
                $key,
                $count,
                $charged,
                $providerCost,
                $charged->minus($providerCost),
                $pixelforgeCost,
                $difference,
                $matches,
            );

            $totalGenerations += $count;
            $totalCharged = $totalCharged->plus($charged);
            $totalProviderCost = $totalProviderCost->plus($providerCost);
        }

        return new AdminReport(
            $start,
            $end,
            $usage !== null,
            $days,
            $this->users($start, $end),
            new AdminReportTotals(
                $totalGenerations,
                $totalCharged,
                $totalProviderCost,
                $totalCharged->minus($totalProviderCost),
                $usage === null ? null : $totalPixelforge,
                $usage === null ? null : $allMatch,
            ),
            $this->stuck(),
        );
    }

    /**
     * In-flight generations with a provider id older than the stuck threshold, reported or not.
     *
     * @return list<AdminReportStuckGeneration>
     */
    private function stuck(): array
    {
        $rows = DB::table('generations')
            ->leftJoin('users', 'users.id', '=', 'generations.user_id')
            ->whereIn('generations.status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNotNull('generations.provider_id')
            ->where('generations.created_at', '<=', now()->subSeconds(ReconcileGenerations::stuckAfterSeconds()))
            ->orderBy('generations.created_at')
            ->orderBy('generations.id')
            ->limit(50)
            ->get(['generations.id', 'generations.status', 'generations.provider_id', 'generations.created_at', 'users.email']);

        $stuck = [];

        foreach ($rows as $row) {
            $stuck[] = new AdminReportStuckGeneration(
                $this->text($row->id),
                $row->email === null ? null : $this->text($row->email),
                $this->text($row->status),
                $this->text($row->provider_id),
                CarbonImmutable::parse($this->text($row->created_at), 'UTC'),
            );
        }

        return $stuck;
    }

    /**
     * @return array<string, array{int, Money, Money}>
     */
    private function localByDay(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->baseQuery($start, $end)
            ->selectRaw('DATE(generations.provider_completed_at) as day')
            ->selectRaw('COUNT(*) as generations')
            ->selectRaw('SUM(ledger_entries.charge_nanos) as charged')
            ->selectRaw('SUM(ledger_entries.provider_cost_nanos) as provider_cost')
            ->groupByRaw('DATE(generations.provider_completed_at)')
            ->get();

        $byDay = [];

        foreach ($rows as $row) {
            $byDay[$this->text($row->day)] = [
                $this->count($row->generations),
                $this->nanos($row->charged),
                $this->nanos($row->provider_cost),
            ];
        }

        return $byDay;
    }

    /**
     * @return list<AdminReportUser>
     */
    private function users(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->baseQuery($start, $end)
            ->leftJoin('users', 'users.id', '=', 'ledger_entries.user_id')
            ->selectRaw('ledger_entries.user_id as user_id')
            ->selectRaw('users.name as name')
            ->selectRaw('users.email as email')
            ->selectRaw('COUNT(*) as generations')
            ->selectRaw('SUM(ledger_entries.charge_nanos) as charged')
            ->selectRaw('SUM(ledger_entries.provider_cost_nanos) as provider_cost')
            ->groupBy('ledger_entries.user_id', 'users.name', 'users.email')
            ->orderByRaw('SUM(ledger_entries.charge_nanos) DESC')
            ->orderBy('ledger_entries.user_id')
            ->get();

        $users = [];

        foreach ($rows as $row) {
            $charged = $this->nanos($row->charged);
            $providerCost = $this->nanos($row->provider_cost);
            $userId = $row->user_id === null ? null : $this->count($row->user_id);

            $users[] = new AdminReportUser(
                $userId,
                $userId === null ? 'Deleted user' : $this->text($row->name),
                $userId === null ? null : $this->text($row->email),
                $this->count($row->generations),
                $charged,
                $providerCost,
                $charged->minus($providerCost),
            );
        }

        return $users;
    }

    /**
     * Ledger rows whose generation completed in [start 00:00, end + 1 day 00:00) UTC.
     */
    private function baseQuery(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return DB::table('ledger_entries')
            ->join('generations', 'generations.id', '=', 'ledger_entries.generation_id')
            ->where('generations.provider_completed_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('generations.provider_completed_at', '<', $end->addDay()->format('Y-m-d H:i:s'));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * MySQL returns SUM of BIGINT as a decimal string; never pass it through a float.
     */
    private function nanos(mixed $value): Money
    {
        if ($value === null) {
            return Money::zero();
        }

        return Money::ofNanos(BigInteger::of($this->text($value))->toInt());
    }

    /**
     * Cached per range for a minute and paid from the shared request budget; a
     * failure is never cached and never fails the report. The cache holds an array:
     * it never unserializes objects, so anything else found there is fetched again.
     */
    private function providerUsage(CarbonImmutable $start, CarbonImmutable $end): ?ProviderUsage
    {
        $key = sprintf('admin-report-usage:%s:%s', $start->format('Y-m-d'), $end->format('Y-m-d'));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            try {
                return ProviderUsage::fromCacheArray($cached);
            } catch (InvalidArgumentException) {
                // Unreadable entry: fetch again and overwrite it below.
            }
        }

        try {
            $usage = $this->limiter->attempt(fn (): ProviderUsage => $this->client->usage($start, $end));
            Cache::put($key, $usage->toCacheArray(), self::USAGE_CACHE_SECONDS);

            return $usage;
        } catch (PixelforgeBusy) {
            Log::warning('Admin report: Pixelforge usage skipped, request budget spent.');
        } catch (PixelforgeException $exception) {
            Log::warning('Admin report: Pixelforge usage unavailable.', ['error_code' => $exception->errorCode]);
        }

        return null;
    }
}
