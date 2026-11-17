<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Support\Money\Money;
use RuntimeException;

final class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly Money $required, public readonly Money $available)
    {
        parent::__construct(
            "Not enough credits. This generation can cost up to {$required->format()} and you have {$available->format()} available. Buy credits to continue.",
        );
    }
}
