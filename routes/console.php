<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Queue metrics for the Horizon dashboard graphs.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Safety net for lost webhooks and lost submit jobs (docs/adr/0011).
Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping();
