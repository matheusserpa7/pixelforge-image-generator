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

    private static function isRetryable(int $status, string $errorCode): bool
    {
        if ($errorCode === 'connection_failed') {
            return true;
        }

        if ($status === 429 && in_array($errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true)) {
            return true;
        }

        if ($status === 503 && $errorCode === 'overloaded') {
            return true;
        }

        return false;
    }
}
