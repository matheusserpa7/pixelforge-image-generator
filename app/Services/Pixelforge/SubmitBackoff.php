<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use InvalidArgumentException;

final class SubmitBackoff
{
    public static function seconds(?int $retryAfterSeconds, int $submitAttempts): int
    {
        if ($submitAttempts < 1) {
            throw new InvalidArgumentException('Submit attempts must be at least 1.');
        }

        $retryAfter = $retryAfterSeconds ?? 0;

        if ($retryAfter < 0) {
            $retryAfter = 0;
        }

        $shift = min($submitAttempts - 1, 20);

        return $retryAfter + (2 ** $shift);
    }
}
