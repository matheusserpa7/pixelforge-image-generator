<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class GenerationImageController extends Controller
{
    public function __invoke(Generation $generation): StreamedResponse|RedirectResponse
    {
        if ($generation->image_path !== null) {
            $disk = Storage::disk(config()->string('filesystems.generation_images_disk'));

            if ($disk->exists($generation->image_path)) {
                return $disk->response($generation->image_path, "{$generation->id}.png", [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'private, max-age=86400',
                ]);
            }
        }

        if (
            $generation->status === GenerationStatus::Succeeded
            && $generation->image_url !== null
            && ($generation->image_url_expires_at === null || $generation->image_url_expires_at->isFuture())
        ) {
            return redirect()->away($generation->image_url);
        }

        abort(404);
    }
}
