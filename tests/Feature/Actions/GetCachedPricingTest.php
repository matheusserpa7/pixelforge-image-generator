<?php

declare(strict_types=1);

use App\Actions\GetCachedPricing;
use App\Services\Billing\PricingUnavailable;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\SamplePricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget(GetCachedPricing::CACHE_KEY);
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.api_key' => 'test-key',
        'services.pixelforge.timeout' => 10,
        'billing.pricing_cache_seconds' => 3600,
    ]);
});

function cachedPricing(): GetCachedPricing
{
    return app(GetCachedPricing::class);
}

it('sends one HTTP request when handle is called twice', function () {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(SamplePricing::JSON, 200),
    ]);

    $action = cachedPricing();

    $first = $action->handle();
    $second = $action->handle();

    expect($first->charactersPerTextToken)->toBe($second->charactersPerTextToken);

    Http::assertSentCount(1);
    expect(Cache::has(GetCachedPricing::CACHE_KEY))->toBeTrue();
});

it('throws PricingUnavailable on 503 with an empty cache and does not cache', function () {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(
            ['error' => ['type' => 'server_error', 'code' => 'overloaded', 'message' => 'Busy.']],
            503,
            ['Retry-After' => '2'],
        ),
    ]);

    try {
        cachedPricing()->handle();
        test()->fail('Expected PricingUnavailable.');
    } catch (PricingUnavailable $exception) {
        expect($exception->getMessage())->toBe('Pricing is unavailable. Try again in a moment.')
            ->and($exception->getPrevious())->not->toBeNull();

        $previous = $exception->getPrevious();
        expect($previous)->toBeInstanceOf(PixelforgeException::class)
            ->and($previous->errorCode)->toBe('overloaded');
    }

    expect(Cache::has(GetCachedPricing::CACHE_KEY))->toBeFalse();
    Http::assertSentCount(1);
});

it('returns warm cache when the provider responds 503 on a later call', function () {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(SamplePricing::JSON, 200),
    ]);

    $action = cachedPricing();
    $warm = $action->handle();

    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(
            ['error' => ['type' => 'server_error', 'code' => 'overloaded', 'message' => 'Busy.']],
            503,
        ),
    ]);

    $fromCache = $action->handle();

    expect($fromCache->charactersPerTextToken)->toBe($warm->charactersPerTextToken)
        ->and($fromCache->rates->inputText->nanos)->toBe($warm->rates->inputText->nanos);

    Http::assertNothingSent();
});

it('discards a non-array cache value and refetches pricing', function () {
    Cache::put(GetCachedPricing::CACHE_KEY, 'not-pricing', 3600);

    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(SamplePricing::JSON, 200),
    ]);

    $pricing = cachedPricing()->handle();

    expect($pricing->charactersPerTextToken)->toBe(4);
    Http::assertSentCount(1);
    expect(Cache::get(GetCachedPricing::CACHE_KEY))->toBeArray();
});
