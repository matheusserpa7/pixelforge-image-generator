<?php

declare(strict_types=1);

use App\Services\Pixelforge\GenerationFailureMessage;

it('tells the user to try again on transient failures', function (string $code) {
    expect(GenerationFailureMessage::forCode($code))->toEndWith(' Try again.');
})->with([
    'generation_failed', 'internal_error', 'connection_failed', 'rate_limit_exceeded',
    'concurrency_limit_exceeded', 'overloaded', 'submit_failed', 'invalid_response', 'unknown_code',
]);

it('does not suggest retrying permanent failures', function (string $code) {
    expect(GenerationFailureMessage::forCode($code))->not->toContain('Try again');
})->with(['content_policy_violation', 'daily_limit_exceeded']);
