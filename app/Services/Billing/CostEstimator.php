<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\CostEstimate;
use App\Data\GenerationRates;
use App\Data\Pricing;
use App\Data\TokenUsage;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class CostEstimator
{
    public static function estimate(
        Pricing $pricing,
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
        string $markupPercent,
    ): CostEstimate {
        if ($referenceImageCount < 0) {
            throw new InvalidArgumentException('referenceImageCount must not be negative.');
        }

        $qualityKey = $quality->value;
        $sizeKey = $size->value;

        if (! isset($pricing->outputTokens[$qualityKey][$sizeKey])) {
            throw new InvalidArgumentException("Missing output token count for {$qualityKey} and {$sizeKey}.");
        }

        if (! isset($pricing->reasoningTokens[$qualityKey])) {
            throw new InvalidArgumentException("Missing reasoning token range for {$qualityKey}.");
        }

        $chars = mb_strlen($prompt, 'UTF-8');
        $perChar = $pricing->charactersPerTextToken;
        $textEstimate = intdiv($chars + $perChar - 1, $perChar);

        $textMaximum = BigDecimal::of($textEstimate)
            ->multipliedBy(BigDecimal::of($pricing->textTokenVariance)->plus(1))
            ->toScale(0, RoundingMode::Ceiling)
            ->toInt();

        $imageTokens = self::imageTokens($pricing->tokensPerReferenceImage, $referenceImageCount);

        $outputTokens = $pricing->outputTokens[$qualityKey][$sizeKey];

        $reasoningRow = $pricing->reasoningTokens[$qualityKey];
        $reasoningEstimate = BigDecimal::of($reasoningRow['min'])
            ->plus($reasoningRow['max'])
            ->dividedBy(2, 0, RoundingMode::HalfUp)
            ->toInt();
        $reasoningMaximum = $reasoningRow['max'];

        $rates = $pricing->rates;

        $estimatedProviderCost = self::providerCost(
            $rates,
            $textEstimate,
            $imageTokens,
            $outputTokens,
            $reasoningEstimate,
        );

        $maximumProviderCost = self::providerCost(
            $rates,
            $textMaximum,
            $imageTokens,
            $outputTokens,
            $reasoningMaximum,
        );

        return new CostEstimate(
            estimatedProviderCost: $estimatedProviderCost,
            maximumProviderCost: $maximumProviderCost,
            estimatedCharge: $estimatedProviderCost->withMarkup($markupPercent),
            maximumCharge: $maximumProviderCost->withMarkup($markupPercent),
            maximumTokens: new TokenUsage($textMaximum, $imageTokens, $outputTokens, $reasoningMaximum),
        );
    }

    private static function imageTokens(int $tokensPerReferenceImage, int $referenceImageCount): int
    {
        try {
            return BigInteger::of($tokensPerReferenceImage)->multipliedBy($referenceImageCount)->toInt();
        } catch (MathException $exception) {
            throw new InvalidArgumentException(
                'Image token count does not fit in a PHP int.',
                previous: $exception,
            );
        }
    }

    private static function providerCost(
        GenerationRates $rates,
        int $textTokens,
        int $imageTokens,
        int $outputTokens,
        int $reasoningTokens,
    ): Money {
        return $rates->inputText->times($textTokens)
            ->plus($rates->inputImage->times($imageTokens))
            ->plus($rates->output->times($outputTokens))
            ->plus($rates->reasoning->times($reasoningTokens));
    }
}
