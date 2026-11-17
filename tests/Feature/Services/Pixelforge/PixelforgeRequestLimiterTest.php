<?php

declare(strict_types=1);

use App\Services\Pixelforge\PixelforgeBusy;
use App\Services\Pixelforge\PixelforgeRequestLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Http::preventStrayRequests();
    RateLimiter::clear(PixelforgeRequestLimiter::RATE_KEY);

    for ($i = 1; $i <= 10; $i++) {
        Cache::lock(PixelforgeRequestLimiter::LOCK_PREFIX.$i, PixelforgeRequestLimiter::LOCK_SECONDS)->forceRelease();
    }
});

it('rejects a nested run when concurrency is one', function () {
    config(['services.pixelforge.concurrency_limit' => 1]);

    $limiter = new PixelforgeRequestLimiter;
    $innerRan = false;

    $limiter->run(function () use ($limiter, &$innerRan): void {
        expect(fn () => $limiter->run(function () use (&$innerRan): void {
            $innerRan = true;
        }))->toThrow(PixelforgeBusy::class);
    });

    expect($innerRan)->toBeFalse();

    $outerRan = false;
    $limiter->run(function () use (&$outerRan): void {
        $outerRan = true;
    });

    expect($outerRan)->toBeTrue();
});

it('rejects a second run when the per-minute limit is one', function () {
    config(['services.pixelforge.requests_per_minute' => 1]);

    $limiter = new PixelforgeRequestLimiter;
    $secondCallbackRan = false;

    $limiter->run(fn (): string => 'first');

    try {
        $limiter->run(function () use (&$secondCallbackRan): void {
            $secondCallbackRan = true;
        });
        test()->fail('Expected PixelforgeBusy.');
    } catch (PixelforgeBusy $busy) {
        expect($busy->retryAfterSeconds)->toBeGreaterThanOrEqual(1)
            ->and($secondCallbackRan)->toBeFalse();
    }
});
