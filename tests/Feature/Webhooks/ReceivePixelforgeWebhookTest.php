<?php

declare(strict_types=1);

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
});

function postWebhook(string $body, ?string $signature): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($signature !== null) {
        $server['HTTP_PIXELFORGE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/webhooks/pixelforge', [], [], [], $server, $body);
}

it('records a signed event and dispatches processing', function () {
    $body = pixelforgeEvent('generation.processing', pixelforgeGenerationObject());

    postWebhook($body, signPixelforge($body))
        ->assertOk()
        ->assertExactJson(['received' => true]);

    $event = WebhookEvent::query()->sole();

    expect($event->event_id)->toBe('evt_test_1')
        ->and($event->type)->toBe('generation.processing')
        ->and($event->payload)->toBe($body)
        ->and($event->outcome)->toBeNull();

    Queue::assertPushed(ProcessWebhookEvent::class, 1);
    Queue::assertPushed(ProcessWebhookEvent::class, fn (ProcessWebhookEvent $job) => $job->webhookEventId === $event->id);
});

it('acknowledges a duplicate without storing or dispatching again', function () {
    $body = pixelforgeEvent('generation.processing', pixelforgeGenerationObject());

    postWebhook($body, signPixelforge($body))->assertOk();
    postWebhook($body, signPixelforge($body, time() + 5))
        ->assertOk()
        ->assertExactJson(['received' => true]);

    expect(WebhookEvent::query()->count())->toBe(1);
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

it('refuses a missing, bad or stale signature', function (?string $signature) {
    $body = pixelforgeEvent('ping', []);

    postWebhook($body, $signature)
        ->assertUnauthorized()
        ->assertExactJson(['error' => 'invalid_signature']);

    expect(WebhookEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'missing header' => [null],
    'bad signature' => ['t='.time().',v1='.str_repeat('a', 64)],
    'stale timestamp' => fn () => signPixelforge(pixelforgeEvent('ping', []), time() - 301),
]);

it('refuses everything when no secret is configured', function () {
    config(['services.pixelforge.webhook_secret' => '']);
    $body = pixelforgeEvent('ping', []);

    Log::spy();

    postWebhook($body, signPixelforge($body, secret: ''))
        ->assertUnauthorized()
        ->assertExactJson(['error' => 'invalid_signature']);

    expect(WebhookEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('error')->with('Pixelforge webhook secret is not configured.')->once();
});

it('refuses a signed body that is not a readable event', function (string $body) {
    postWebhook($body, signPixelforge($body))
        ->assertStatus(400)
        ->assertExactJson(['error' => 'malformed_payload']);

    expect(WebhookEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'not json' => ['not json'],
    'empty object' => ['{}'],
    'id too long' => fn () => json_encode(['id' => str_repeat('e', 256), 'type' => 'ping']),
    'type too long' => fn () => json_encode(['id' => 'evt_1', 'type' => str_repeat('t', 65)]),
]);

it('accepts and stores a ping', function () {
    $body = json_encode(['id' => 'evt_ping', 'type' => 'ping']);

    postWebhook($body, signPixelforge($body))->assertOk();

    expect(WebhookEvent::query()->sole()->type)->toBe('ping');
});
