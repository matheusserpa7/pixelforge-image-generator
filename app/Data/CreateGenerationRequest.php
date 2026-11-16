<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;

final readonly class CreateGenerationRequest
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public array $referenceImages,
        public array $metadata,
        public string $idempotencyKey,
    ) {}
}
