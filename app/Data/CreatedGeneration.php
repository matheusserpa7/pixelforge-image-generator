<?php

declare(strict_types=1);

namespace App\Data;

final readonly class CreatedGeneration
{
    public function __construct(
        public PixelforgeGeneration $generation,
        public bool $replayed,
    ) {}
}
