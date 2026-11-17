<?php

declare(strict_types=1);

use App\Actions\GetCachedPricing;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\SamplePricing;
use App\Support\Money\Money;
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

    $this->actingAs(User::factory()->withCredit()->create())
        ->postJson(route('generations.estimate'), workedExamplePayload())
        ->assertOk()
        ->assertExactJson([
            'estimated_charge' => ['amount' => '0.412562500', 'display' => '$0.4126'],
            'maximum_charge' => ['amount' => '0.467343750', 'display' => '$0.4673'],
            'available' => ['amount' => '10.000000000', 'display' => '$10.0000'],
            'affordable' => true,
        ]);

    expect(Generation::query()->count())->toBe(0)
        ->and($client->created)->toBeEmpty();
});

it('accepts an empty prompt and no references', function () {
    seedEstimatePricing();

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), ['prompt' => '', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertOk()
        ->assertJsonStructure([
            'estimated_charge' => ['amount', 'display'],
            'maximum_charge' => ['amount', 'display'],
            'available' => ['amount', 'display'],
            'affordable',
        ]);
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

it('reports affordability against the available credit', function (string $usd, bool $affordable) {
    seedEstimatePricing();

    $user = User::factory()->withCredit($usd)->create();

    $this->actingAs($user)
        ->postJson(route('generations.estimate'), workedExamplePayload())
        ->assertOk()
        ->assertJsonPath('affordable', $affordable)
        ->assertJsonPath('available.amount', Money::fromDecimal($usd)->toDecimalString());
})->with([
    'exactly the maximum' => ['0.467343750', true],
    'one nano short' => ['0.467343749', false],
    'plenty' => ['5', true],
]);

it('subtracts credit held by generations in progress from the available credit', function () {
    seedEstimatePricing();

    $user = User::factory()->withCredit('1')->create();
    Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Processing,
        'maximum_charge_nanos' => 600_000_000,
    ]);

    $this->actingAs($user)
        ->postJson(route('generations.estimate'), workedExamplePayload())
        ->assertOk()
        ->assertJsonPath('available.amount', '0.400000000')
        ->assertJsonPath('affordable', false);
});
