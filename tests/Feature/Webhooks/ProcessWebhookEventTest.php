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
 * Token counts as integers, the way Pixelforge sends them (JSON numbers).
 *
 * @return array<string, int>
 */
function usageObject(): array
{
    return [
        'input_text_tokens' => 100,
        'input_image_tokens' => 765,
        'output_tokens' => 1000,
        'reasoning_tokens' => 200,
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

/**
 * The production payload (event evt_01M7MRN5ZWE111HMGRXFNBW6HY) with numbers as JSON numbers.
 */
function productionSucceededPayload(string $generationId, string $eventId): string
{
    return '{"id":"'.$eventId.'","type":"generation.succeeded","data":{"object":{'
        .'"id":"gen_test_1","object":"generation","status":"succeeded","prompt":"A lighthouse at dusk",'
        .'"size":"1024x1024","quality":"medium","reference_images":[],'
        .'"metadata":{"generation_id":"'.$generationId.'"},'
        .'"image_url":"https://example.test/image.png","image_url_expires_at":"2026-11-17T12:00:00Z",'
        .'"usage":{"input_text_tokens":17,"input_image_tokens":0,"output_tokens":1100,"reasoning_tokens":1819,"total_tokens":2936},'
        .'"rates":{"input_text_tokens":"5.00","input_image_tokens":"10.00","output_tokens":"40.00","reasoning_tokens":"12.50"},'
        .'"error":null,"created_at":"2026-11-17T12:00:00Z","started_at":"2026-11-17T12:00:01Z","completed_at":"2026-11-17T12:00:09Z"'
        .'}}}';
}

it('applies a production-shaped succeeded payload with numeric token counts', function () {
    $generation = Generation::factory()->create();

    $event = processEvent(WebhookEvent::query()->create([
        'event_id' => 'evt_prod_1',
        'type' => 'generation.succeeded',
        'payload' => productionSucceededPayload($generation->id, 'evt_prod_1'),
    ]));
    $entry = LedgerEntry::query()->firstOrFail();

    expect($event->outcome)->toBe(WebhookEventOutcome::Applied)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and($entry->provider_cost_nanos)->toBe(66822500)
        ->and($entry->charge_nanos)->toBe(83528125);

    Queue::assertPushed(DownloadGenerationImage::class);
});

it('charges once when the same production payload arrives under another event id', function () {
    $generation = Generation::factory()->create();

    foreach (['evt_prod_1', 'evt_prod_2'] as $eventId) {
        processEvent(WebhookEvent::query()->create([
            'event_id' => $eventId,
            'type' => 'generation.succeeded',
            'payload' => productionSucceededPayload($generation->id, $eventId),
        ]));
    }

    expect(LedgerEntry::query()->count())->toBe(1);
});

it('logs an unparseable generation payload at error level', function () {
    Log::spy();

    $object = pixelforgeGenerationObject();
    unset($object['rates']);

    $event = processEvent(storeEvent('generation.processing', $object, 'evt_bad_1'));

    expect($event->outcome)->toBe(WebhookEventOutcome::InvalidPayload);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => ($context['event_id'] ?? null) === 'evt_bad_1')
        ->once();
});

it('refuses a succeeded snapshot whose total_tokens does not match the priced types', function () {
    $generation = Generation::factory()->create();

    $event = processEvent(storeEvent('generation.succeeded', pixelforgeGenerationObject([
        'status' => 'succeeded',
        'metadata' => ['generation_id' => $generation->id],
        'usage' => [...usageObject(), 'total_tokens' => 1_366],
    ])));

    expect($event->outcome)->toBe(WebhookEventOutcome::InvalidPayload)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});
