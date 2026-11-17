<?php

declare(strict_types=1);

use App\Data\PixelforgeGeneration;

/**
 * @param  array<string, int>  $usage
 */
function succeededWithUsage(array $usage): PixelforgeGeneration
{
    return PixelforgeGeneration::fromDecoded([
        'id' => 'gen_1',
        'object' => 'generation',
        'status' => 'succeeded',
        'prompt' => 'A lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => array_map(strval(...), $usage),
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => null,
        'created_at' => '2026-11-16T12:00:00Z',
        'started_at' => null,
        'completed_at' => null,
    ]);
}

$tokens = ['input_text_tokens' => 100, 'input_image_tokens' => 765, 'output_tokens' => 1000, 'reasoning_tokens' => 200];

it('accepts a total_tokens equal to the sum of the four priced types', function () use ($tokens) {
    $generation = succeededWithUsage([...$tokens, 'total_tokens' => 2065]);

    expect($generation->usage?->output)->toBe(1000);
});

it('refuses a total_tokens that differs from the sum', function () use ($tokens) {
    succeededWithUsage([...$tokens, 'total_tokens' => 2066]);
})->throws(InvalidArgumentException::class, 'usage.total_tokens does not match the priced token types.');

it('accepts a usage without total_tokens', function () use ($tokens) {
    expect(succeededWithUsage($tokens)->usage?->inputText)->toBe(100);
});
