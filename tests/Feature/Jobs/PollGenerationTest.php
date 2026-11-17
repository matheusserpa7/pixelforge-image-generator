<?php

declare(strict_types=1);

use App\Actions\GetCachedPricing;
use App\Data\PixelforgeGeneration;
use App\Enums\GenerationStatus;
use App\Jobs\DownloadGenerationImage;
use App\Jobs\PollGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgeRequestLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([DownloadGenerationImage::class]);
    Cache::forget(GetCachedPricing::CACHE_KEY);
    RateLimiter::clear(PixelforgeRequestLimiter::RATE_KEY);

    for ($i = 1; $i <= 10; $i++) {
        Cache::lock(PixelforgeRequestLimiter::LOCK_PREFIX.$i, PixelforgeRequestLimiter::LOCK_SECONDS)->forceRelease();
    }

    config(['billing.markup_percent' => '25']);
});

/**
 * @param  array<string, mixed>|null  $object
 */
function pollFake(Generation $generation, ?array $object = null): FakePixelforgeClient
{
    $client = new FakePixelforgeClient;
    app()->instance(PixelforgeClient::class, $client);

    if ($object !== null) {
        $client->generations[(string) $generation->provider_id] = PixelforgeGeneration::fromDecoded($object);
    }

    return $client;
}

function runPoll(Generation $generation): void
{
    app()->call([new PollGeneration($generation->id), 'handle']);
}

function polledGeneration(): Generation
{
    return Generation::factory()->create([
        'status' => GenerationStatus::Processing,
        'provider_id' => 'gen_test_1',
        'submit_attempts' => 2,
    ]);
}

it('charges once for a succeeded snapshot', function () {
    $generation = polledGeneration();
    pollFake($generation, pixelforgeGenerationObject([
        'status' => 'succeeded',
        'metadata' => ['generation_id' => $generation->id],
        'usage' => [
            'input_text_tokens' => '100',
            'input_image_tokens' => '765',
            'output_tokens' => '1000',
            'reasoning_tokens' => '200',
        ],
        'image_url' => 'https://example.test/image.png',
        'image_url_expires_at' => '2026-11-17T12:00:00Z',
    ]));

    runPoll($generation);
    runPoll($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('fails a generation the provider reports as failed, without charge', function () {
    $generation = polledGeneration();
    pollFake($generation, pixelforgeGenerationObject([
        'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'boom'],
    ]));

    runPoll($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('fails the generation when the provider does not know it', function () {
    $generation = polledGeneration();
    pollFake($generation);

    runPoll($generation);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('not_found')
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('leaves the row untouched on a retryable provider error', function () {
    $generation = polledGeneration();
    $client = pollFake($generation);
    $client->failure = PixelforgeException::fromProvider(429, 'rate_limit_exceeded', 'Slow down.', null, 5);

    runPoll($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->error_code)->toBeNull();
});

it('returns without calling the provider when no slot is free', function () {
    config(['services.pixelforge.concurrency_limit' => 1]);
    $generation = polledGeneration();
    $client = pollFake($generation);
    // A scripted failure that is still pending afterwards proves the fake was never called.
    $client->failure = PixelforgeException::fromProvider(500, 'internal_error', 'untouched', null, null);
    $held = Cache::lock(PixelforgeRequestLimiter::LOCK_PREFIX.'1', 60);
    $held->get();

    runPoll($generation);

    $held->release();

    expect($client->failure)->not->toBeNull()
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->error_code)->toBeNull();
});

it('does not call the provider for final rows or rows without provider id', function () {
    $final = Generation::factory()->create(['status' => GenerationStatus::Failed, 'provider_id' => 'gen_test_1']);
    $unsent = Generation::factory()->create(['status' => GenerationStatus::Queued, 'provider_id' => null]);
    $client = pollFake($final);
    $client->failure = PixelforgeException::fromProvider(500, 'internal_error', 'untouched', null, null);

    runPoll($final);
    runPoll($unsent);

    expect($client->failure)->not->toBeNull();
});

it('never changes submit attempts and is unique per generation', function () {
    $generation = polledGeneration();
    pollFake($generation, pixelforgeGenerationObject(['status' => 'processing']));

    runPoll($generation);

    expect($generation->refresh()->submit_attempts)->toBe(2)
        ->and((new PollGeneration($generation->id))->uniqueId())->toBe($generation->id);
});
