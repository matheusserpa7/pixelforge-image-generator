<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Money;

/**
 * One UTC day of the admin report. `pixelforgeCost`, `difference` and `matches`
 * are null only when Pixelforge's usage could not be read.
 */
final readonly class AdminReportDay
{
    public function __construct(
        public string $date,
        public int $generations,
        public Money $charged,
        public Money $providerCost,
        public Money $profit,
        public ?Money $pixelforgeCost,
        public ?Money $difference,
        public ?bool $matches,
    ) {}
}
