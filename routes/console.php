<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Queue metrics for the Horizon dashboard graphs.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
