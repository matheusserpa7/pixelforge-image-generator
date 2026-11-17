<?php

declare(strict_types=1);

use App\Services\Pixelforge\PixelforgeException;

$error = static fn (int $status, string $code): PixelforgeException => PixelforgeException::fromProvider($status, $code, 'Message.', null, null);

it('proves the key unknown only for the four deterministic cases', function () use ($error) {
    expect($error(429, 'concurrency_limit_exceeded')->provesKeyUnknown())->toBeTrue()
        ->and($error(429, 'daily_limit_exceeded')->provesKeyUnknown())->toBeTrue()
        ->and($error(422, 'validation_failed')->provesKeyUnknown())->toBeTrue()
        ->and($error(400, 'content_policy_violation')->provesKeyUnknown())->toBeTrue()
        ->and($error(429, 'rate_limit_exceeded')->provesKeyUnknown())->toBeFalse()
        ->and($error(401, 'invalid_api_key')->provesKeyUnknown())->toBeFalse()
        ->and($error(503, 'overloaded')->provesKeyUnknown())->toBeFalse()
        ->and(PixelforgeException::connection(new RuntimeException('x'))->provesKeyUnknown())->toBeFalse()
        ->and(PixelforgeException::invalidResponse('bad')->provesKeyUnknown())->toBeFalse();
});

it('knows when an attempt created nothing', function () use ($error) {
    expect($error(429, 'concurrency_limit_exceeded')->createdNothing())->toBeTrue()
        ->and($error(422, 'validation_failed')->createdNothing())->toBeTrue()
        ->and($error(400, 'content_policy_violation')->createdNothing())->toBeTrue()
        ->and($error(429, 'daily_limit_exceeded')->createdNothing())->toBeTrue()
        ->and($error(429, 'rate_limit_exceeded')->createdNothing())->toBeTrue()
        ->and($error(401, 'invalid_api_key')->createdNothing())->toBeTrue()
        ->and($error(404, 'not_found')->createdNothing())->toBeTrue()
        ->and($error(503, 'overloaded')->createdNothing())->toBeTrue()
        ->and(PixelforgeException::connection(new RuntimeException('x'))->createdNothing())->toBeFalse()
        ->and(PixelforgeException::invalidResponse('bad')->createdNothing())->toBeFalse()
        ->and($error(500, 'unknown_error')->createdNothing())->toBeFalse()
        ->and($error(502, 'bad_gateway')->createdNothing())->toBeFalse()
        ->and($error(503, 'other_code')->createdNothing())->toBeFalse()
        ->and($error(409, 'idempotency_key_reused')->createdNothing())->toBeFalse();
});

it('retries every server error but not daily limits or invalid responses', function () use ($error) {
    expect($error(500, 'unknown_error')->retryable)->toBeTrue()
        ->and($error(502, 'bad_gateway')->retryable)->toBeTrue()
        ->and($error(504, 'timeout')->retryable)->toBeTrue()
        ->and($error(429, 'daily_limit_exceeded')->retryable)->toBeFalse()
        ->and(PixelforgeException::invalidResponse('bad', 502)->retryable)->toBeFalse();
});
