<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Money;

final readonly class CreditBalance
{
    public function __construct(
        public Money $purchased,
        public Money $charged,
        public Money $held,
    ) {}

    public function balance(): Money
    {
        return $this->purchased->minus($this->charged);
    }

    public function available(): Money
    {
        return $this->balance()->minus($this->held);
    }
}
