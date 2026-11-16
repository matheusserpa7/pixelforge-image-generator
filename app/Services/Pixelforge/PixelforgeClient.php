<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

/**
 * The only way the app talks to Pixelforge (docs/product/pixelforge-api.md).
 *
 * Bound to HttpPixelforgeClient in AppServiceProvider; tests swap in
 * FakePixelforgeClient. Methods (create, retrieve, pricing) arrive with
 * spec 001-pixelforge-client-and-pricing.
 */
interface PixelforgeClient {}
