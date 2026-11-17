<?php

declare(strict_types=1);

use App\Actions\GetCachedPricing;
use App\Data\CreatedGeneration;
use App\Data\CreateGenerationRequest;
use App\Data\GenerationRates;
use App\Data\PixelforgeGeneration;
use App\Data\Pricing;
use App\Data\ProviderUsage;
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
use App\Services\Pixelforge\SubmitBackoff;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
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

it('waits on a per-minute 429 without using an attempt', function () {
    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(429, 'rate_limit_exceeded', 'Slow down.', null, 7);
    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    $job = runSubmitJob($generation, fakeQueue: true);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBeNull()
        ->and($generation->submit_attempts)->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);

    $job->assertReleased(7);
});

it('never fails a generation after many per-minute 429s in a row', function () {
    config(['services.pixelforge.max_submit_attempts' => 1]);

    $client = bindFakePixelforgeClient();
    $generation = Generation::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $client->failure = PixelforgeException::fromProvider(429, 'rate_limit_exceeded', 'Slow down.', null, 7);
        runSubmitJob($generation, fakeQueue: true)->assertReleased(7);
    }

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submit_attempts)->toBe(0);
});

it('touches updated_at on every wait path', function () {
    $this->freezeTime();

    $stale = fn (Generation $g) => Generation::query()->whereKey($g->id)->update(['updated_at' => now()->subHour()]);

    // Provider 429 (per-minute).
    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(429, 'rate_limit_exceeded', 'Slow down.', null, 7);
    $generation = Generation::factory()->create();
    $stale($generation);
    runSubmitJob($generation, fakeQueue: true);
    expect($generation->refresh()->updated_at->toDateTimeString())->toBe(now()->toDateTimeString());

    // Retryable error backoff.
    $client->failure = PixelforgeException::fromProvider(503, 'overloaded', 'Busy.', null, 3);
    $stale($generation);
    runSubmitJob($generation, fakeQueue: true);
    expect($generation->refresh()->updated_at->toDateTimeString())->toBe(now()->toDateTimeString());

    // Limiter busy (per-minute budget spent).
    $client->failure = null;
    config(['services.pixelforge.requests_per_minute' => 1]);
    RateLimiter::hit(PixelforgeRequestLimiter::RATE_KEY, 60);
    $stale($generation);
    runSubmitJob($generation, fakeQueue: true);
    expect($generation->refresh()->updated_at->toDateTimeString())->toBe(now()->toDateTimeString());

    // In-flight count full.
    config(['services.pixelforge.requests_per_minute' => 60, 'services.pixelforge.concurrency_limit' => 1]);
    Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_busy']);
    $stale($generation);
    runSubmitJob($generation, fakeQueue: true);
    expect($generation->refresh()->updated_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('marks the generation failed after the max submit attempts on retryable errors', function () {
    config(['services.pixelforge.max_submit_attempts' => 2]);

    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(
        503,
        'overloaded',
        'Slow down.',
        null,
        7,
    );

    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    runSubmitJob($generation, fakeQueue: true);

    $client->failure = PixelforgeException::fromProvider(
        503,
        'overloaded',
        'Slow down.',
        null,
        7,
    );

    $second = runSubmitJob($generation->fresh(), fakeQueue: true);

    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('overloaded')
        ->and($generation->error_message)->toBe(GenerationFailureMessage::forCode('overloaded'))
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($client->created)->toBeEmpty();

    $second->assertNotReleased();
});

it('fails immediately on daily limit exceeded', function () {
    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(429, 'daily_limit_exceeded', 'Quota.', null, null);

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

it('stores the provider id and ends the job when success is missing usage', function () {
    $client = bindFakePixelforgeClient();
    $client->createStatus = GenerationStatus::Succeeded;
    $client->createUsage = null;

    $generation = Generation::factory()->create(['submit_attempts' => 0]);

    $job = runSubmitJob($generation, fakeQueue: true);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_fake_1')
        ->and($client->created)->toHaveCount(1)
        ->and(LedgerEntry::query()->count())->toBe(0);

    $job->assertNotReleased();
});

it('keeps a generation unconfirmed after a connection failure on the last attempt', function () {
    config(['services.pixelforge.max_submit_attempts' => 1]);

    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::connection(new RuntimeException('timeout'));
    $generation = Generation::factory()->create();

    $job = runSubmitJob($generation, fakeQueue: true);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBeNull()
        ->and($generation->submit_unconfirmed)->toBeTrue()
        ->and(LedgerEntry::query()->count())->toBe(0);

    $job->assertNotReleased();
});

it('keeps a generation unconfirmed after an invalid response', function () {
    config(['services.pixelforge.max_submit_attempts' => 1]);

    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::invalidResponse('bad body', 200);
    $generation = Generation::factory()->create();

    runSubmitJob($generation, fakeQueue: true);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submit_unconfirmed)->toBeTrue();
});

it('retries a server error with backoff and keeps it unconfirmed', function () {
    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(500, 'unknown_error', 'Boom.', null, null);
    $generation = Generation::factory()->create();

    $job = runSubmitJob($generation, fakeQueue: true);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submit_attempts)->toBe(1)
        ->and($generation->submit_unconfirmed)->toBeTrue();

    $job->assertReleased(SubmitBackoff::seconds(null, 1));
});

it('recovers an unconfirmed generation through the idempotent replay', function () {
    config(['services.pixelforge.max_submit_attempts' => 1]);

    $client = bindFakePixelforgeClient();
    $generation = Generation::factory()->create();

    $client->create(new CreateGenerationRequest(
        prompt: $generation->prompt,
        size: $generation->size,
        quality: $generation->quality,
        referenceImages: [],
        metadata: ['generation_id' => $generation->id],
        idempotencyKey: $generation->id,
    ));

    $client->failure = PixelforgeException::connection(new RuntimeException('timeout'));

    runSubmitJob($generation, fakeQueue: true);

    expect($generation->refresh()->submit_unconfirmed)->toBeTrue()
        ->and($generation->provider_id)->toBeNull();

    runSubmitJob($generation->fresh(), fakeQueue: true);
    $generation->refresh();

    expect($generation->provider_id)->toBe('gen_fake_1')
        ->and($generation->status)->toBe(GenerationStatus::Queued);
});

it('keeps an unconfirmed generation on an overload but fails a confirmed one', function () {
    config(['services.pixelforge.max_submit_attempts' => 1]);

    $client = bindFakePixelforgeClient();
    $unconfirmed = Generation::factory()->create(['submit_unconfirmed' => true]);
    $confirmed = Generation::factory()->create();

    $client->failure = PixelforgeException::fromProvider(503, 'overloaded', 'Slow.', null, 3);
    runSubmitJob($unconfirmed, fakeQueue: true);

    $client->failure = PixelforgeException::fromProvider(503, 'overloaded', 'Slow.', null, 3);
    runSubmitJob($confirmed, fakeQueue: true);

    expect($unconfirmed->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and($unconfirmed->submit_unconfirmed)->toBeTrue()
        ->and($confirmed->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($confirmed->error_message)->toBe(GenerationFailureMessage::forCode('overloaded'));
});

it('fails an unconfirmed generation at once when the provider proves the key unknown', function (int $status, string $code) {
    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider($status, $code, 'No.', null, null);
    $generation = Generation::factory()->create(['submit_unconfirmed' => true]);

    runSubmitJob($generation, fakeQueue: true);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->submit_unconfirmed)->toBeFalse();
})->with([
    'validation' => [422, 'validation_failed'],
    'content policy' => [400, 'content_policy_violation'],
    'daily limit' => [429, 'daily_limit_exceeded'],
]);

it('gives up on an unconfirmed generation after the give-up window', function () {
    config(['services.pixelforge.submit_give_up_seconds' => 3600]);

    $client = bindFakePixelforgeClient();
    $generation = Generation::factory()->create(['submit_unconfirmed' => true]);

    $this->travel(3601)->seconds();

    $job = runSubmitJob($generation, fakeQueue: true);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('submit_failed')
        ->and($generation->error_message)->toBe(GenerationFailureMessage::forCode('submit_failed'))
        ->and($client->created)->toBeEmpty();

    $job->assertNotReleased();
});

it('leaves an unconfirmed generation queued when the worker dies during the call', function () {
    app()->instance(PixelforgeClient::class, new class implements PixelforgeClient
    {
        public function pricing(): Pricing
        {
            throw new RuntimeException('worker died');
        }

        public function create(CreateGenerationRequest $request): CreatedGeneration
        {
            throw new RuntimeException('worker died');
        }

        public function retrieve(string $id): PixelforgeGeneration
        {
            throw new RuntimeException('worker died');
        }

        public function usage(CarbonImmutable $startDate, CarbonImmutable $endDate): ProviderUsage
        {
            throw new RuntimeException('worker died');
        }
    });

    $generation = Generation::factory()->create();

    try {
        runSubmitJob($generation);
    } catch (RuntimeException) {
        // The queue would now call failed().
    }

    $generation->refresh();

    expect($generation->submit_unconfirmed)->toBeTrue()
        ->and($generation->submit_attempts)->toBe(1);

    (new SubmitGeneration($generation->id))->failed(new RuntimeException('worker died'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('waits without an attempt while enough generations are in flight at the provider', function () {
    config([
        'services.pixelforge.concurrency_limit' => 3,
        'services.pixelforge.concurrency_retry_seconds' => 9,
    ]);

    $client = bindFakePixelforgeClient();
    Generation::factory()->create(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_a']);
    Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_b']);
    Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_c']);
    $generation = Generation::factory()->create();

    $job = runSubmitJob($generation, fakeQueue: true);

    expect($generation->refresh()->submit_attempts)->toBe(0)
        ->and($generation->submit_unconfirmed)->toBeFalse()
        ->and($client->created)->toBeEmpty();

    $job->assertReleased(9);
});

it('does not count settled generations against the in-flight gate', function () {
    config(['services.pixelforge.concurrency_limit' => 3]);

    $client = bindFakePixelforgeClient();
    Generation::factory()->create(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_a']);
    Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_b']);
    Generation::factory()->create(['status' => GenerationStatus::Succeeded, 'provider_id' => 'gen_c']);
    $generation = Generation::factory()->create();

    runSubmitJob($generation, fakeQueue: true);

    expect($client->created)->toHaveCount(1);
});

it('waits on a concurrency 429 without using an attempt', function () {
    config([
        'services.pixelforge.max_submit_attempts' => 1,
        'services.pixelforge.concurrency_retry_seconds' => 7,
    ]);

    $client = bindFakePixelforgeClient();
    $client->failure = PixelforgeException::fromProvider(429, 'concurrency_limit_exceeded', 'Busy.', null, 5);
    $generation = Generation::factory()->create();

    $job = runSubmitJob($generation, fakeQueue: true);
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->submit_attempts)->toBe(0)
        ->and($generation->submit_unconfirmed)->toBeFalse();

    $job->assertReleased(7);
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
