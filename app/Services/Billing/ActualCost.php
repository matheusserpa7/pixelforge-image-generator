<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\GenerationCost;
use App\Data\GenerationRates;
use App\Data\TokenUsage;
use InvalidArgumentException;

final class ActualCost
{
    public static function calculate(
        TokenUsage $usage,
        GenerationRates $rates,
        string $markupPercent,
    ): GenerationCost {
        self::requireNonNegative($usage->inputText, 'inputText');
        self::requireNonNegative($usage->inputImage, 'inputImage');
        self::requireNonNegative($usage->output, 'output');
        self::requireNonNegative($usage->reasoning, 'reasoning');

        $providerCost = $rates->inputText->times($usage->inputText)
            ->plus($rates->inputImage->times($usage->inputImage))
            ->plus($rates->output->times($usage->output))
            ->plus($rates->reasoning->times($usage->reasoning));

        return new GenerationCost(
            providerCost: $providerCost,
            charge: $providerCost->withMarkup($markupPercent),
        );
    }

    private static function requireNonNegative(int $value, string $field): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("{$field} must not be negative.");
        }
    }
}
