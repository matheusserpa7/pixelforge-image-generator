<?php

declare(strict_types=1);

use App\Data\CreateGenerationRequest;
use App\Data\PixelforgeGeneration;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\FakePixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;

it('returns the published sample pricing by default', function () {
    $pricing = (new FakePixelforgeClient)->pricing();

    expect($pricing->textTokenVariance)->toBe('0.15')
        ->and($pricing->charactersPerTextToken)->toBe(4)
        ->and($pricing->tokensPerReferenceImage)->toBe(765)
        ->and($pricing->rates->inputText->nanos)->toBe(5000)
        ->and($pricing->rates->inputImage->nanos)->toBe(10000)
        ->and($pricing->rates->output->nanos)->toBe(40000)
        ->and($pricing->rates->reasoning->nanos)->toBe(12500)
        ->and($pricing->outputTokens['high']['1536x1024'])->toBe(6450)
        ->and($pricing->reasoningTokens['high'])->toBe(['min' => 1000, 'max' => 8000]);
});

it('creates then retrieves a generation', function () {
    $client = new FakePixelforgeClient;

    $request = new CreateGenerationRequest(
        prompt: 'A red balloon',
        size: ImageSize::Square,
        quality: ImageQuality::Medium,
        referenceImages: [],
        metadata: [],
        idempotencyKey: 'idem-1',
    );

    $created = $client->create($request);

    expect($created->replayed)->toBeFalse()
        ->and($created->generation->status)->toBe(GenerationStatus::Queued);

    $retrieved = $client->retrieve($created->generation->id);

    expect($retrieved->prompt)->toBe('A red balloon');
});

it('replays an idempotent create', function () {
    $client = new FakePixelforgeClient;

    $request = new CreateGenerationRequest(
        prompt: 'Same',
        size: ImageSize::Landscape,
        quality: ImageQuality::High,
        referenceImages: ['https://example.com/a.png'],
        metadata: ['k' => 'v'],
        idempotencyKey: 'idem-replay',
    );

    $first = $client->create($request);
    $mutated = $first->generation;
    $client->generations[$mutated->id] = new PixelforgeGeneration(
        id: $mutated->id,
        status: GenerationStatus::Processing,
        prompt: $mutated->prompt,
        size: $mutated->size,
        quality: $mutated->quality,
        referenceImages: $mutated->referenceImages,
        metadata: $mutated->metadata,
        imageUrl: $mutated->imageUrl,
        imageUrlExpiresAt: $mutated->imageUrlExpiresAt,
        usage: $mutated->usage,
        rates: $mutated->rates,
        error: $mutated->error,
        createdAt: $mutated->createdAt,
        startedAt: $mutated->startedAt,
        completedAt: $mutated->completedAt,
    );
    $second = $client->create($request);

    expect($second->replayed)->toBeTrue()
        ->and($second->generation->id)->toBe($first->generation->id)
        ->and($second->generation->status)->toBe(GenerationStatus::Processing)
        ->and($client->retrieve($first->generation->id)->status)->toBe(GenerationStatus::Processing);
});

it('treats the same metadata as equal when the key order differs', function () {
    $client = new FakePixelforgeClient;

    $first = $client->create(new CreateGenerationRequest(
        prompt: 'Same',
        size: ImageSize::Square,
        quality: ImageQuality::Low,
        referenceImages: [],
        metadata: ['b' => '2', 'a' => '1'],
        idempotencyKey: 'meta-order',
    ));

    $second = $client->create(new CreateGenerationRequest(
        prompt: 'Same',
        size: ImageSize::Square,
        quality: ImageQuality::Low,
        referenceImages: [],
        metadata: ['a' => '1', 'b' => '2'],
        idempotencyKey: 'meta-order',
    ));

    expect($second->replayed)->toBeTrue()
        ->and($second->generation->id)->toBe($first->generation->id);
});

it('rejects idempotency key reuse with different parameters', function () {
    $client = new FakePixelforgeClient;

    $client->create(new CreateGenerationRequest(
        prompt: 'First',
        size: ImageSize::Square,
        quality: ImageQuality::Low,
        referenceImages: ['https://example.com/a.png', 'https://example.com/b.png'],
        metadata: [],
        idempotencyKey: 'shared-key',
    ));

    try {
        $client->create(new CreateGenerationRequest(
            prompt: 'First',
            size: ImageSize::Square,
            quality: ImageQuality::Low,
            referenceImages: ['https://example.com/b.png', 'https://example.com/a.png'],
            metadata: [],
            idempotencyKey: 'shared-key',
        ));
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->status)->toBe(409)
            ->and($exception->errorCode)->toBe('idempotency_key_reused')
            ->and($exception->retryable)->toBeFalse()
            ->and($client->generations)->toHaveCount(1);
    }
});

it('rejects a changed prompt for the same idempotency key', function () {
    $client = new FakePixelforgeClient;

    $client->create(new CreateGenerationRequest(
        prompt: 'First',
        size: ImageSize::Square,
        quality: ImageQuality::Low,
        referenceImages: [],
        metadata: [],
        idempotencyKey: 'shared-key',
    ));

    try {
        $client->create(new CreateGenerationRequest(
            prompt: 'Changed',
            size: ImageSize::Square,
            quality: ImageQuality::Low,
            referenceImages: [],
            metadata: [],
            idempotencyKey: 'shared-key',
        ));
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->status)->toBe(409)
            ->and($exception->errorCode)->toBe('idempotency_key_reused')
            ->and($exception->retryable)->toBeFalse();
    }
});

it('returns 404 for an unknown id', function () {
    try {
        (new FakePixelforgeClient)->retrieve('gen_missing');
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->status)->toBe(404)
            ->and($exception->errorCode)->toBe('not_found')
            ->and($exception->retryable)->toBeFalse();
    }
});

it('throws a configured failure once', function () {
    $client = new FakePixelforgeClient;
    $client->failure = PixelforgeException::fromProvider(503, 'overloaded', 'Busy.', null, 2);

    expect(fn () => $client->pricing())->toThrow(PixelforgeException::class)
        ->and($client->pricing()->textTokenVariance)->toBe('0.15');
});
