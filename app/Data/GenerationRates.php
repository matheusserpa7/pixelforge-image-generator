<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Money;

final readonly class GenerationRates
{
    public function __construct(
        public Money $inputText,
        public Money $inputImage,
        public Money $output,
        public Money $reasoning,
    ) {}
}
