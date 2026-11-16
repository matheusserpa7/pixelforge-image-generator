<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Data\CreatedGeneration;
use App\Data\CreateGenerationRequest;
use App\Data\PixelforgeGeneration;
use App\Data\Pricing;

/**
 * The only way the app talks to Pixelforge (docs/product/pixelforge-api.md).
 *
 * Bound to HttpPixelforgeClient in AppServiceProvider; tests swap in
 * FakePixelforgeClient.
 */
interface PixelforgeClient
{
    public function pricing(): Pricing;

    public function create(CreateGenerationRequest $request): CreatedGeneration;

    public function retrieve(string $id): PixelforgeGeneration;
}
