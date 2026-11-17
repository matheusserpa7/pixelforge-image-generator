<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A generation Pixelforge has had for longer than expected without a final answer.
 * `userEmail` is null when the user was deleted.
 */
final readonly class AdminReportStuckGeneration
{
    public function __construct(
        public string $id,
        public ?string $userEmail,
        public string $status,
        public string $providerId,
        public CarbonImmutable $createdAt,
    ) {}
}
