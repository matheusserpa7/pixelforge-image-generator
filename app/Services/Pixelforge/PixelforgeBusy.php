<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use RuntimeException;

final class PixelforgeBusy extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Pixelforge request limit reached.');
    }
}
