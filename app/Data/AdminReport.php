<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class AdminReport
{
    /**
     * @param  list<AdminReportDay>  $days  Ascending, one per UTC day from start to end.
     * @param  list<AdminReportUser>  $users  Highest charged first.
     * @param  list<AdminReportStuckGeneration>  $stuck  Oldest first; independent of the date range.
     */
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public bool $providerUsageAvailable,
        public array $days,
        public array $users,
        public AdminReportTotals $totals,
        public array $stuck,
    ) {}
}
