<?php

declare(strict_types=1);

use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\HttpPixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;

it('resolves the HTTP client by default', function () {
    expect(app(PixelforgeClient::class))->toBeInstanceOf(HttpPixelforgeClient::class);
});

it('lets tests swap in the fake client', function () {
    $this->app->instance(PixelforgeClient::class, new FakePixelforgeClient);

    expect(app(PixelforgeClient::class))->toBeInstanceOf(FakePixelforgeClient::class);
});
