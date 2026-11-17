<?php

declare(strict_types=1);

use App\Data\CreateGenerationRequest;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\HttpPixelforgeClient;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\SamplePricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.api_key' => 'test-key',
        'services.pixelforge.timeout' => 10,
    ]);
});

function generationJson(?array $usage = null): string
{
    $usageFragment = $usage === null
        ? 'null'
        : json_encode($usage, JSON_THROW_ON_ERROR);

    return <<<JSON
{
  "object": "generation",
  "id": "gen_test_1",
  "status": "queued",
  "prompt": "A cat",
  "size": "1024x1024",
  "quality": "medium",
  "reference_images": [],
  "metadata": {},
  "image_url": null,
  "image_url_expires_at": null,
  "usage": {$usageFragment},
  "rates": {
    "input_text_tokens": "5.00",
    "input_image_tokens": "10.00",
    "output_tokens": "40.00",
    "reasoning_tokens": "12.50"
  },
  "error": null,
  "created_at": "2026-11-04T14:03:11Z",
  "started_at": null,
  "completed_at": null
}
JSON;
}

function client(): HttpPixelforgeClient
{
    $resolved = app(PixelforgeClient::class);

    expect($resolved)->toBeInstanceOf(HttpPixelforgeClient::class);

    return $resolved;
}

it('fetches pricing with bearer auth when a key is configured', function () {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(SamplePricing::JSON, 200),
    ]);

    $pricing = client()->pricing();

    expect($pricing->rates->inputText->nanos)->toBe(5000)
        ->and($pricing->rates->inputImage->nanos)->toBe(10000)
        ->and($pricing->rates->output->nanos)->toBe(40000)
        ->and($pricing->rates->reasoning->nanos)->toBe(12500)
        ->and($pricing->textTokenVariance)->toBe('0.15');

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.test/v1/pricing'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->hasHeader('Accept', 'application/json');
    });
});

it('fetches pricing without authorization when the api key is empty', function () {
    config(['services.pixelforge.api_key' => '']);
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(SamplePricing::JSON, 200),
    ]);

    client()->pricing();

    Http::assertSent(function ($request) {
        return ! $request->hasHeader('Authorization');
    });
});

it('creates a generation with idempotency and omits empty optional fields', function () {
    Http::fake([
        'https://pixelforge.test/v1/generations' => Http::response(generationJson(), 202),
    ]);

    $created = client()->create(new CreateGenerationRequest(
        prompt: 'A cat',
        size: ImageSize::Square,
        quality: ImageQuality::Medium,
        referenceImages: [],
        metadata: [],
        idempotencyKey: 'idem-abc',
    ));

    expect($created->replayed)->toBeFalse();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://pixelforge.test/v1/generations'
            && $request->hasHeader('Idempotency-Key', 'idem-abc')
            && $request->hasHeader('Accept', 'application/json')
            && ($body['prompt'] ?? null) === 'A cat'
            && ($body['size'] ?? null) === '1024x1024'
            && ($body['quality'] ?? null) === 'medium'
            && array_keys($body) === ['prompt', 'size', 'quality'];
    });
});

it('sends reference images and metadata when they are present', function () {
    Http::fake([
        'https://pixelforge.test/v1/generations' => Http::response(generationJson(), 202),
    ]);

    client()->create(new CreateGenerationRequest(
        prompt: 'A cat',
        size: ImageSize::Landscape,
        quality: ImageQuality::High,
        referenceImages: ['https://example.com/a.png'],
        metadata: ['generation_id' => '01TEST'],
        idempotencyKey: 'idem-full',
    ));

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['reference_images'] ?? null) === ['https://example.com/a.png']
            && ($body['metadata'] ?? null) === ['generation_id' => '01TEST']
            && ($body['size'] ?? null) === '1536x1024'
            && ($body['quality'] ?? null) === 'high'
            && array_keys($body) === ['prompt', 'size', 'quality', 'reference_images', 'metadata'];
    });
});

