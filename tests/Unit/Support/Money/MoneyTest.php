<?php

declare(strict_types=1);

use App\Support\Money\Money;

describe('creating money', function () {
    it('parses exact USD decimal strings into nanos', function (string $usd, int $nanos) {
        expect(Money::fromDecimal($usd)->nanos)->toBe($nanos);
    })->with([
        'Pixelforge usage cost' => ['0.169080000', 169_080_000],
        'one dollar' => ['1', 1_000_000_000],
        'one nano' => ['0.000000001', 1],
        'zero' => ['0.000000000', 0],
    ]);

    it('rejects amounts more precise than a nano', function () {
        Money::fromDecimal('0.0000000001');
    })->throws(InvalidArgumentException::class);

    it('rejects strings that are not numbers', function () {
        Money::fromDecimal('abc');
    })->throws(InvalidArgumentException::class);

    it('converts a rate per 1M tokens into an exact price per token', function (string $rate, int $nanosPerToken) {
        expect(Money::perToken($rate)->nanos)->toBe($nanosPerToken);
    })->with([
        'input text' => ['5.00', 5_000],
        'input image' => ['10.00', 10_000],
        'output' => ['40.00', 40_000],
        'reasoning' => ['12.50', 12_500],
        'smallest exact rate' => ['0.001', 1],
    ]);

    it('rejects a rate that would need a fraction of a nano per token', function () {
        Money::perToken('0.0005');
    })->throws(InvalidArgumentException::class);
});

describe('arithmetic', function () {
    it('adds amounts', function () {
        $sum = Money::ofNanos(1_500)->plus(Money::ofNanos(2_500));

        expect($sum->nanos)->toBe(4_000);
    });

    it('multiplies a per-token price by a token count', function () {
        $cost = Money::perToken('40.00')->times(6_450);

        expect($cost->toDecimalString())->toBe('0.258000000');
    });

    it('reproduces the maximum-cost example from the Pixelforge docs exactly', function () {
        $total = Money::perToken('5.00')->times(115)
            ->plus(Money::perToken('10.00')->times(1_530))
            ->plus(Money::perToken('40.00')->times(6_450))
            ->plus(Money::perToken('12.50')->times(8_000));

        expect($total->toDecimalString())->toBe('0.373875000');
    });

    it('fails instead of overflowing', function () {
        Money::ofNanos(PHP_INT_MAX)->plus(Money::ofNanos(1));
    })->throws(InvalidArgumentException::class);
});

describe('markup', function () {
    it('adds the markup percentage', function () {
        $charge = Money::fromDecimal('0.373875000')->withMarkup('25');

        expect($charge->toDecimalString())->toBe('0.467343750');
    });

    it('rounds half up to the nano', function (int $providerNanos, int $chargedNanos) {
        expect(Money::ofNanos($providerNanos)->withMarkup('25')->nanos)->toBe($chargedNanos);
    })->with([
        '1.25 rounds down' => [1, 1],
        '2.5 rounds up' => [2, 3],
        '3.75 rounds up' => [3, 4],
        'exact stays exact' => [4, 5],
    ]);

    it('accepts decimal percentages', function () {
        expect(Money::ofNanos(1_000)->withMarkup('12.5')->nanos)->toBe(1_125);
    });

    it('charges nothing extra on a zero cost', function () {
        expect(Money::zero()->withMarkup('25')->nanos)->toBe(0);
    });
});

describe('formatting', function () {
    it('prints the exact amount with nine decimals', function () {
        expect(Money::ofNanos(46_125_000)->toDecimalString())->toBe('0.046125000')
            ->and(Money::zero()->toDecimalString())->toBe('0.000000000');
    });

    it('formats for people, rounding half up', function (int $nanos, int $decimals, string $expected) {
        expect(Money::ofNanos($nanos)->format($decimals))->toBe($expected);
    })->with([
        'default four decimals' => [46_125_000, 4, '$0.0461'],
        'half up' => [50_000, 4, '$0.0001'],
        'two decimals' => [1_234_560_000, 2, '$1.23'],
        'negative' => [-46_125_000, 4, '-$0.0461'],
        'zero' => [0, 4, '$0.0000'],
    ]);

    it('compares by value', function () {
        expect(Money::ofNanos(5)->equals(Money::fromDecimal('0.000000005')))->toBeTrue()
            ->and(Money::ofNanos(5)->equals(Money::ofNanos(6)))->toBeFalse();
    });
});
