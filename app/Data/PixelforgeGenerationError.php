<?php

declare(strict_types=1);

namespace App\Data;

final readonly class PixelforgeGenerationError
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}
}
