<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

final class PixelforgeRequestLimiter
{
    public const string RATE_KEY = 'pixelforge-requests';

    public const string LOCK_PREFIX = 'pixelforge-concurrency-';

    public const int LOCK_SECONDS = 60;

    public static function concurrencyLimit(): int
    {
        return self::positiveIntConfig('services.pixelforge.concurrency_limit', 3);
    }

    public static function requestsPerMinute(): int
    {
        return self::positiveIntConfig('services.pixelforge.requests_per_minute', 60);
    }

    public static function maxSubmitAttempts(): int
    {
        return self::positiveIntConfig('services.pixelforge.max_submit_attempts', 5);
    }

    public static function concurrencyRetrySeconds(): int
    {
        return self::positiveIntConfig('services.pixelforge.concurrency_retry_seconds', 5);
    }

    public static function submitGiveUpSeconds(): int
    {
        return self::positiveIntConfig('services.pixelforge.submit_give_up_seconds', 86400);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(callable $callback): mixed
    {
        $lock = $this->acquireConcurrencyLock();

        if ($lock === null) {
            throw new PixelforgeBusy(self::concurrencyRetrySeconds());
        }

        try {
            if (RateLimiter::tooManyAttempts(self::RATE_KEY, self::requestsPerMinute())) {
                throw new PixelforgeBusy(max(1, RateLimiter::availableIn(self::RATE_KEY)));
            }

            RateLimiter::hit(self::RATE_KEY, 60);

            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Spend one request of the shared per-minute budget without taking a
     * concurrency slot (for calls that are not generations, like GET /v1/usage).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function attempt(callable $callback): mixed
    {
        if (RateLimiter::tooManyAttempts(self::RATE_KEY, self::requestsPerMinute())) {
            throw new PixelforgeBusy(max(1, RateLimiter::availableIn(self::RATE_KEY)));
        }

        RateLimiter::hit(self::RATE_KEY, 60);

        return $callback();
    }

    private function acquireConcurrencyLock(): ?Lock
    {
        $limit = self::concurrencyLimit();

        for ($i = 1; $i <= $limit; $i++) {
            $lock = Cache::lock(self::LOCK_PREFIX.$i, self::LOCK_SECONDS);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    private static function positiveIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_numeric($value)) {
            return $default;
        }

        $int = (int) $value;

        return $int < 1 ? $default : $int;
    }
}
