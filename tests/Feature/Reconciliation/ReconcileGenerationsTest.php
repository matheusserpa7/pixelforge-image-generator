<?php

declare(strict_types=1);

use App\Actions\ReconcileGenerations;
use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(now()->startOfSecond());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function staleGeneration(array $attributes, int $secondsAgo): Generation
{
    $generation = Generation::factory()->create($attributes);
    Generation::query()->whereKey($generation->id)->update(['updated_at' => now()->subSeconds($secondsAgo)]);

    return $generation->refresh();
}

it('polls a stale processing generation once and touches it', function () {
    $generation = staleGeneration(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_1'], 121);

    app(ReconcileGenerations::class)->handle();

    Queue::assertPushed(PollGeneration::class, fn ($job) => $job->generationId === $generation->id);
    expect($generation->refresh()->updated_at->equalTo(now()))->toBeTrue();

    app(ReconcileGenerations::class)->handle();

    Queue::assertPushed(PollGeneration::class, 1);
});

it('skips young, final and not-yet-submitted rows', function () {
    staleGeneration(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_1'], 60);
    staleGeneration(['status' => GenerationStatus::Succeeded, 'provider_id' => 'gen_2'], 1000);
    staleGeneration(['status' => GenerationStatus::Failed, 'provider_id' => 'gen_3'], 1000);
    staleGeneration(['status' => GenerationStatus::Queued, 'provider_id' => null], 599);

    app(ReconcileGenerations::class)->handle();

    Queue::assertNothingPushed();
});

it('resubmits a queued generation without provider id after ten minutes', function () {
    $generation = staleGeneration(['status' => GenerationStatus::Queued, 'provider_id' => null], 601);

    app(ReconcileGenerations::class)->handle();

    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
    Queue::assertNotPushed(PollGeneration::class);
    expect($generation->refresh()->updated_at->equalTo(now()))->toBeTrue();

    app(ReconcileGenerations::class)->handle();

    Queue::assertPushed(SubmitGeneration::class, 1);
});

it('does not resubmit a queued generation whose job is waiting', function () {
    $generation = staleGeneration(['status' => GenerationStatus::Queued, 'provider_id' => null], 601);
    Generation::query()->whereKey($generation->id)->update(['updated_at' => now()->subSeconds(30)]);

    app(ReconcileGenerations::class)->handle();

    Queue::assertNotPushed(SubmitGeneration::class);
});

it('polls a stale queued generation that already has a provider id', function () {
    $generation = staleGeneration(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_1'], 121);

    expect(app(ReconcileGenerations::class)->handle())->toBe(['polled' => 1, 'resubmitted' => 0, 'stuck' => 0, 'webhooks' => 0]);

    Queue::assertPushed(PollGeneration::class, fn ($job) => $job->generationId === $generation->id);
    Queue::assertNotPushed(SubmitGeneration::class);
});

it('dispatches at most twenty per sweep', function () {
    for ($i = 0; $i < 25; $i++) {
        staleGeneration(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_'.$i], 500);
    }

    app(ReconcileGenerations::class)->handle();

    Queue::assertPushed(PollGeneration::class, 20);
});

it('falls back to 120 seconds when the threshold is not usable', function () {
    config(['services.pixelforge.reconcile_after_seconds' => 'abc']);
    expect(ReconcileGenerations::reconcileAfterSeconds())->toBe(120);

    config(['services.pixelforge.reconcile_after_seconds' => '0']);
    expect(ReconcileGenerations::reconcileAfterSeconds())->toBe(120);

    config(['services.pixelforge.reconcile_after_seconds' => '30']);
    expect(ReconcileGenerations::reconcileAfterSeconds())->toBe(30);
});

it('schedules the command every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'pixelforge:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('runs through the artisan command', function () {
    staleGeneration(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_1'], 500);

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Queue::assertPushed(PollGeneration::class, 1);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function inFlightCreatedMinutesAgo(int $minutes, array $attributes = []): Generation
{
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Processing,
        'provider_id' => 'gen_'.fake()->unique()->numberBetween(1, 99999),
        ...$attributes,
    ]);
    Generation::query()->whereKey($generation->id)->update([
        'created_at' => now()->subMinutes($minutes),
        'updated_at' => now()->subSeconds(30),
    ]);

    return $generation->refresh();
}

it('reports a generation in flight for over an hour once and never fails it', function () {
    Log::spy();
    $stuck = inFlightCreatedMinutesAgo(61);
    $recent = inFlightCreatedMinutesAgo(59);
    $final = inFlightCreatedMinutesAgo(61, ['status' => GenerationStatus::Succeeded]);
    $unsubmitted = inFlightCreatedMinutesAgo(61, ['status' => GenerationStatus::Queued, 'provider_id' => null]);

    $counts = app(ReconcileGenerations::class)->handle();

    expect($counts['stuck'])->toBe(1)
        ->and($stuck->refresh()->stuck_reported_at)->not->toBeNull()
        ->and($stuck->status)->toBe(GenerationStatus::Processing)
        ->and($stuck->updated_at->equalTo(now()->subSeconds(30)))->toBeTrue()
        ->and($recent->refresh()->stuck_reported_at)->toBeNull()
        ->and($final->refresh()->stuck_reported_at)->toBeNull()
        ->and($unsubmitted->refresh()->stuck_reported_at)->toBeNull();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => $message === 'Pixelforge generation stuck.'
            && $context['generation_id'] === $stuck->id
            && $context['provider_id'] === $stuck->provider_id)
        ->once();

    app(ReconcileGenerations::class)->handle();

    Log::shouldHaveReceived('error')->once();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function webhookCreatedSecondsAgo(int $seconds, array $attributes = []): WebhookEvent
{
    static $n = 0;
    $n++;

    $event = WebhookEvent::query()->create([
        'event_id' => 'evt_sweep_'.$n,
        'type' => 'generation.processing',
        'payload' => '{}',
    ]);
    WebhookEvent::query()->whereKey($event->id)->update([
        'created_at' => now()->subSeconds($seconds),
        'updated_at' => now()->subSeconds($seconds),
        ...$attributes,
    ]);

    return $event->refresh();
}

it('redispatches an unprocessed webhook event at most every five minutes', function () {
    $lost = webhookCreatedSecondsAgo(360);

    $counts = app(ReconcileGenerations::class)->handle();

    expect($counts['webhooks'])->toBe(1)
        ->and($lost->refresh()->updated_at->equalTo(now()))->toBeTrue();

    Queue::assertPushed(ProcessWebhookEvent::class, fn ($job) => $job->webhookEventId === $lost->id);

    app(ReconcileGenerations::class)->handle();

    Queue::assertPushed(ProcessWebhookEvent::class, 1);

    $this->travel(301)->seconds();
    app(ReconcileGenerations::class)->handle();

    Queue::assertPushed(ProcessWebhookEvent::class, 2);
});

it('skips young, ancient and processed webhook events', function () {
    webhookCreatedSecondsAgo(120);
    webhookCreatedSecondsAgo(2 * 86400);
    webhookCreatedSecondsAgo(360, ['processed_at' => now()]);

    $counts = app(ReconcileGenerations::class)->handle();

    expect($counts['webhooks'])->toBe(0);
    Queue::assertNotPushed(ProcessWebhookEvent::class);
});
