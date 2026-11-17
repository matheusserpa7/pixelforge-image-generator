<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Actions\FailGeneration;
use App\Enums\SnapshotOutcome;
use App\Models\Generation;
use App\Services\Pixelforge\GenerationFailureMessage;
use App\Services\Pixelforge\PixelforgeBusy;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgeRequestLimiter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class PollGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function handle(
        PixelforgeClient $client,
        PixelforgeRequestLimiter $limiter,
        ApplyGenerationSnapshot $apply,
        FailGeneration $fail,
    ): void {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status->isFinal() || $generation->provider_id === null) {
            return;
        }

        $providerId = $generation->provider_id;

        try {
            $snapshot = $limiter->run(fn () => $client->retrieve($providerId));
        } catch (PixelforgeBusy) {
            return;
        } catch (PixelforgeException $exception) {
            if ($exception->errorCode === 'not_found') {
                $fail->handle($generation->id, 'not_found', GenerationFailureMessage::forCode('not_found'));

                return;
            }

            Log::warning('Pixelforge poll failed.', [
                'generation_id' => $generation->id,
                'error_code' => $exception->errorCode,
            ]);

            return;
        }

        $outcome = $apply->handle($generation->id, $snapshot);

        if ($outcome === SnapshotOutcome::Incomplete) {
            Log::warning('Pixelforge poll returned an incomplete snapshot.', [
                'generation_id' => $generation->id,
            ]);
        }
    }
}
