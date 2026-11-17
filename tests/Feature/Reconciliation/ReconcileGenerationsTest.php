<?php

declare(strict_types=1);

use App\Actions\ReconcileGenerations;
use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
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

it('polls a stale queued generation that already has a provider id', function () {
    $generation = staleGeneration(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_1'], 121);

    expect(app(ReconcileGenerations::class)->handle())->toBe(['polled' => 1, 'resubmitted' => 0]);

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
