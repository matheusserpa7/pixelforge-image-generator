<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

final class GenerationFailureMessage
{
    public static function forCode(string $code): string
    {
        return match ($code) {
            'generation_failed' => 'The generation failed. Try again.',
            'internal_error' => 'The image service failed while generating the image. Try again.',
            'content_policy_violation' => 'The prompt was rejected by the content policy.',
            'daily_limit_exceeded' => 'The daily generation limit has been reached.',
            'validation_failed' => 'The generation request was rejected.',
            'idempotency_key_reused' => 'This generation could not be submitted again.',
            'invalid_api_key' => 'The image service rejected the API key.',
            'not_found' => 'The generation could not be found.',
            'invalid_response' => 'The image service returned an unexpected response. Try again.',
            'connection_failed' => 'The image service could not be reached. Try again.',
            'rate_limit_exceeded' => 'The image service is rate limiting requests. Try again.',
            'concurrency_limit_exceeded' => 'The image service is at its concurrency limit. Try again.',
            'overloaded' => 'The image service is overloaded. Try again.',
            'submit_failed' => 'The generation could not be submitted. Try again.',
            default => 'The generation failed. Try again.',
        };
    }
}
