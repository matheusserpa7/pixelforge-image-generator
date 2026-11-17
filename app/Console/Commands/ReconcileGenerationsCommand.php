<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReconcileGenerations;
use Illuminate\Console\Command;

final class ReconcileGenerationsCommand extends Command
{
    protected $signature = 'pixelforge:reconcile';

    protected $description = 'Poll stale generations and resubmit lost submissions.';

    public function handle(ReconcileGenerations $reconcile): int
    {
        $counts = $reconcile->handle();

        if (array_sum($counts) > 0) {
            $this->line("Polled {$counts['polled']}, resubmitted {$counts['resubmitted']}, stuck {$counts['stuck']}, webhooks {$counts['webhooks']}.");
        }

        return self::SUCCESS;
    }
}
