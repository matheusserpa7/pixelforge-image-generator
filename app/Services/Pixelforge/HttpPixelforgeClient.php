<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Data\CreatedGeneration;
use App\Data\CreateGenerationRequest;
use App\Data\PixelforgeGeneration;
use App\Data\Pricing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class HttpPixelforgeClient implements PixelforgeClient
{
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function pricing(): Pricing
    {
        return $this->withConnectionHandling(fn (): Pricing => $this->decodePricing(
            $this->request()->get($this->baseUrl.'/pricing'),
        ));
    }

    public function create(CreateGenerationRequest $request): CreatedGeneration
    {
        $this->assertIdempotencyKey($request->idempotencyKey);

        $body = [
            'prompt' => $request->prompt,
            'size' => $request->size->value,
            'quality' => $request->quality->value,
        ];

        if ($request->referenceImages !== []) {
            $body['reference_images'] = $request->referenceImages;
        }

        if ($request->metadata !== []) {
            $body['metadata'] = $request->metadata;
        }

        return $this->withConnectionHandling(function () use ($request, $body): CreatedGeneration {
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => $request->idempotencyKey])
                ->post($this->baseUrl.'/generations', $body);

            $replayed = strtolower($response->header('Idempotent-Replayed')) === 'true';

            return new CreatedGeneration(
                $this->decodeGeneration($response),
                $replayed,
            );
        });
    }

    public function retrieve(string $id): PixelforgeGeneration
    {
        return $this->withConnectionHandling(fn (): PixelforgeGeneration => $this->decodeGeneration(
            $this->request()->get($this->baseUrl.'/generations/'.rawurlencode($id)),
        ));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withConnectionHandling(callable $callback)
    {
        try {
            return $callback();
        } catch (ConnectionException $exception) {
            throw PixelforgeException::connection($exception);
        }
    }

    private function assertIdempotencyKey(string $key): void
    {
        if ($key === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('Idempotency key must be between 1 and 255 characters.');
        }
    }

    /**
     * @return PendingRequest
     */
    private function request()
    {
        $pending = Http::acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->timeout);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $pending = $pending->withToken($this->apiKey);
        }

        return $pending;
    }

    private function decodePricing(Response $response): Pricing
    {
        if (! $response->successful()) {
            throw $this->providerException($response);
        }

        try {
            $decoded = DecimalJson::decode($response->body());

            return Pricing::fromDecoded($decoded);
        } catch (InvalidArgumentException $exception) {
            throw PixelforgeException::invalidResponse(
                $exception->getMessage(),
                $response->status(),
                $exception,
            );
        }
    }

    private function decodeGeneration(Response $response): PixelforgeGeneration
    {
        if (! $response->successful()) {
            throw $this->providerException($response);
        }

        try {
            $decoded = DecimalJson::decode($response->body());

            return PixelforgeGeneration::fromDecoded($decoded);
        } catch (InvalidArgumentException $exception) {
            throw PixelforgeException::invalidResponse(
                $exception->getMessage(),
                $response->status(),
                $exception,
            );
        }
    }

    private function providerException(Response $response): PixelforgeException
    {
        $retryAfter = $this->parseRetryAfter($response->header('Retry-After'));

        try {
            $decoded = DecimalJson::decode($response->body());
        } catch (Throwable) {
            return PixelforgeException::fromProvider(
                $response->status(),
                'unknown_error',
                $response->reason() ?: 'Unknown error.',
                null,
                $retryAfter,
            );
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'unknown_error';
        $message = is_string($error['message'] ?? null) ? $error['message'] : ($response->reason() ?: 'Unknown error.');
        $param = is_string($error['param'] ?? null) ? $error['param'] : null;

        return PixelforgeException::fromProvider(
            $response->status(),
            $code,
            $message,
            $param,
            $retryAfter,
        );
    }

    private function parseRetryAfter(?string $header): ?int
    {
        if ($header === null || ! preg_match('/^\d+$/', $header)) {
            return null;
        }

        return (int) $header;
    }
}
