<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a generation, mirroring Pixelforge:
 * queued → processing → succeeded | failed (docs/adr/0009-monotonic-status.md).
 */
enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Final statuses never change again.
     */
    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
