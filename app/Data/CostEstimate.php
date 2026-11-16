<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Money;

final readonly class CostEstimate
{
    public function __construct(
        public Money $estimatedProviderCost,
        public Money $maximumProviderCost,
        public Money $estimatedCharge,
        public Money $maximumCharge,
    ) {}
}
