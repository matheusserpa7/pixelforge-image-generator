<?php

declare(strict_types=1);

use App\Actions\BuildAdminReport;
use App\Data\AdminReport;
use App\Data\ProviderUsage;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgeRequestLimiter;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear(PixelforgeRequestLimiter::RATE_KEY);
    $this->client = new FakePixelforgeClient;
    $this->app->instance(PixelforgeClient::class, $this->client);
});

function reportDay(string $date): CarbonImmutable
{
    return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
}

function buildReport(string $start, string $end): AdminReport
{
    return app(BuildAdminReport::class)->handle(reportDay($start), reportDay($end));
}

/**
 * A succeeded generation with its ledger row, completed at the given UTC moment.
 */
function chargedGeneration(?User $user, string $completedAt, int $providerNanos, int $chargeNanos, ?string $ledgerAt = null): Generation
{
    $generation = Generation::factory()->create([
        'user_id' => $user?->id,
        'status' => GenerationStatus::Succeeded,
        'provider_completed_at' => $completedAt,
    ]);

    DB::table('ledger_entries')->insert([
        'generation_id' => $generation->id,
        'user_id' => $user?->id,
        'provider_cost_nanos' => $providerNanos,
        'charge_nanos' => $chargeNanos,
        'markup_percent' => '25',
        'created_at' => $ledgerAt ?? now()->toDateTimeString(),
    ]);

    return $generation;
}

it('sums only ledger rows whose generation completed in range', function () {
    $user = User::factory()->create();
    chargedGeneration($user, '2026-03-10 12:00:00', 1_000, 1_250);
    chargedGeneration($user, '2026-03-11 08:00:00', 2_000, 2_500);
    chargedGeneration($user, '2026-03-12 08:00:00', 9_000, 9_999); // outside the range
    Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Failed,
        'provider_completed_at' => '2026-03-10 13:00:00',
    ]);
    Generation::factory()->create(['user_id' => $user->id, 'status' => GenerationStatus::Processing]);

    $report = buildReport('2026-03-10', '2026-03-11');

    expect($report->totals->generations)->toBe(2)
        ->and($report->totals->charged->nanos)->toBe(3_750)
        ->and($report->totals->providerCost->nanos)->toBe(3_000)
        ->and($report->totals->profit->nanos)->toBe(750)
        ->and($report->users)->toHaveCount(1);
});

it('puts 23:59:59 on its day and midnight on the next', function () {
    $user = User::factory()->create();
    chargedGeneration($user, '2026-03-10 23:59:59', 1, 1);
    chargedGeneration($user, '2026-03-11 00:00:00', 10, 10);

    $report = buildReport('2026-03-10', '2026-03-11');

    expect($report->days[0]->generations)->toBe(1)
        ->and($report->days[0]->providerCost->nanos)->toBe(1)
        ->and($report->days[1]->generations)->toBe(1)
        ->and($report->days[1]->providerCost->nanos)->toBe(10);
});

it('counts a ledger row written days later on the completion day', function () {
    chargedGeneration(User::factory()->create(), '2026-03-10 10:00:00', 500, 625, ledgerAt: '2026-03-14 09:00:00');

    expect(buildReport('2026-03-10', '2026-03-10')->totals->generations)->toBe(1)
        ->and(buildReport('2026-03-14', '2026-03-14')->totals->generations)->toBe(0);
});

it('fills days without charges with zeros, ascending', function () {
    chargedGeneration(User::factory()->create(), '2026-03-12 10:00:00', 4_000_000_000, 5_000_000_000);

    $report = buildReport('2026-03-10', '2026-03-13');

    expect(array_map(fn ($d) => $d->date, $report->days))->toBe(['2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'])
        ->and($report->days[0]->generations)->toBe(0)
        ->and($report->days[0]->charged->nanos)->toBe(0)
        ->and($report->days[2]->charged->nanos)->toBe(5_000_000_000);
});

it('sums values above 2^31 exactly and ranks users by charged', function () {
    $small = User::factory()->create(['name' => 'Small']);
    $big = User::factory()->create(['name' => 'Big']);
    chargedGeneration($small, '2026-03-10 10:00:00', 1, 2);
    chargedGeneration($big, '2026-03-10 10:00:00', 3_000_000_000, 3_750_000_001);
    chargedGeneration($big, '2026-03-10 11:00:00', 3_000_000_000, 3_750_000_001);

    $report = buildReport('2026-03-10', '2026-03-10');

    expect($report->users[0]->name)->toBe('Big')
        ->and($report->users[0]->generations)->toBe(2)
        ->and($report->users[0]->charged->nanos)->toBe(7_500_000_002)
        ->and($report->users[0]->profit->nanos)->toBe(1_500_000_002)
        ->and($report->users[1]->name)->toBe('Small')
        ->and($report->totals->charged->nanos)->toBe(7_500_000_004);
});

it('groups the charges of deleted users as "Deleted user"', function () {
    $user = User::factory()->create();
    chargedGeneration($user, '2026-03-10 10:00:00', 100, 125);
    chargedGeneration($user, '2026-03-10 11:00:00', 100, 125);
    $user->delete();

    $report = buildReport('2026-03-10', '2026-03-10');

    expect($report->users)->toHaveCount(1)
        ->and($report->users[0]->userId)->toBeNull()
        ->and($report->users[0]->name)->toBe('Deleted user')
        ->and($report->users[0]->email)->toBeNull()
        ->and($report->users[0]->charged->nanos)->toBe(250)
        ->and($report->totals->charged->nanos)->toBe(250);
});

