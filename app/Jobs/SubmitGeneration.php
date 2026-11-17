<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Actions\FailGeneration;
use App\Data\CreateGenerationRequest;
use App\Enums\GenerationStatus;
use App\Enums\SnapshotOutcome;
use App\Models\Generation;
use App\Services\Pixelforge\GenerationFailureMessage;
use App\Services\Pixelforge\PixelforgeBusy;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgeRequestLimiter;
use App\Services\Pixelforge\SubmitBackoff;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 100_000;

    public int $timeout = 60;

    public function __construct(public string $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->generationId))
                ->expireAfter(60)
                ->releaseAfter(5),
        ];
    }

    public function failed(Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation !== null && $generation->provider_id === null && $generation->submit_unconfirmed) {
            Log::warning('Pixelforge submit job failed while unconfirmed.', [
                'generation_id' => $generation->id,
            ]);

            return;
        }

        app(FailGeneration::class)->handle(
            $this->generationId,
            'submit_failed',
            GenerationFailureMessage::forCode('submit_failed'),
        );
    }

    public function handle(
        PixelforgeClient $client,
        PixelforgeRequestLimiter $limiter,
        ApplyGenerationSnapshot $applyGenerationSnapshot,
        FailGeneration $failGeneration,
    ): void {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status->isFinal() || $generation->provider_id !== null) {
            return;
        }

        if (
            $generation->submit_unconfirmed
            && $generation->created_at !== null
            && $generation->created_at->lte(now()->subSeconds(PixelforgeRequestLimiter::submitGiveUpSeconds()))
        ) {
            Log::warning('Pixelforge submit gave up while unconfirmed.', [
                'generation_id' => $generation->id,
            ]);

            $failGeneration->handle(
                $generation->id,
                'submit_failed',
                GenerationFailureMessage::forCode('submit_failed'),
            );

            return;
        }

        $inFlight = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->count();

        if ($inFlight >= PixelforgeRequestLimiter::concurrencyLimit()) {
            $this->touch();
            $this->release(PixelforgeRequestLimiter::concurrencyRetrySeconds());

            return;
        }

        $request = new CreateGenerationRequest(
            prompt: $generation->prompt,
            size: $generation->size,
            quality: $generation->quality,
            referenceImages: $generation->reference_images ?? [],
            metadata: ['generation_id' => $generation->id],
            idempotencyKey: $generation->id,
        );

        $wasUnconfirmed = $generation->submit_unconfirmed;

        try {
            $created = $limiter->run(function () use ($client, $generation, $request) {
                $generation->submit_attempts++;
                $generation->submit_unconfirmed = true;
                $generation->save();

                return $client->create($request);
            });
        } catch (PixelforgeBusy $busy) {
            $this->touch();
            $this->release($busy->retryAfterSeconds);

            return;
        } catch (PixelforgeException $exception) {
            $generation->refresh();

            $generation->submit_unconfirmed = match (true) {
                $exception->provesKeyUnknown() => false,
                $exception->createdNothing() => $wasUnconfirmed,
                default => true,
            };
            $generation->save();

            if ($exception->status === 429 && in_array($exception->errorCode, ['concurrency_limit_exceeded', 'rate_limit_exceeded'], true)) {
                if ($generation->submit_attempts > 0) {
                    $generation->decrement('submit_attempts');
                }

                $this->touch();
                $this->release(max($exception->retryAfterSeconds ?? 0, PixelforgeRequestLimiter::concurrencyRetrySeconds()));

                return;
            }

            if ($exception->retryable && $generation->submit_attempts < PixelforgeRequestLimiter::maxSubmitAttempts()) {
                Log::warning('Pixelforge submit failed; will retry.', [
                    'generation_id' => $generation->id,
                    'error_code' => $exception->errorCode,
                ]);

                $this->touch();
                $this->release(SubmitBackoff::seconds($exception->retryAfterSeconds, $generation->submit_attempts));

                return;
            }

            if ($generation->submit_unconfirmed) {
                Log::warning('Pixelforge submit unconfirmed; waiting for the provider.', [
                    'generation_id' => $generation->id,
                    'error_code' => $exception->errorCode,
                ]);

                return;
            }

            $failGeneration->handle(
                $generation->id,
                $exception->errorCode,
                GenerationFailureMessage::forCode($exception->errorCode),
            );

            return;
        }

        $outcome = $applyGenerationSnapshot->handle($generation->id, $created->generation);

        if ($outcome === SnapshotOutcome::Incomplete) {
            Log::warning('Pixelforge submit returned success without usage.', [
                'generation_id' => $generation->id,
            ]);
        }
    }

    /**
     * Mark the row as recently handled so the reconciler does not resubmit a job that is only waiting.
     */
    private function touch(): void
    {
        Generation::query()->whereKey($this->generationId)->update(['updated_at' => now()]);
    }
}
