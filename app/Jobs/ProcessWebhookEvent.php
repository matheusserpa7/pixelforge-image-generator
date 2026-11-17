<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        // Processing arrives with task T2.
    }
}