it('marks matching days, signed differences and days absent from Pixelforge', function () {
    $user = User::factory()->create();
    chargedGeneration($user, '2026-03-10 10:00:00', 1_000, 1_250); // matches
    chargedGeneration($user, '2026-03-11 10:00:00', 2_000, 2_500); // ours is higher
    chargedGeneration($user, '2026-03-12 10:00:00', 3_000, 3_750); // absent at Pixelforge
    $this->client->usage = new ProviderUsage(reportDay('2026-03-10'), reportDay('2026-03-13'), [
        '2026-03-10' => Money::ofNanos(1_000),
        '2026-03-11' => Money::ofNanos(1_500),
        '2026-03-13' => Money::ofNanos(7), // we know nothing about it
    ], Money::ofNanos(2_507));

    $report = buildReport('2026-03-10', '2026-03-13');

    expect($report->providerUsageAvailable)->toBeTrue()
        ->and($report->days[0]->matches)->toBeTrue()
        ->and($report->days[0]->difference?->nanos)->toBe(0)
        ->and($report->days[1]->matches)->toBeFalse()
        ->and($report->days[1]->difference?->nanos)->toBe(500)
        ->and($report->days[2]->pixelforgeCost?->nanos)->toBe(0)
        ->and($report->days[2]->difference?->nanos)->toBe(3_000)
        ->and($report->days[3]->difference?->nanos)->toBe(-7)
        ->and($report->totals->pixelforgeCost?->nanos)->toBe(2_507)
        ->and($report->totals->matches)->toBeFalse();
});

it('reports a full match when every day matches', function () {
    chargedGeneration(User::factory()->create(), '2026-03-10 10:00:00', 1_000, 1_250);
    $this->client->usage = new ProviderUsage(reportDay('2026-03-10'), reportDay('2026-03-11'), [
        '2026-03-10' => Money::ofNanos(1_000),
    ], Money::ofNanos(1_000));

    expect(buildReport('2026-03-10', '2026-03-11')->totals->matches)->toBeTrue();
});

it('keeps local figures when the usage call fails', function () {
    chargedGeneration(User::factory()->create(), '2026-03-10 10:00:00', 1_000, 1_250);
    $this->client->usageFailure = PixelforgeException::fromProvider(503, 'overloaded', 'Busy.', null, 2);

    $report = buildReport('2026-03-10', '2026-03-10');

    expect($report->providerUsageAvailable)->toBeFalse()
        ->and($report->totals->charged->nanos)->toBe(1_250)
        ->and($report->totals->pixelforgeCost)->toBeNull()
        ->and($report->totals->matches)->toBeNull()
        ->and($report->days[0]->pixelforgeCost)->toBeNull()
        ->and($report->days[0]->matches)->toBeNull();

    // A failure is not cached: the next build tries again.
    $this->client->usageFailure = null;

    expect(buildReport('2026-03-10', '2026-03-10')->providerUsageAvailable)->toBeTrue()
        ->and($this->client->usageCalls)->toHaveCount(2);
});

it('skips the usage call when the minute budget is spent', function () {
    chargedGeneration(User::factory()->create(), '2026-03-10 10:00:00', 1_000, 1_250);
    config(['services.pixelforge.requests_per_minute' => 2]);
    RateLimiter::hit(PixelforgeRequestLimiter::RATE_KEY, 60);
    RateLimiter::hit(PixelforgeRequestLimiter::RATE_KEY, 60);

    $report = buildReport('2026-03-10', '2026-03-10');

    expect($report->providerUsageAvailable)->toBeFalse()
        ->and($report->totals->charged->nanos)->toBe(1_250)
        ->and($this->client->usageCalls)->toBeEmpty();
});

it('calls usage once for two builds of the same range within the cache window', function () {
    buildReport('2026-03-10', '2026-03-12');
    buildReport('2026-03-10', '2026-03-12');

    expect($this->client->usageCalls)->toHaveCount(1)
        ->and(RateLimiter::attempts(PixelforgeRequestLimiter::RATE_KEY))->toBe(1);

    buildReport('2026-03-10', '2026-03-13');

    expect($this->client->usageCalls)->toHaveCount(2);
});

it('lists in-flight generations with a provider id older than an hour, including a deleted user', function () {
    $user = User::factory()->create(['email' => 'ada@example.test']);
    $old = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Processing,
        'provider_id' => 'gen_old',
    ]);
    $orphan = Generation::factory()->create([
        'user_id' => null,
        'status' => GenerationStatus::Queued,
        'provider_id' => 'gen_orphan',
    ]);
    $recent = Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_new']);
    $final = Generation::factory()->create(['status' => GenerationStatus::Succeeded, 'provider_id' => 'gen_done']);
    $unsubmitted = Generation::factory()->create(['status' => GenerationStatus::Queued, 'provider_id' => null]);
    Generation::query()->whereKey([$old->id, $final->id, $unsubmitted->id])
        ->update(['created_at' => now()->subHours(2)]);
    Generation::query()->whereKey($orphan->id)->update(['created_at' => now()->subHours(3)]);

    $stuck = buildReport('2026-03-10', '2026-03-10')->stuck;

    expect($stuck)->toHaveCount(2)
        ->and($stuck[0]->id)->toBe($orphan->id)
        ->and($stuck[0]->userEmail)->toBeNull()
        ->and($stuck[0]->providerId)->toBe('gen_orphan')
        ->and($stuck[1]->id)->toBe($old->id)
        ->and($stuck[1]->userEmail)->toBe('ada@example.test')
        ->and($stuck[1]->status)->toBe('processing')
        ->and(collect($stuck)->pluck('id')->contains($recent->id))->toBeFalse();
});
