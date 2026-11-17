<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\CostEstimate;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Billing\CostEstimator;

final class EstimateGeneration
{
    public function __construct(
        private readonly GetCachedPricing $getCachedPricing,
    ) {}

    public function handle(
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
    ): CostEstimate {
        return CostEstimator::estimate(
            $this->getCachedPricing->handle(),
            $prompt,
            $size,
            $quality,
            $referenceImageCount,
            config()->string('billing.markup_percent'),
        );
    }
}
