<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\Pricing;
use App\Services\Billing\PricingUnavailable;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class GetCachedPricing
{
    public const string CACHE_KEY = 'pixelforge.pricing';

    public function __construct(
        private readonly PixelforgeClient $client,
    ) {}

    public function handle(): Pricing
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            try {
                return Pricing::fromCacheArray($cached);
            } catch (InvalidArgumentException) {
                Cache::forget(self::CACHE_KEY);
            }
        } elseif ($cached !== null) {
            Cache::forget(self::CACHE_KEY);
        }

        try {
            $pricing = $this->client->pricing();
        } catch (PixelforgeException $e) {
            throw new PricingUnavailable(
                'Pricing is unavailable. Try again in a moment.',
                previous: $e,
            );
        }

        Cache::put(
            self::CACHE_KEY,
            $pricing->toCacheArray(),
            config()->integer('billing.pricing_cache_seconds'),
        );

        return $pricing;
    }
}
