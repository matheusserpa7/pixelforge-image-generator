<?php

declare(strict_types=1);

use App\Actions\GetCachedPricing;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\SamplePricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget(GetCachedPricing::CACHE_KEY);
    config(['billing.markup_percent' => '25']);
});

function workedExamplePayload(array $overrides = []): array
{
    return array_merge([
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['a', 'b'],
    ], $overrides);
}

function seedEstimatePricing(): void
{
    Cache::put(
        GetCachedPricing::CACHE_KEY,
        SamplePricing::pricing()->toCacheArray(),
        config()->integer('billing.pricing_cache_seconds'),
    );
}

it('returns the worked example estimate as money views', function () {
    seedEstimatePricing();
    $client = new FakePixelforgeClient;
    $this->app->instance(PixelforgeClient::class, $client);

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), workedExamplePayload())
        ->assertOk()
        ->assertExactJson([
            'estimated_charge' => ['amount' => '0.412562500', 'display' => '$0.4126'],
            'maximum_charge' => ['amount' => '0.467343750', 'display' => '$0.4673'],
        ]);

    expect(Generation::query()->count())->toBe(0)
        ->and($client->created)->toBeEmpty();
});

it('accepts an empty prompt and no references', function () {
    seedEstimatePricing();

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), ['prompt' => '', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertOk()
        ->assertJsonStructure(['estimated_charge' => ['amount', 'display'], 'maximum_charge' => ['amount', 'display']]);
});

it('rejects invalid input with 422', function (array $overrides) {
    seedEstimatePricing();

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), workedExamplePayload($overrides))
        ->assertUnprocessable();
})->with([
    'five references' => [['reference_images' => ['a', 'b', 'c', 'd', 'e']]],
    'unknown size' => [['size' => '999x999']],
    'unknown quality' => [['quality' => 'ultra']],
]);

it('answers 503 with the message when pricing is unavailable', function () {
    $client = new FakePixelforgeClient;
    $client->failure = PixelforgeException::fromProvider(503, 'overloaded', 'Busy.', null, 2);
    $this->app->instance(PixelforgeClient::class, $client);

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), workedExamplePayload())
        ->assertStatus(503)
        ->assertJson(['message' => 'Pricing is unavailable. Try again in a moment.']);

    expect(Generation::query()->count())->toBe(0)
        ->and($client->created)->toBeEmpty();
});

it('rejects guests', function () {
    $this->postJson(route('generations.estimate'), workedExamplePayload())->assertUnauthorized();
});

it('limits estimates to 60 per minute per user', function () {
    seedEstimatePricing();
    $this->actingAs(User::factory()->create());

    foreach (range(1, 60) as $_) {
        $this->postJson(route('generations.estimate'), workedExamplePayload())->assertOk();
    }

    $this->postJson(route('generations.estimate'), workedExamplePayload())->assertStatus(429);
});
