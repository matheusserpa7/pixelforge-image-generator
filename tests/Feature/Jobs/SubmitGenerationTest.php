<?php

declare(strict_types=1);

use App\Actions\GetCachedPricing;
use App\Data\GenerationRates;
use App\Data\TokenUsage;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\GenerationFailureMessage;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgeRequestLimiter;
use App\Support\Money\Money;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget(GetCachedPricing::CACHE_KEY);
    RateLimiter::clear(PixelforgeRequestLimiter::RATE_KEY);

    for ($i = 1; $i <= 10; $i++) {
        Cache::lock(PixelforgeRequestLimiter::LOCK_PREFIX.$i, PixelforgeRequestLimiter::LOCK_SECONDS)->forceRelease();
    }

    config([
        'billing.markup_percent' => '25',
        'services.pixelforge.max_submit_attempts' => 5,
    ]);
});

function bindFakePixelforgeClient(): FakePixelforgeClient
{
    $client = new FakePixelforgeClient;
    app()->instance(PixelforgeClient::class, $client);

    return $client;
}

function runSubmitJob(Generation $generation, bool $fakeQueue = false): SubmitGeneration
{
    $job = new SubmitGeneration($generation->id);

    if ($fakeQueue) {
        $job->withFakeQueueInteractions();
    }

    app()->call([$job, 'handle']);

    return $job;
}

it('submits a queued generation on the happy path', function () {
    $client = bindFakePixelforgeClient();
    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    runSubmitJob($generation);

    $generation->refresh();

    expect($client->created)->toHaveCount(1)
        ->and($client->created[0]->idempotencyKey)->toBe($generation->id)
        ->and($client->created[0]->metadata)->toBe(['generation_id' => $generation->id])
        ->and($generation->provider_id)->toBe('gen_fake_1')
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submit_attempts)->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('does not call create again when the job runs a second time', function () {
    $client = bindFakePixelforgeClient();
    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    runSubmitJob($generation);
    runSubmitJob($generation->fresh());

    expect($client->created)->toHaveCount(1);
});

it('releases with backoff on a retryable rate limit', function () {
    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(
        429,
        'rate_limit_exceeded',
        'Slow down.',
        null,
        7,
    );

    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    $job = runSubmitJob($generation, fakeQueue: true);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBeNull()
        ->and($generation->submit_attempts)->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($client->created)->toBeEmpty();

    $job->assertReleased(8);
});

it('marks the generation failed after the max submit attempts on retryable errors', function () {
    config(['services.pixelforge.max_submit_attempts' => 2]);

    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(
        429,
        'rate_limit_exceeded',
        'Slow down.',
        null,
        7,
    );

    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    runSubmitJob($generation, fakeQueue: true);

    $client->failure = PixelforgeException::fromProvider(
        429,
        'rate_limit_exceeded',
        'Slow down.',
        null,
        7,
    );

    $second = runSubmitJob($generation->fresh(), fakeQueue: true);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('rate_limit_exceeded')
        ->and($generation->error_message)->toBe(GenerationFailureMessage::forCode('rate_limit_exceeded'))
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($client->created)->toBeEmpty();

    $second->assertNotReleased();
});

it('fails immediately on daily limit exceeded', function () {
    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(402, 'daily_limit_exceeded', 'Quota.', null, null);

    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    runSubmitJob($generation);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('daily_limit_exceeded')
        ->and($client->created)->toBeEmpty();
});

it('fails immediately on content policy violation', function () {
    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(400, 'content_policy_violation', 'Rejected.', null, null);

    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    runSubmitJob($generation);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('content_policy_violation');
});

it('charges when the fake returns an immediate success snapshot', function () {
    $client = bindFakePixelforgeClient();
    $client->createStatus = GenerationStatus::Succeeded;
    $client->createUsage = new TokenUsage(2, 0, 0, 0);
    $client->createRates = new GenerationRates(
        Money::perToken('6.00'),
        Money::zero(),
        Money::zero(),
        Money::zero(),
    );

    Cache::put(GetCachedPricing::CACHE_KEY, ['broken' => true], 60);

    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    runSubmitJob($generation);

    $generation->refresh();
    $ledger = LedgerEntry::query()->sole();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nanos)->toBe(12_000)
        ->and($generation->charge_nanos)->toBe(15_000)
        ->and($ledger->provider_cost_nanos)->toBe(12_000)
        ->and($ledger->charge_nanos)->toBe(15_000)
        ->and(Cache::get(GetCachedPricing::CACHE_KEY))->toBe(['broken' => true]);
});

