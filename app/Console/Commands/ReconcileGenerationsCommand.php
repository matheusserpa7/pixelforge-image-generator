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

        if ($counts['polled'] > 0 || $counts['resubmitted'] > 0) {
            $this->line("Polled {$counts['polled']}, resubmitted {$counts['resubmitted']}.");
        }

        return self::SUCCESS;
    }
}