it('marks create as replayed when Idempotent-Replayed is true', function () {
    Http::fake([
        'https://pixelforge.test/v1/generations' => Http::response(
            generationJson(),
            202,
            ['Idempotent-Replayed' => 'TRUE'],
        ),
    ]);

    $created = client()->create(new CreateGenerationRequest(
        prompt: 'A cat',
        size: ImageSize::Square,
        quality: ImageQuality::Medium,
        referenceImages: [],
        metadata: [],
        idempotencyKey: 'idem-replay',
    ));

    expect($created->replayed)->toBeTrue();
});

it('retrieves a generation with usage and null usage', function () {
    Http::fake([
        'https://pixelforge.test/v1/generations/gen_with_usage' => Http::response(
            generationJson([
                'input_text_tokens' => 10,
                'input_image_tokens' => 0,
                'output_tokens' => 100,
                'reasoning_tokens' => 50,
                'total_tokens' => 160,
            ]),
            200,
        ),
        'https://pixelforge.test/v1/generations/gen_no_usage' => Http::response(generationJson(), 200),
    ]);

    $withUsage = client()->retrieve('gen_with_usage');
    $withoutUsage = client()->retrieve('gen_no_usage');

    expect($withUsage->usage?->output)->toBe(100)
        ->and($withoutUsage->usage)->toBeNull()
        ->and($withUsage->rates->inputText->nanos)->toBe(5000);
});

it('classifies retryable provider errors', function (int $status, string $code, ?string $retryAfter, bool $retryable) {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(
            ['error' => ['type' => 'x', 'code' => $code, 'message' => 'Limited.']],
            $status,
            $retryAfter !== null ? ['Retry-After' => $retryAfter] : [],
        ),
    ]);

    try {
        client()->pricing();
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->retryable)->toBe($retryable)
            ->and($exception->errorCode)->toBe($code)
            ->and($exception->retryAfterSeconds)->toBe($retryAfter !== null ? (int) $retryAfter : null);
    }
})->with([
    'rate limit' => [429, 'rate_limit_exceeded', '7', true],
    'concurrency' => [429, 'concurrency_limit_exceeded', '5', true],
    'daily limit' => [429, 'daily_limit_exceeded', '60', false],
    'overloaded' => [503, 'overloaded', '2', true],
    'retry after zero' => [429, 'rate_limit_exceeded', '0', true],
]);

it('keeps validation param and non-retryable client errors', function (int $status, string $code, ?string $param) {
    $error = [
        'type' => 'invalid_request_error',
        'code' => $code,
        'message' => 'Bad.',
    ];

    if ($param !== null) {
        $error['param'] = $param;
    }

    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(['error' => $error], $status),
    ]);

    try {
        client()->pricing();
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->retryable)->toBeFalse()
            ->and($exception->param)->toBe($param);
    }
})->with([
    [422, 'validation_failed', 'size'],
    [409, 'idempotency_key_reused', null],
    [401, 'invalid_api_key', null],
    [400, 'content_policy_violation', null],
]);

it('wraps connection failures as retryable', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    try {
        client()->pricing();
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->retryable)->toBeTrue()
            ->and($exception->errorCode)->toBe('connection_failed')
            ->and($exception->retryAfterSeconds)->toBeNull();
    }
});

it('rejects a non-json success body', function () {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response('not-json', 200),
    ]);

    try {
        client()->pricing();
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->retryable)->toBeFalse()
            ->and($exception->errorCode)->toBe('invalid_response');
    }
});

it('treats a 500 with a non-json body as a retryable unknown error', function () {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response('boom', 500),
    ]);

    try {
        client()->pricing();
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->retryable)->toBeTrue()
            ->and($exception->status)->toBe(500)
            ->and($exception->errorCode)->toBe('unknown_error');
    }
});

it('ignores a Retry-After HTTP date', function () {
    Http::fake([
        'https://pixelforge.test/v1/pricing' => Http::response(
            ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Limited.']],
            429,
            ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT'],
        ),
    ]);

    try {
        client()->pricing();
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->retryable)->toBeTrue()
            ->and($exception->errorCode)->toBe('rate_limit_exceeded')
            ->and($exception->retryAfterSeconds)->toBeNull();
    }
});

