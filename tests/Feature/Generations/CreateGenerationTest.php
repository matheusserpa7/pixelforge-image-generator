<?php

declare(strict_types=1);

use App\Actions\GetCachedPricing;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\SamplePricing;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Cache::forget(GetCachedPricing::CACHE_KEY);
    config(['billing.markup_percent' => '25']);
});

function seedPricingCache(): void
{
    Cache::put(
        GetCachedPricing::CACHE_KEY,
        SamplePricing::pricing()->toCacheArray(),
        config()->integer('billing.pricing_cache_seconds'),
    );
}

function validGenerationPayload(array $overrides = []): array
{
    return array_merge([
        'prompt' => 'A scenic landscape at sunset.',
        'size' => ImageSize::Square->value,
        'quality' => ImageQuality::Medium->value,
        'reference_images' => [],
    ], $overrides);
}

it('redirects guests to login and stores nothing', function () {
    $this->post(route('generations.store'), validGenerationPayload())
        ->assertRedirect(route('login'));

    expect(Generation::query()->count())->toBe(0);
});

it('rejects invalid input and stores nothing', function (array $payload) {
    $user = User::factory()->create();
    $this->actingAs($user);
    seedPricingCache();

    $this->post(route('generations.store'), $payload)
        ->assertSessionHasErrors()
        ->assertRedirect();

    expect(Generation::query()->count())->toBe(0);
})->with([
    'empty prompt' => [validGenerationPayload(['prompt' => ''])],
    'omitted prompt' => [Arr::except(validGenerationPayload(), ['prompt'])],
    'blank prompt' => [validGenerationPayload(['prompt' => '   '])],
    'prompt longer than 4000 characters' => [validGenerationPayload(['prompt' => str_repeat('a', 4001)])],
    'invalid size' => [validGenerationPayload(['size' => '999x999'])],
    'invalid quality' => [validGenerationPayload(['quality' => 'ultra'])],
    'fifth reference url' => [validGenerationPayload([
        'reference_images' => [
            'https://example.com/1.jpg',
            'https://example.com/2.jpg',
            'https://example.com/3.jpg',
            'https://example.com/4.jpg',
            'https://example.com/5.jpg',
        ],
    ])],
    'non-http reference url' => [validGenerationPayload([
        'reference_images' => ['ftp://example.com/image.jpg'],
    ])],
]);

it('uses a readable attribute name in reference url errors', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->postJson(route('generations.store'), validGenerationPayload([
        'reference_images' => ['ftp://example.com/a.png'],
    ]));

    $response->assertUnprocessable();
    expect($response->json('errors')['reference_images.0'][0])
        ->toBe('The reference image URL field must be a valid URL.');
});

it('stores the worked example estimate in nano-usd', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    seedPricingCache();

    $payload = [
        'prompt' => str_repeat('a', 400),
        'size' => ImageSize::Landscape->value,
        'quality' => ImageQuality::High->value,
        'reference_images' => [
            'https://example.com/ref-a.jpg',
            'https://example.com/ref-b.jpg',
        ],
    ];

    $response = $this->post(route('generations.store'), $payload)
        ->assertRedirect(route('generations.index'));

    $generation = Generation::query()->sole();

    $response->assertSessionHas('generation_id', $generation->id);

    expect($generation->user_id)->toBe($user->id)
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->prompt)->toBe($payload['prompt'])
        ->and($generation->size)->toBe(ImageSize::Landscape)
        ->and($generation->quality)->toBe(ImageQuality::High)
        ->and($generation->reference_images)->toBe($payload['reference_images'])
        ->and($generation->estimated_provider_cost_nanos)->toBe(330_050_000)
        ->and($generation->maximum_provider_cost_nanos)->toBe(373_875_000)
        ->and($generation->estimated_charge_nanos)->toBe(412_562_500)
        ->and($generation->maximum_charge_nanos)->toBe(467_343_750)
        ->and($generation->submit_attempts)->toBe(0)
        ->and($generation->provider_id)->toBeNull();
});

