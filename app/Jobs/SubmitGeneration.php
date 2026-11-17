<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Actions\FailGeneration;
use App\Data\CreateGenerationRequest;
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

        $request = new CreateGenerationRequest(
            prompt: $generation->prompt,
            size: $generation->size,
            quality: $generation->quality,
            referenceImages: $generation->reference_images ?? [],
            metadata: ['generation_id' => $generation->id],
            idempotencyKey: $generation->id,
        );

        try {
            $created = $limiter->run(function () use ($client, $generation, $request) {
                $generation->increment('submit_attempts');
                $generation->refresh();

                return $client->create($request);
            });
        } catch (PixelforgeBusy $busy) {
            $this->release($busy->retryAfterSeconds);

            return;
        } catch (PixelforgeException $exception) {
            $generation->refresh();

            if ($exception->retryable && $generation->submit_attempts < PixelforgeRequestLimiter::maxSubmitAttempts()) {
                Log::warning('Pixelforge submit failed; will retry.', [
                    'generation_id' => $generation->id,
                    'error_code' => $exception->errorCode,
                ]);

                $this->release(SubmitBackoff::seconds($exception->retryAfterSeconds, $generation->submit_attempts));

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

        if ($outcome !== SnapshotOutcome::Incomplete) {
            return;
        }

        $generation->refresh();

        if ($generation->submit_attempts >= PixelforgeRequestLimiter::maxSubmitAttempts()) {
            $failGeneration->handle(
                $generation->id,
                'invalid_response',
                GenerationFailureMessage::forCode('invalid_response'),
            );

            return;
        }

        $this->release(SubmitBackoff::seconds(null, $generation->submit_attempts));
    }
}
