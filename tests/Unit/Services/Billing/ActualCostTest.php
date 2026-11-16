<?php

declare(strict_types=1);

use App\Data\GenerationRates;
use App\Data\TokenUsage;
use App\Services\Billing\ActualCost;
use App\Support\Money\Money;

it('uses the generation rates even when they differ from the sample table', function () {
    $usage = new TokenUsage(inputText: 10, inputImage: 0, output: 0, reasoning: 0);
    $rates = new GenerationRates(
        Money::perToken('6.00'),
        Money::perToken('10.00'),
        Money::perToken('40.00'),
        Money::perToken('12.50'),
    );

    $cost = ActualCost::calculate($usage, $rates, '0');

    expect($cost->providerCost)->toEqual(Money::perToken('6.00')->times(10));
});

it('applies markup once on the summed provider cost', function () {
    $usage = new TokenUsage(inputText: 1, inputImage: 1, output: 1, reasoning: 0);
    $rates = new GenerationRates(
        Money::ofNanos(1),
        Money::ofNanos(1),
        Money::ofNanos(1),
        Money::zero(),
    );

    $cost = ActualCost::calculate($usage, $rates, '25');

    expect($cost->providerCost->nanos)->toBe(3)
        ->and($cost->charge->nanos)->toBe(4);
});

it('returns zero cost when every token count is zero', function () {
    $usage = new TokenUsage(inputText: 0, inputImage: 0, output: 0, reasoning: 0);
    $rates = new GenerationRates(
        Money::perToken('5.00'),
        Money::perToken('10.00'),
        Money::perToken('40.00'),
        Money::perToken('12.50'),
    );

    $costZeroMarkup = ActualCost::calculate($usage, $rates, '0');
    $costWithMarkup = ActualCost::calculate($usage, $rates, '25');

    expect($costZeroMarkup->providerCost)->toEqual(Money::zero())
        ->and($costZeroMarkup->charge)->toEqual(Money::zero())
        ->and($costWithMarkup->providerCost)->toEqual(Money::zero())
        ->and($costWithMarkup->charge)->toEqual(Money::zero());
});

it('throws when a token count is negative', function () {
    $usage = new TokenUsage(inputText: -1, inputImage: 0, output: 0, reasoning: 0);
    $rates = new GenerationRates(
        Money::perToken('5.00'),
        Money::perToken('10.00'),
        Money::perToken('40.00'),
        Money::perToken('12.50'),
    );

    ActualCost::calculate($usage, $rates, '0');
})->throws(InvalidArgumentException::class);