it('rejects an empty idempotency key before sending HTTP', function () {
    Http::fake();

    expect(fn () => client()->create(new CreateGenerationRequest(
        prompt: 'A cat',
        size: ImageSize::Square,
        quality: ImageQuality::Medium,
        referenceImages: [],
        metadata: [],
        idempotencyKey: '',
    )))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('rejects an idempotency key longer than 255 characters before sending HTTP', function () {
    Http::fake();

    expect(fn () => client()->create(new CreateGenerationRequest(
        prompt: 'A cat',
        size: ImageSize::Square,
        quality: ImageQuality::Medium,
        referenceImages: [],
        metadata: [],
        idempotencyKey: str_repeat('a', 256),
    )))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('sends an idempotency key of 255 characters', function () {
    Http::fake([
        'https://pixelforge.test/v1/generations' => Http::response(generationJson(), 202),
    ]);

    $key = str_repeat('b', 255);

    client()->create(new CreateGenerationRequest(
        prompt: 'A cat',
        size: ImageSize::Square,
        quality: ImageQuality::Medium,
        referenceImages: [],
        metadata: [],
        idempotencyKey: $key,
    ));

    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $key));
});

it('fetches usage with the date range and bearer auth', function () {
    Http::fake([
        'https://pixelforge.test/v1/usage*' => Http::response(
            '{"object":"usage","start_date":"2026-11-01","end_date":"2026-11-02",'
            .'"data":[{"date":"2026-11-01","generations":3,"input_text_tokens":41,"cost_usd":"0.169080000"},'
            .'{"date":"2026-11-02","generations":0,"input_text_tokens":0,"cost_usd":"0.000000000"}],'
            .'"total":{"generations":3,"input_text_tokens":41,"cost_usd":"0.169080000"}}',
            200,
        ),
    ]);

    $usage = client()->usage(
        CarbonImmutable::parse('2026-11-01', 'UTC'),
        CarbonImmutable::parse('2026-11-02', 'UTC'),
    );

    expect($usage->costByDate['2026-11-01']->nanos)->toBe(169_080_000)
        ->and($usage->total->nanos)->toBe(169_080_000);

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://pixelforge.test/v1/usage?')
            && $request['start_date'] === '2026-11-01'
            && $request['end_date'] === '2026-11-02'
            && $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

it('maps a usage 422 date_range_too_long to a PixelforgeException', function () {
    Http::fake([
        'https://pixelforge.test/v1/usage*' => Http::response(
            '{"error":{"type":"invalid_request_error","code":"date_range_too_long","message":"Too long.","param":null}}',
            422,
        ),
    ]);

    try {
        client()->usage(CarbonImmutable::parse('2026-01-01', 'UTC'), CarbonImmutable::parse('2026-11-01', 'UTC'));
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->status)->toBe(422)->and($exception->errorCode)->toBe('date_range_too_long');
    }
});

it('maps a usage 5xx to a retryable PixelforgeException', function () {
    Http::fake(['https://pixelforge.test/v1/usage*' => Http::response('', 503)]);

    try {
        client()->usage(CarbonImmutable::parse('2026-11-01', 'UTC'), CarbonImmutable::parse('2026-11-02', 'UTC'));
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->status)->toBe(503)->and($exception->retryable)->toBeTrue();
    }
});

it('rejects a malformed usage body as an invalid response', function () {
    Http::fake(['https://pixelforge.test/v1/usage*' => Http::response('{"object":"usage"}', 200)]);

    try {
        client()->usage(CarbonImmutable::parse('2026-11-01', 'UTC'), CarbonImmutable::parse('2026-11-02', 'UTC'));
        test()->fail('Expected PixelforgeException.');
    } catch (PixelforgeException $exception) {
        expect($exception->errorCode)->toBe('invalid_response');
    }
});
