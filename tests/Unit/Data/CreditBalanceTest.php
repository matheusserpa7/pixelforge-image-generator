<?php

declare(strict_types=1);

use App\Data\CreditBalance;
use App\Support\Money\Money;

it('derives balance and available from purchases, charges and holds', function () {
    $balance = new CreditBalance(
        Money::fromDecimal('5'),
        Money::fromDecimal('1.25'),
        Money::fromDecimal('0.5'),
    );

    expect($balance->balance()->nanos)->toBe(3_750_000_000)
        ->and($balance->available()->nanos)->toBe(3_250_000_000);
});

it('goes negative instead of clamping', function () {
    $balance = new CreditBalance(Money::fromDecimal('1'), Money::fromDecimal('1.000000001'), Money::fromDecimal('2'));

    expect($balance->balance()->nanos)->toBe(-1)
        ->and($balance->available()->nanos)->toBe(-2_000_000_001);
});
