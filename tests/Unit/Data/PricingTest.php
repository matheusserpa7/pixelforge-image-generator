<?php

declare(strict_types=1);

use App\Data\Pricing;
use App\Services\Pixelforge\SamplePricing;

it('round-trips pricing through integers and the variance string', function () {
    $pricing = SamplePricing::pricing();
    $cached = $pricing->toCacheArray();

    expect($cached['text_token_variance'])->toBe('0.15')
        ->and($cached['rates']['input_text'])->toBe(5000)
        ->and($cached['rates']['input_image'])->toBe(10000)
        ->and($cached['rates']['output'])->toBe(40000)
        ->and($cached['rates']['reasoning'])->toBe(12500)
        ->and($cached['characters_per_text_token'])->toBe(4)
        ->and($cached['tokens_per_reference_image'])->toBe(765)
        ->and($cached['output']['high']['1536x1024'])->toBe(6450)
        ->and($cached['reasoning']['high'])->toBe(['min' => 1000, 'max' => 8000]);

    $restored = Pricing::fromCacheArray($cached);

    expect($restored->textTokenVariance)->toBe('0.15')
        ->and($restored->rates->output->nanos)->toBe(40000)
        ->and($restored->rates->reasoning->nanos)->toBe(12500)
        ->and($restored->charactersPerTextToken)->toBe(4)
        ->and($restored->tokensPerReferenceImage)->toBe(765)
        ->and($restored->outputTokens['high']['1536x1024'])->toBe(6450)
        ->and($restored->reasoningTokens['medium'])->toBe(['min' => 200, 'max' => 2000]);
});
