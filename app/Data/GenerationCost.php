<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Money;

final readonly class GenerationCost
{
    public function __construct(
        public Money $providerCost,
        public Money $charge,
    ) {}
}
