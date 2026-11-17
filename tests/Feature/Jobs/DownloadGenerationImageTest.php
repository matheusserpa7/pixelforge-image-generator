<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const FAKE_IMAGE_URL = 'https://images.example.test/gen.png';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['filesystems.generation_images_disk' => 'local']);
    Storage::fake('local');
});

function pngBody(int $extraBytes = 16): string
{
    return DownloadGenerationImage::PNG_SIGNATURE.str_repeat('x', $extraBytes);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function succeededWithImage(array $overrides = []): Generation
{
    return Generation::factory()->create(array_merge([
        'status' => GenerationStatus::Succeeded,
        'image_url' => FAKE_IMAGE_URL,
        'image_url_expires_at' => now()->addHours(2),
    ], $overrides));
}

function runDownload(Generation $generation): void
{
    (new DownloadGenerationImage($generation->id))->handle();
}

it('stores the image and sets image_path, then does nothing on a second run', function () {
    $generation = succeededWithImage();
    $body = pngBody();
    Http::fake([FAKE_IMAGE_URL => Http::response($body, 200)]);

    runDownload($generation);

    Storage::disk('local')->assertExists("generations/{$generation->id}.png");
    expect(Storage::disk('local')->get("generations/{$generation->id}.png"))->toBe($body)
        ->and($generation->fresh()->image_path)->toBe("generations/{$generation->id}.png");

    runDownload($generation);

    Http::assertSentCount(1);
});

it('sends no API key with the download request', function () {
    $generation = succeededWithImage();
    Http::fake([FAKE_IMAGE_URL => Http::response(pngBody(), 200)]);

    runDownload($generation);

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('sends no request when the provider url already expired', function () {
    $generation = succeededWithImage(['image_url_expires_at' => now()->subMinute()]);
    Http::fake();

    runDownload($generation);

    Http::assertNothingSent();
    Storage::disk('local')->assertMissing("generations/{$generation->id}.png");
    expect($generation->fresh()->image_path)->toBeNull();
});

it('rejects a body that is not a PNG without throwing', function () {
    $generation = succeededWithImage();
    Http::fake([FAKE_IMAGE_URL => Http::response('<html>nope</html>', 200)]);

    runDownload($generation);

    Storage::disk('local')->assertMissing("generations/{$generation->id}.png");
    expect($generation->fresh()->image_path)->toBeNull();
});

it('rejects a body over the size limit without throwing', function () {
    $generation = succeededWithImage();
    $body = pngBody(DownloadGenerationImage::MAX_BYTES);
    Http::fake([FAKE_IMAGE_URL => Http::response($body, 200)]);

    runDownload($generation);

    Storage::disk('local')->assertMissing("generations/{$generation->id}.png");
    expect($generation->fresh()->image_path)->toBeNull();
});

it('throws on a server error so the queue retries', function () {
    $generation = succeededWithImage();
    Http::fake([FAKE_IMAGE_URL => Http::response('boom', 500)]);

    expect(fn () => runDownload($generation))
        ->toThrow(RuntimeException::class, 'Generation image download failed.');
});

it('throws a generic error on a connection exception without leaking the url', function () {
    $generation = succeededWithImage();
    Http::fake([FAKE_IMAGE_URL => fn () => throw new ConnectionException('cURL error for '.FAKE_IMAGE_URL)]);

    try {
        runDownload($generation);
        $this->fail('Expected an exception.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Generation image download failed.');
    }
});

it('sends nothing when the generation is not succeeded or has no image url', function () {
    Http::fake();

    runDownload(succeededWithImage(['status' => GenerationStatus::Processing]));
    runDownload(succeededWithImage(['image_url' => null]));
    runDownload(succeededWithImage(['image_path' => 'generations/already.png']));
    (new DownloadGenerationImage('01JMISSINGMISSINGMISSING000'))->handle();

    Http::assertNothingSent();
});

it('is unique per generation id', function () {
    expect((new DownloadGenerationImage('abc'))->uniqueId())->toBe('abc');
});
