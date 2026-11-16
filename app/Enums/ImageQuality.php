<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Image quality accepted by Pixelforge. Higher quality means more output and
 * reasoning tokens, so a higher price.
 */
enum ImageQuality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
