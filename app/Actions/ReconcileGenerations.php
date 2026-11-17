<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

final class ReconcileGenerations
{
    public const int RESUBMIT_AFTER_SECONDS = 600;

    public const int BATCH_SIZE = 20;

    public const int WEBHOOK_REDISPATCH_SECONDS = 300;

    public static function reconcileAfterSeconds(): int
    {
        $value = config('services.pixelforge.reconcile_after_seconds', 120);

        if (! is_numeric($value)) {
            return 120;
        }

        $int = (int) $value;

        return $int < 1 ? 120 : $int;
    }

    public static function stuckAfterSeconds(): int
    {
        $value = config('services.pixelforge.stuck_after_seconds', 3600);

        if (! is_numeric($value)) {
            return 3600;
        }

        $int = (int) $value;

        return $int < 1 ? 3600 : $int;
    }

    /**
     * @return array{polled: int, resubmitted: int, stuck: int, webhooks: int}
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

        $counts = [
            'polled' => count($pollIds),
            'resubmitted' => count($resubmitIds),
            'stuck' => $this->reportStuck(),
            'webhooks' => $this->redispatchWebhooks(),
        ];

        if (array_sum($counts) > 0) {
            Log::info('Pixelforge reconciliation dispatched.', $counts);
        }

        return $counts;
    }

    /**
     * Tells operators once about a generation still in flight long after it should be done. It is
     * never failed locally: Pixelforge may still bill it.
     */
    private function reportStuck(): int
    {
        $rows = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->whereNull('stuck_reported_at')
            ->where('created_at', '<=', now()->subSeconds(self::stuckAfterSeconds()))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        foreach ($rows as $row) {
            Log::error('Pixelforge generation stuck.', [
                'generation_id' => $row->id,
                'provider_id' => $row->provider_id,
                'status' => $row->status->value,
                'created_at' => $row->created_at?->toIso8601String(),
            ]);
        }

        // toBase(): an Eloquent update would also touch updated_at and delay the next poll.
        Generation::query()->whereKey($rows->modelKeys())->toBase()->update(['stuck_reported_at' => now()]);

        return $rows->count();
    }

    /**
     * Webhook events whose processing job was lost: stored, never processed, and old enough that
     * the job should have run. At most one redispatch per event every five minutes.
     */
    private function redispatchWebhooks(): int
    {
        $ids = [];

        $events = WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('created_at', '>=', now()->subDay())
            ->where('created_at', '<=', now()->subSeconds(self::WEBHOOK_REDISPATCH_SECONDS))
            ->where('updated_at', '<=', now()->subSeconds(self::WEBHOOK_REDISPATCH_SECONDS))
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id']);

        foreach ($events as $event) {
            $ids[] = $event->id;
        }

        if ($ids === []) {
            return 0;
        }

        WebhookEvent::query()->whereKey($ids)->update(['updated_at' => now()]);

        foreach ($ids as $id) {
            ProcessWebhookEvent::dispatch($id);
        }

        return count($ids);
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
