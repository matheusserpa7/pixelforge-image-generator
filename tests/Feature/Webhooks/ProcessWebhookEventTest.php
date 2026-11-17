<?php

declare(strict_types=1);

use App\Data\GenerationRates;
use App\Data\TokenUsage;
use App\Enums\GenerationStatus;
use App\Enums\WebhookEventOutcome;
use App\Jobs\DownloadGenerationImage;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\WebhookEvent;
use App\Services\Billing\ActualCost;
use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([DownloadGenerationImage::class]);
    config(['billing.markup_percent' => '25']);
});

/**
 * @param  array<string, mixed>  $object
 */
function storeEvent(string $type, array $object, string $eventId = 'evt_test_1'): WebhookEvent
{
    return WebhookEvent::query()->create([
        'event_id' => $eventId,
        'type' => $type,
        'payload' => pixelforgeEvent($type, $object, $eventId),
    ]);
}

function processEvent(WebhookEvent $event): WebhookEvent
{
    app()->call([new ProcessWebhookEvent($event->id), 'handle']);

    return $event->refresh();
}

/**
 * @return array<string, string>
 */
function usageObject(): array
{
    return [
        'input_text_tokens' => '100',
        'input_image_tokens' => '765',
        'output_tokens' => '1000',
        'reasoning_tokens' => '200',
    ];
}

