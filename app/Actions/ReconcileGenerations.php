<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

final class ReconcileGenerations
{
    public const int RESUBMIT_AFTER_SECONDS = 600;

    public const int BATCH_SIZE = 20;

    public static function reconcileAfterSeconds(): int
    {
        $value = config('services.pixelforge.reconcile_after_seconds', 120);

        if (! is_numeric($value)) {
            return 120;
        }

        $int = (int) $value;

        return $int < 1 ? 120 : $int;
    }

    /**
     * @return array{polled: int, resubmitted: int}
     */
    public function handle(): array
    {
        $pollIds = $this->ids(
            Generation::query()
                ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
                ->whereNotNull('provider_id')
                ->where('updated_at', '<=', now()->subSeconds(self::reconcileAfterSeconds())),
        );

        $this->touch($pollIds);

        foreach ($pollIds as $id) {
            PollGeneration::dispatch($id);
        }

        $resubmitIds = $this->ids(
            Generation::query()
                ->where('status', GenerationStatus::Queued)
                ->whereNull('provider_id')
                ->where('updated_at', '<=', now()->subSeconds(self::RESUBMIT_AFTER_SECONDS)),
        );

        $this->touch($resubmitIds);

        foreach ($resubmitIds as $id) {
            SubmitGeneration::dispatch($id);
        }

        $counts = ['polled' => count($pollIds), 'resubmitted' => count($resubmitIds)];

        if ($counts['polled'] > 0 || $counts['resubmitted'] > 0) {
            Log::info('Pixelforge reconciliation dispatched.', $counts);
        }

        return $counts;
    }

    /**
     * @param  Builder<Generation>  $query
     * @return list<string>
     */
    private function ids(Builder $query): array
    {
        $ids = [];

        foreach ($query->orderBy('updated_at')->orderBy('id')->limit(self::BATCH_SIZE)->get(['id']) as $row) {
            $ids[] = $row->id;
        }

        return $ids;
    }

    /**
     * @param  list<string>  $ids
     */
    private function touch(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Generation::query()->whereKey($ids)->update(['updated_at' => now()]);
    }
}
