<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Data\CreatedGeneration;
use App\Data\CreateGenerationRequest;
use App\Data\GenerationRates;
use App\Data\PixelforgeGeneration;
use App\Data\PixelforgeGenerationError;
use App\Data\Pricing;
use App\Data\ProviderUsage;
use App\Data\TokenUsage;
use App\Enums\GenerationStatus;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * In-memory Pixelforge for tests: no network, scripted responses.
 */
final class FakePixelforgeClient implements PixelforgeClient
{
    public ?Pricing $pricing = null;

    /**
     * @var list<CreateGenerationRequest>
     */
    public array $created = [];

    /**
     * @var array<string, PixelforgeGeneration>
     */
    public array $generations = [];

    public ?ProviderUsage $usage = null;

    public ?PixelforgeException $usageFailure = null;

    /**
     * @var list<array{start: string, end: string}>
     */
    public array $usageCalls = [];

    public ?PixelforgeException $failure = null;

    public GenerationStatus $createStatus = GenerationStatus::Queued;

    public ?TokenUsage $createUsage = null;

    public ?PixelforgeGenerationError $createError = null;

    public ?GenerationRates $createRates = null;

    /**
     * @var array<string, array{canonical: string, id: string}>
     */
    private array $idempotency = [];

    private int $nextId = 1;

    public function pricing(): Pricing
    {
        $this->throwFailureIfSet();

        return $this->pricing ?? SamplePricing::pricing();
    }

    public function create(CreateGenerationRequest $request): CreatedGeneration
    {
        $this->throwFailureIfSet();

        $this->created[] = $request;

        $canonical = $this->canonicalRequest($request);
        $existing = $this->idempotency[$request->idempotencyKey] ?? null;

        if ($existing !== null) {
            if ($existing['canonical'] !== $canonical) {
                throw PixelforgeException::fromProvider(
                    409,
                    'idempotency_key_reused',
                    'Idempotency key was reused with different parameters.',
                    null,
                    null,
                );
            }

            $generation = $this->generations[$existing['id']] ?? null;

            if ($generation === null) {
                throw PixelforgeException::fromProvider(
                    404,
                    'not_found',
                    'Generation not found.',
                    null,
                    null,
                );
            }

            return new CreatedGeneration($generation, true);
        }

        $pricing = $this->pricing();
        $now = CarbonImmutable::now('UTC');

        $rates = $this->createRates ?? $this->copyRates($pricing->rates);

        $generation = new PixelforgeGeneration(
            id: 'gen_fake_'.$this->nextId,
            status: $this->createStatus,
            prompt: $request->prompt,
            size: $request->size,
            quality: $request->quality,
            referenceImages: $request->referenceImages,
            metadata: $request->metadata,
            imageUrl: null,
            imageUrlExpiresAt: null,
            usage: $this->createUsage,
            rates: $rates,
            error: $this->createError,
            createdAt: $now,
            startedAt: null,
            completedAt: null,
        );

        $this->nextId++;
        $this->generations[$generation->id] = $generation;
        $this->idempotency[$request->idempotencyKey] = [
            'canonical' => $canonical,
            'id' => $generation->id,
        ];

        return new CreatedGeneration($generation, false);
    }

    public function retrieve(string $id): PixelforgeGeneration
    {
        $this->throwFailureIfSet();

        $generation = $this->generations[$id] ?? null;

        if ($generation === null) {
            throw PixelforgeException::fromProvider(
                404,
                'not_found',
                'Generation not found.',
                null,
                null,
            );
        }

        return $generation;
    }

    public function usage(CarbonImmutable $startDate, CarbonImmutable $endDate): ProviderUsage
    {
        $this->usageCalls[] = [
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d'),
        ];

        if ($this->usageFailure !== null) {
            throw $this->usageFailure;
        }

        return $this->usage ?? new ProviderUsage($startDate, $endDate, [], Money::zero());
    }

    private function throwFailureIfSet(): void
    {
        if ($this->failure === null) {
            return;
        }

        $failure = $this->failure;
        $this->failure = null;

        throw $failure;
    }

    private function copyRates(GenerationRates $rates): GenerationRates
    {
        return new GenerationRates(
            $rates->inputText,
            $rates->inputImage,
            $rates->output,
            $rates->reasoning,
        );
    }

    private function canonicalRequest(CreateGenerationRequest $request): string
    {
        $metadata = $request->metadata;
        ksort($metadata);

        $payload = [
            'prompt' => $request->prompt,
            'size' => $request->size->value,
            'quality' => $request->quality->value,
            'reference_images' => $request->referenceImages,
            'metadata' => $metadata,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
