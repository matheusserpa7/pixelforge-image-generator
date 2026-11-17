<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class DownloadGenerationImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int MAX_BYTES = 20 * 1024 * 1024;

    public const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public int $uniqueFor = 900;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(): void
    {
        $generation = Generation::query()->find($this->generationId);

        if (
            $generation === null
            || $generation->status !== GenerationStatus::Succeeded
            || $generation->image_path !== null
            || $generation->image_url === null
        ) {
            return;
        }

        if ($generation->image_url_expires_at !== null && ! $generation->image_url_expires_at->isFuture()) {
            Log::info('Generation image expired before download.', ['generation_id' => $generation->id]);

            return;
        }

        try {
            $response = Http::timeout(30)->get($generation->image_url);
        } catch (ConnectionException) {
            throw new RuntimeException('Generation image download failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Generation image download failed.');
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES || ! str_starts_with($body, self::PNG_SIGNATURE)) {
            Log::warning('Generation image rejected.', ['generation_id' => $generation->id]);

            return;
        }

        $path = "generations/{$generation->id}.png";

        Storage::disk(config()->string('filesystems.generation_images_disk'))->put($path, $body);

        Generation::query()
            ->whereKey($generation->id)
            ->whereNull('image_path')
            ->update(['image_path' => $path]);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Generation image download gave up.', ['generation_id' => $this->generationId]);
    }
}
