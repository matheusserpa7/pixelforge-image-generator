<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Money;

final readonly class AdminReportTotals
{
    public function __construct(
        public int $generations,
        public Money $charged,
        public Money $providerCost,
        public Money $profit,
        public ?Money $pixelforgeCost,
        public ?bool $matches,
    ) {}
}