it('releases without counting a wait for a concurrency slot', function () {
    config([
        'services.pixelforge.concurrency_limit' => 1,
        'services.pixelforge.concurrency_retry_seconds' => 5,
    ]);

    $client = bindFakePixelforgeClient();
    $generation = Generation::factory()->create(['submit_attempts' => 0]);
    $held = Cache::lock(PixelforgeRequestLimiter::LOCK_PREFIX.'1', PixelforgeRequestLimiter::LOCK_SECONDS);

    expect($held->get())->toBeTrue();

    try {
        $job = runSubmitJob($generation, fakeQueue: true);
        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->provider_id)->toBeNull()
            ->and($generation->submit_attempts)->toBe(0)
            ->and($client->created)->toBeEmpty();

        $job->assertReleased(5);
    } finally {
        $held->release();
    }
});

it('releases when success is missing usage', function () {
    $client = bindFakePixelforgeClient();
    $client->createStatus = GenerationStatus::Succeeded;
    $client->createUsage = null;

    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    $job = runSubmitJob($generation, fakeQueue: true);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBeNull()
        ->and($job->assertReleased(1))->toBeInstanceOf(SubmitGeneration::class);
});

it('fails an incomplete success once submit attempts are exhausted', function () {
    config(['services.pixelforge.max_submit_attempts' => 1]);

    $client = bindFakePixelforgeClient();
    $client->createStatus = GenerationStatus::Succeeded;
    $client->createUsage = null;

    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    $job = runSubmitJob($generation, fakeQueue: true);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('invalid_response')
        ->and($generation->error_message)->toBe(GenerationFailureMessage::forCode('invalid_response'))
        ->and($generation->provider_id)->toBeNull()
        ->and($client->created)->toHaveCount(1)
        ->and(LedgerEntry::query()->count())->toBe(0);

    $job->assertNotReleased();
});

it('skips create when the generation is already final', function () {
    $client = bindFakePixelforgeClient();
    $generation = Generation::factory()->create(['status' => GenerationStatus::Failed]);

    runSubmitJob($generation);

    expect($client->created)->toBeEmpty();
});

it('skips create when a provider id is already stored', function () {
    $client = bindFakePixelforgeClient();
    $generation = Generation::factory()->create([
        'provider_id' => 'gen_existing',
        'submit_attempts' => 0,
    ]);

    runSubmitJob($generation);

    expect($client->created)->toBeEmpty();
});

it('exposes queue guardrails on the job', function () {
    $generation = Generation::factory()->create();
    $job = new SubmitGeneration($generation->id);

    $middleware = $job->middleware();
    $retryUntil = $job->retryUntil();

    expect($job->tries)->toBe(100_000)
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe($generation->id)
        ->and($middleware[0]->expiresAfter)->toBe(60)
        ->and($middleware[0]->releaseAfter)->toBe(5)
        ->and($retryUntil->getTimestamp())->toBeGreaterThan(now()->addHours(5)->getTimestamp())
        ->and($retryUntil->getTimestamp())->toBeLessThan(now()->addHours(7)->getTimestamp());
});

it('records submit_failed when the worker gives up', function () {
    $generation = Generation::factory()->create(['submit_attempts' => 0]);
    $job = new SubmitGeneration($generation->id);

    $job->failed(new RuntimeException('worker lost the job'));

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('submit_failed')
        ->and($generation->error_message)->toBe(GenerationFailureMessage::forCode('submit_failed'))
        ->and($generation->error_message)->not->toBe('worker lost the job')
        ->and(LedgerEntry::query()->count())->toBe(0);
});
