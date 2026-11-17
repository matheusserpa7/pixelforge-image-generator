<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use RuntimeException;
use Throwable;

final class PixelforgeException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?string $param,
        public readonly ?int $retryAfterSeconds,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connection(Throwable $previous): self
    {
        return new self(
            'Pixelforge connection failed (connection_failed).',
            0,
            'connection_failed',
            null,
            null,
            true,
            $previous,
        );
    }

    public static function invalidResponse(
        string $reason,
        int $status = 0,
        ?Throwable $previous = null,
    ): self {
        return new self(
            "Pixelforge invalid response (invalid_response): {$reason}.",
            $status,
            'invalid_response',
            null,
            null,
            false,
            $previous,
        );
    }

    public static function fromProvider(
        int $status,
        string $errorCode,
        string $message,
        ?string $param,
        ?int $retryAfterSeconds,
    ): self {
        $retryable = self::isRetryable($status, $errorCode);
        $summary = "Pixelforge error HTTP {$status} ({$errorCode}).";

        return new self(
            $summary.' '.$message,
            $status,
            $errorCode,
            $param,
            $retryAfterSeconds,
            $retryable,
        );
    }

    /**
     * True only when the provider would have accepted this key earlier had the request ever been valid
     * for it: replays skip the concurrency and daily limits, and validation and content policy depend
     * only on the request.
     */
    public function provesKeyUnknown(): bool
    {
        return ($this->status === 429 && in_array($this->errorCode, ['concurrency_limit_exceeded', 'daily_limit_exceeded'], true))
            || ($this->status === 422 && $this->errorCode === 'validation_failed')
            || ($this->status === 400 && $this->errorCode === 'content_policy_violation');
    }

    /**
     * True when this attempt certainly created nothing at the provider.
     */
    public function createdNothing(): bool
    {
        if ($this->provesKeyUnknown()) {
            return true;
        }

        $isClientError = $this->status >= 400 && $this->status <= 499;

        return ($isClientError && $this->errorCode !== 'idempotency_key_reused')
            || ($this->status === 503 && $this->errorCode === 'overloaded');
    }

    private static function isRetryable(int $status, string $errorCode): bool
    {
        if ($errorCode === 'invalid_response') {
            return false;
        }

        if ($status >= 500) {
            return true;
        }

        if ($errorCode === 'connection_failed') {
            return true;
        }

        if ($status === 429 && in_array($errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true)) {
            return true;
        }

        return false;
    }
}
