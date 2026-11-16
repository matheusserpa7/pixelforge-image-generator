<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

/**
 * In-memory Pixelforge for tests: no network, scripted responses.
 * Behaviour arrives with spec 001-pixelforge-client-and-pricing.
 */
final class FakePixelforgeClient implements PixelforgeClient {}
