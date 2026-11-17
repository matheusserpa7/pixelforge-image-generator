<?php

declare(strict_types=1);

/**
 * A valid Pixelforge generation object, merged with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGenerationObject(array $overrides = []): array
{
    return array_merge([
        'id' => 'gen_test_1',
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
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
    ], $overrides);
}

/**
 * The JSON body of a webhook event carrying a generation object.
 *
 * @param  array<string, mixed>  $object
 */
function pixelforgeEvent(string $type, array $object, string $eventId = 'evt_test_1'): string
{
    return json_encode([
        'id' => $eventId,
        'type' => $type,
        'data' => ['object' => $object],
    ], JSON_THROW_ON_ERROR);
}

/**
 * The `Pixelforge-Signature` header value for a body.
 */
function signPixelforge(string $body, ?int $timestamp = null, string $secret = 'whsec_test'): string
{
    $timestamp ??= time();

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}
