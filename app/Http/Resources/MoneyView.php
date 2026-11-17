<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money\Money;

/**
 * Money as the browser sees it: an exact decimal string and a display string.
 * The front end never does arithmetic on either.
 */
final class MoneyView
{
    /**
     * @return array{amount: string, display: string}
     */
    public static function of(int $nanos): array
    {
        $money = Money::ofNanos($nanos);

        return [
            'amount' => $money->toDecimalString(),
            'display' => $money->format(4),
        ];
    }
}
