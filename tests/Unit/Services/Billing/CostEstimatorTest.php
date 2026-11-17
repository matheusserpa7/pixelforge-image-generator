<?php

declare(strict_types=1);

use App\Data\GenerationRates;
use App\Data\Pricing;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Billing\CostEstimator;
use App\Services\Pixelforge\SamplePricing;
use App\Support\Money\Money;

it('matches the worked example from the API summary', function () {
    $pricing = SamplePricing::pricing();
    $prompt = str_repeat('a', 400);

    $estimate = CostEstimator::estimate(
        $pricing,
        $prompt,
        ImageSize::Landscape,
        ImageQuality::High,
        2,
        '25',
    );

    expect($estimate->estimatedProviderCost->toDecimalString())->toBe('0.330050000')
        ->and($estimate->maximumProviderCost->toDecimalString())->toBe('0.373875000')
        ->and($estimate->estimatedCharge->toDecimalString())->toBe('0.412562500')
        ->and($estimate->maximumCharge->toDecimalString())->toBe('0.467343750');
});

it('counts four thumb emojis as four code points for one text token', function () {
    $pricing = sampleLikePricing();

    $estimate = CostEstimator::estimate(
        $pricing,
        '👍👍👍👍',
        ImageSize::Landscape,
        ImageQuality::High,
        0,
        '0',
    );

    $textOnly = Money::perToken('5.00')->times(1);

    expect($estimate->estimatedProviderCost)->toEqual($textOnly);
});

it('ceil-divides text tokens when characters exceed an even multiple', function () {
    $pricing = sampleLikePricing(charactersPerTextToken: 10);

    $estimate = CostEstimator::estimate(
        $pricing,
        str_repeat('x', 11),
        ImageSize::Landscape,
        ImageQuality::High,
        0,
        '0',
    );

    expect(Money::perToken('5.00')->times(2)->equals($estimate->estimatedProviderCost))->toBeTrue();
});

it('ceilings a fractional text maximum instead of rounding half up', function () {
    $pricing = sampleLikePricing();

    $estimate = CostEstimator::estimate(
        $pricing,
        'abcd',
        ImageSize::Landscape,
        ImageQuality::High,
        0,
        '0',
    );

    $textRate = Money::perToken('5.00');

    expect($estimate->estimatedProviderCost)->toEqual($textRate->times(1))
        ->and($estimate->maximumProviderCost)->toEqual($textRate->times(2));
});

it('keeps the text maximum equal to the estimate when variance is zero', function () {
    $pricing = sampleLikePricing(textTokenVariance: '0');

    $estimate = CostEstimator::estimate(
        $pricing,
        str_repeat('a', 8),
        ImageSize::Landscape,
        ImageQuality::High,
        0,
        '0',
    );

    $textTokens = 2;
    $textCost = Money::perToken('5.00')->times($textTokens);

    expect($estimate->estimatedProviderCost)->toEqual($textCost)
        ->and($estimate->maximumProviderCost)->toEqual($textCost);
});

it('estimates reasoning tokens as the half-up midpoint', function () {
    $pricing = sampleLikePricing(
        reasoningTokens: [
            'high' => ['min' => 1, 'max' => 2],
        ],
    );

    $estimate = CostEstimator::estimate(
        $pricing,
        '',
        ImageSize::Landscape,
        ImageQuality::High,
        0,
        '0',
    );

    $reasoningCost = Money::perToken('12.50')->times(2);

    expect($estimate->estimatedProviderCost)->toEqual($reasoningCost);
});

it('leaves charge equal to provider cost when markup is zero', function () {
    $pricing = SamplePricing::pricing();
    $prompt = str_repeat('a', 400);

    $estimate = CostEstimator::estimate(
        $pricing,
        $prompt,
        ImageSize::Landscape,
        ImageQuality::High,
        0,
        '0',
    );

    expect($estimate->estimatedCharge)->toEqual($estimate->estimatedProviderCost)
        ->and($estimate->maximumCharge)->toEqual($estimate->maximumProviderCost);
});

it('throws when the output table has no cell for the requested size', function () {
    $pricing = sampleLikePricing(
        outputTokens: [
            'high' => [
                ImageSize::Square->value => 100,
            ],
        ],
    );

    CostEstimator::estimate(
        $pricing,
        'x',
        ImageSize::Landscape,
        ImageQuality::High,
        0,
        '0',
    );
})->throws(InvalidArgumentException::class);

it('throws when the image token count does not fit in a PHP int', function () {
    $pricing = new Pricing(
        new GenerationRates(
            Money::zero(),
            Money::zero(),
            Money::zero(),
            Money::zero(),
        ),
        charactersPerTextToken: 4,
        textTokenVariance: '0',
        tokensPerReferenceImage: PHP_INT_MAX,
        outputTokens: [
            'high' => [ImageSize::Landscape->value => 0],
        ],
        reasoningTokens: [
            'high' => ['min' => 0, 'max' => 0],
        ],
    );

    CostEstimator::estimate(
        $pricing,
        '',
        ImageSize::Landscape,
        ImageQuality::High,
        2,
        '0',
    );
})->throws(InvalidArgumentException::class);

it('throws on a negative reference image count', function () {
    $pricing = SamplePricing::pricing();

    CostEstimator::estimate(
        $pricing,
        'x',
        ImageSize::Landscape,
        ImageQuality::High,
        -1,
        '0',
    );
})->throws(InvalidArgumentException::class);

it('applies markup once on the summed provider cost', function () {
    $rates = new GenerationRates(
        Money::ofNanos(1),
        Money::ofNanos(1),
        Money::ofNanos(1),
        Money::zero(),
    );

    $pricing = new Pricing(
        $rates,
        charactersPerTextToken: 1,
        textTokenVariance: '0',
        tokensPerReferenceImage: 1,
        outputTokens: [
            'high' => [ImageSize::Landscape->value => 1],
        ],
        reasoningTokens: [
            'high' => ['min' => 0, 'max' => 0],
        ],
    );

    $estimate = CostEstimator::estimate(
        $pricing,
        'x',
        ImageSize::Landscape,
        ImageQuality::High,
        1,
        '25',
    );

    expect($estimate->estimatedProviderCost->nanos)->toBe(3)
        ->and($estimate->estimatedCharge->nanos)->toBe(4);
});

/**
 * @param  array<string, array<string, int>>  $outputTokens
 * @param  array<string, array{min: int, max: int}>  $reasoningTokens
 */
function sampleLikePricing(
    int $charactersPerTextToken = 4,
    string $textTokenVariance = '0.15',
    array $outputTokens = [],
    array $reasoningTokens = [],
): Pricing {
    $defaultsOutput = [
        'high' => [
            ImageSize::Landscape->value => 0,
        ],
    ];

    $defaultsReasoning = [
        'high' => ['min' => 0, 'max' => 0],
    ];

    return new Pricing(
        SamplePricing::pricing()->rates,
        $charactersPerTextToken,
        $textTokenVariance,
        765,
        $outputTokens === [] ? $defaultsOutput : $outputTokens,
        $reasoningTokens === [] ? $defaultsReasoning : $reasoningTokens,
    );
}

it('returns the maximum token counts of the worked example', function () {
    $estimate = CostEstimator::estimate(
        SamplePricing::pricing(),
        str_repeat('a', 400),
        ImageSize::Landscape,
        ImageQuality::High,
        2,
        '25',
    );

    expect($estimate->maximumTokens->toStoredArray())->toBe([
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
    ]);
});
