<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Queue metrics for the Horizon dashboard graphs.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Safety net for lost webhooks and lost submit jobs (docs/adr/0011). The command runs in a child
// process whose output Laravel discards by default, and our logs go to stdout (docs/adr/0019), so
// its output goes to the stdout of the scheduler container's main process instead. Without this,
// "Pixelforge generation stuck." errors never reach the logs.
Schedule::command('pixelforge:reconcile')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo('/proc/1/fd/1');
