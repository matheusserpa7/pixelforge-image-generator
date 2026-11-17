<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Data\PixelforgeGeneration;
use App\Enums\WebhookEventOutcome;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\DecimalJson;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $webhookEventId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(ApplyGenerationSnapshot $apply): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        if (! str_starts_with($event->type, 'generation.')) {
            $this->finish($event, WebhookEventOutcome::NotAGenerationEvent);

            return;
        }

        $snapshot = $this->parseSnapshot($event);

        if ($snapshot === null) {
            Log::error('Pixelforge webhook payload is invalid.', [
                'event_id' => $event->event_id,
                'type' => $event->type,
            ]);
            $this->finish($event, WebhookEventOutcome::InvalidPayload);

            return;
        }

        $generation = $this->findGeneration($snapshot);

        if ($generation === null) {
            Log::info('Pixelforge webhook for an unknown generation.', [
                'event_id' => $event->event_id,
                'provider_id' => $snapshot->id,
            ]);
            $this->finish($event, WebhookEventOutcome::UnknownGeneration);

            return;
        }

        $outcome = WebhookEventOutcome::fromSnapshot($apply->handle($generation->id, $snapshot));

        if ($outcome === WebhookEventOutcome::Incomplete) {
            Log::warning('Pixelforge webhook snapshot is incomplete.', [
                'event_id' => $event->event_id,
                'generation_id' => $generation->id,
            ]);
        }

        $this->finish($event, $outcome, $generation->id);
    }

    private function parseSnapshot(WebhookEvent $event): ?PixelforgeGeneration
    {
        try {
            $decoded = DecimalJson::decode($event->payload);
        } catch (InvalidArgumentException) {
            return null;
        }

        $object = is_array($decoded['data'] ?? null)
            ? ($decoded['data']['object'] ?? null)
            : null;

        if (! is_array($object)) {
            return null;
        }

        try {
            return PixelforgeGeneration::fromDecoded($object);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function findGeneration(PixelforgeGeneration $snapshot): ?Generation
    {
        $localId = $snapshot->metadata['generation_id'] ?? null;

        if (is_string($localId) && $localId !== '') {
            $generation = Generation::query()->find($localId);

            if ($generation !== null) {
                return $generation;
            }
        }

        return Generation::query()->where('provider_id', $snapshot->id)->first();
    }

    private function finish(WebhookEvent $event, WebhookEventOutcome $outcome, ?string $generationId = null): void
    {
        $event->outcome = $outcome;
        $event->generation_id = $generationId;
        $event->processed_at = Carbon::instance(now());
        $event->save();
    }
}