it('defaults omitted size and quality', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    seedPricingCache();

    $this->post(route('generations.store'), ['prompt' => 'A cat wearing a hat.'])
        ->assertRedirect(route('generations.index'));

    $generation = Generation::query()->sole();

    expect($generation->size)->toBe(ImageSize::Square)
        ->and($generation->quality)->toBe(ImageQuality::Medium)
        ->and($generation->reference_images)->toBe([]);
});

it('creates two generations with distinct ulids', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    seedPricingCache();

    $this->post(route('generations.store'), validGenerationPayload())->assertRedirect();
    $this->post(route('generations.store'), validGenerationPayload(['prompt' => 'Another prompt.']))->assertRedirect();

    $ids = Generation::query()->orderBy('created_at')->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->not->toBe($ids[1])
        ->and(Str::isUlid($ids[0]))->toBeTrue()
        ->and(Str::isUlid($ids[1]))->toBeTrue();
});

it('stores nothing when pricing is unavailable', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    /** @var FakePixelforgeClient $client */
    $client = new FakePixelforgeClient;
    $client->failure = PixelforgeException::fromProvider(503, 'overloaded', 'Busy.', null, 2);
    $this->app->instance(PixelforgeClient::class, $client);

    $this->post(route('generations.store'), validGenerationPayload())
        ->assertRedirect()
        ->assertSessionHasErrors(['pricing']);

    expect(Generation::query()->count())->toBe(0)
        ->and($client->created)->toBeEmpty();
});

it('stores nothing when the pricing cache is empty and pricing cannot be fetched', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    /** @var FakePixelforgeClient $client */
    $client = new FakePixelforgeClient;
    $client->failure = PixelforgeException::connection(new RuntimeException('Unreachable.'));
    $this->app->instance(PixelforgeClient::class, $client);

    $this->post(route('generations.store'), validGenerationPayload())
        ->assertSessionHasErrors(['pricing']);

    expect(Generation::query()->count())->toBe(0)
        ->and($client->created)->toBeEmpty();
});

it('dispatches a submit job after commit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    seedPricingCache();

    $this->post(route('generations.store'), validGenerationPayload())
        ->assertRedirect(route('generations.index'));

    $generation = Generation::query()->sole();

    Queue::assertPushed(
        SubmitGeneration::class,
        fn (SubmitGeneration $job): bool => $job->generationId === $generation->id && $job->afterCommit === true,
    );
});

it('scopes generations to the owner and enforces view policy', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    seedPricingCache();

    $this->actingAs($owner);
    $this->post(route('generations.store'), validGenerationPayload())->assertRedirect();

    $generation = Generation::query()->sole();

    expect(Gate::forUser($other)->denies('view', $generation))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $generation))->toBeTrue()
        ->and(Generation::query()->forUser($owner)->count())->toBe(1)
        ->and(Generation::query()->forUser($other)->count())->toBe(0);
});

it('nulls user_id on the generation when the user is deleted', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    seedPricingCache();

    $this->post(route('generations.store'), validGenerationPayload())->assertRedirect();

    $generationId = Generation::query()->value('id');
    $user->delete();

    $generation = Generation::query()->find($generationId);

    expect($generation)->not->toBeNull();
    expect($generation)->toBeInstanceOf(Generation::class)
        ->and($generation->user_id)->toBeNull()
        ->and(Gate::forUser($user)->denies('view', $generation))->toBeTrue();
});

it('flashes a toast after a successful submit', function () {
    $this->actingAs(User::factory()->create());
    seedPricingCache();

    $this->post(route('generations.store'), validGenerationPayload())
        ->assertRedirect(route('generations.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Generation queued.']);
});

it('turns the eleventh submit in a minute into a readable error and stores nothing', function () {
    $this->actingAs(User::factory()->create());
    seedPricingCache();

    foreach (range(1, 10) as $_) {
        $this->post(route('generations.store'), validGenerationPayload())->assertRedirect(route('generations.index'));
    }

    $this->from(route('generations.index'))
        ->post(route('generations.store'), validGenerationPayload())
        ->assertRedirect(route('generations.index'))
        ->assertSessionHasErrors(['generation']);

    expect(session('errors')->first('generation'))->toMatch('/^You are submitting too fast\. Try again in \d+ seconds\.$/')
        ->and(Generation::query()->count())->toBe(10);
});
