<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

final class AlwaysFailingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Pixelforge exploded');
    }
}

it('logs failed jobs at error level', function () {
    Log::spy();

    try {
        AlwaysFailingJob::dispatch();
    } catch (RuntimeException) {
        // The sync queue rethrows after marking the job as failed.
    }

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Queued job failed.'
            && $context['job'] === AlwaysFailingJob::class
            && $context['error'] === 'Pixelforge exploded')
        ->once();
});