it('moves a generation to processing and then charges it once on success', function () {
    $generation = Generation::factory()->create();
    $metadata = ['generation_id' => $generation->id];

    $event = processEvent(storeEvent('generation.processing', pixelforgeGenerationObject([
        'status' => 'processing',
        'metadata' => $metadata,
    ])));

    $generation->refresh();

    expect($event->outcome)->toBe(WebhookEventOutcome::Applied)
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->generation_id)->toBe($generation->id)
        ->and($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->provider_id)->toBe('gen_test_1');

    $succeeded = pixelforgeGenerationObject([
        'status' => 'succeeded',
        'metadata' => $metadata,
        'usage' => usageObject(),
        'image_url' => 'https://example.test/image.png',
        'image_url_expires_at' => '2026-11-17T12:00:00Z',
    ]);

    $event = processEvent(storeEvent('generation.succeeded', $succeeded, 'evt_test_2'));

    $expected = ActualCost::calculate(
        new TokenUsage(100, 765, 1000, 200),
        GenerationRates::fromStoredArray($generation->refresh()->rates ?? []),
        '25',
    );

    expect($event->outcome)->toBe(WebhookEventOutcome::Applied)
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->firstOrFail()->charge_nanos)->toBe($expected->charge->nanos);

    $duplicate = processEvent(storeEvent('generation.succeeded', $succeeded, 'evt_test_3'));

    expect($duplicate->outcome)->toBe(WebhookEventOutcome::Ignored)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('ignores an older status that arrives after a final one', function () {
    $generation = Generation::factory()->create();
    $metadata = ['generation_id' => $generation->id];

    processEvent(storeEvent('generation.succeeded', pixelforgeGenerationObject([
        'status' => 'succeeded',
        'metadata' => $metadata,
        'usage' => usageObject(),
    ])));

    $late = processEvent(storeEvent('generation.processing', pixelforgeGenerationObject([
        'status' => 'processing',
        'metadata' => $metadata,
    ]), 'evt_test_2'));

    expect($late->outcome)->toBe(WebhookEventOutcome::Ignored)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('fails a generation without charging and ignores a later success', function () {
    $generation = Generation::factory()->create();
    $metadata = ['generation_id' => $generation->id];

    $event = processEvent(storeEvent('generation.failed', pixelforgeGenerationObject([
        'status' => 'failed',
        'metadata' => $metadata,
        'error' => ['code' => 'content_policy_violation', 'message' => 'Rejected.'],
    ])));

    $generation->refresh();

    expect($event->outcome)->toBe(WebhookEventOutcome::Applied)
        ->and($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_message)->toBe('The prompt was rejected by the content policy.')
        ->and(LedgerEntry::query()->count())->toBe(0);

    $late = processEvent(storeEvent('generation.succeeded', pixelforgeGenerationObject([
        'status' => 'succeeded',
        'metadata' => $metadata,
        'usage' => usageObject(),
    ]), 'evt_test_2'));

    expect($late->outcome)->toBe(WebhookEventOutcome::Ignored)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('finds the generation by provider id when metadata is missing', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_test_1']);

    $event = processEvent(storeEvent('generation.processing', pixelforgeGenerationObject([
        'status' => 'processing',
    ])));

    expect($event->outcome)->toBe(WebhookEventOutcome::Applied)
        ->and($event->generation_id)->toBe($generation->id)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('records an unknown generation', function () {
    $event = processEvent(storeEvent('generation.processing', pixelforgeGenerationObject([
        'status' => 'processing',
    ])));

    expect($event->outcome)->toBe(WebhookEventOutcome::UnknownGeneration)
        ->and($event->generation_id)->toBeNull()
        ->and($event->processed_at)->not->toBeNull();
});

it('records a ping as not a generation event', function () {
    $event = processEvent(storeEvent('ping', []));

    expect($event->outcome)->toBe(WebhookEventOutcome::NotAGenerationEvent);
});

it('records an invalid payload', function () {
    $generation = Generation::factory()->create();
    $object = pixelforgeGenerationObject(['metadata' => ['generation_id' => $generation->id]]);
    unset($object['rates']);

    $event = processEvent(storeEvent('generation.processing', $object));

    expect($event->outcome)->toBe(WebhookEventOutcome::InvalidPayload)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('records a succeeded snapshot without usage as incomplete', function () {
    $generation = Generation::factory()->create();

    $event = processEvent(storeEvent('generation.succeeded', pixelforgeGenerationObject([
        'status' => 'succeeded',
        'metadata' => ['generation_id' => $generation->id],
        'usage' => null,
    ])));

    expect($event->outcome)->toBe(WebhookEventOutcome::Incomplete)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('does not apply the same event row twice', function () {
    $generation = Generation::factory()->create();
    $event = storeEvent('generation.succeeded', pixelforgeGenerationObject([
        'status' => 'succeeded',
        'metadata' => ['generation_id' => $generation->id],
        'usage' => usageObject(),
    ]));

    processEvent($event);
    $processedAt = $event->refresh()->processed_at;

    $this->travel(1)->minutes();
    $again = processEvent($event);

    expect($again->outcome)->toBe(WebhookEventOutcome::Applied)
        ->and($again->processed_at?->equalTo($processedAt))->toBeTrue()
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('resolves a generation left unconfirmed by a connection failure', function () {
    config(['services.pixelforge.max_submit_attempts' => 1]);

    $client = new FakePixelforgeClient;
    $client->failure = PixelforgeException::connection(new RuntimeException('timeout'));
    app()->instance(PixelforgeClient::class, $client);

    $generation = Generation::factory()->create();
    app()->call([new SubmitGeneration($generation->id), 'handle']);

    expect($generation->refresh()->submit_unconfirmed)->toBeTrue()
        ->and($generation->provider_id)->toBeNull();

    $event = processEvent(storeEvent('generation.succeeded', pixelforgeGenerationObject([
        'status' => 'succeeded',
        'metadata' => ['generation_id' => $generation->id],
        'usage' => usageObject(),
    ])));

    $generation->refresh();
    $rates = GenerationRates::fromStoredArray($generation->rates ?? []);
    $expected = ActualCost::calculate(new TokenUsage(100, 765, 1000, 200), $rates, '25');

    expect($event->outcome)->toBe(WebhookEventOutcome::Applied)
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->firstOrFail()->charge_nanos)->toBe($expected->charge->nanos);
});
