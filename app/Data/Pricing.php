<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use InvalidArgumentException;

final readonly class Pricing
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens
     */
    public function __construct(
        public GenerationRates $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromDecoded(array $payload): self
    {
        self::requireString($payload['object'] ?? null, 'object', 'pricing');
        self::requireString($payload['currency'] ?? null, 'currency', 'usd');
        self::requireString($payload['unit'] ?? null, 'unit', 'per_1m_tokens');

        $ratesPayload = self::requireMap($payload['rates'] ?? null, 'rates');
        $rates = new GenerationRates(
            Money::perToken(self::requireRateString($ratesPayload, 'input_text_tokens')),
            Money::perToken(self::requireRateString($ratesPayload, 'input_image_tokens')),
            Money::perToken(self::requireRateString($ratesPayload, 'output_tokens')),
            Money::perToken(self::requireRateString($ratesPayload, 'reasoning_tokens')),
        );

        $tokensPayload = self::requireMap($payload['tokens'] ?? null, 'tokens');

        $charactersPerTextToken = self::requireNonNegativeIntString(
            $tokensPayload['characters_per_text_token'] ?? null,
            'characters_per_text_token',
        );

        if ($charactersPerTextToken < 1) {
            throw new InvalidArgumentException('characters_per_text_token must be at least 1.');
        }

        $variance = self::requireDecimalString(
            $tokensPayload['text_token_variance'] ?? null,
            'text_token_variance',
        );

        if (BigDecimal::of($variance)->isNegative()) {
            throw new InvalidArgumentException('text_token_variance must not be negative.');
        }

        $tokensPerReferenceImage = self::requireNonNegativeIntString(
            $tokensPayload['per_reference_image'] ?? null,
            'per_reference_image',
        );

        $outputTokens = self::parseOutputTable($tokensPayload['output'] ?? null);
        $reasoningTokens = self::parseReasoningTable($tokensPayload['reasoning'] ?? null);

        return new self(
            $rates,
            $charactersPerTextToken,
            $variance,
            $tokensPerReferenceImage,
            $outputTokens,
            $reasoningTokens,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCacheArray(): array
    {
        return [
            'rates' => [
                'input_text' => $this->rates->inputText->nanos,
                'input_image' => $this->rates->inputImage->nanos,
                'output' => $this->rates->output->nanos,
                'reasoning' => $this->rates->reasoning->nanos,
            ],
            'characters_per_text_token' => $this->charactersPerTextToken,
            'text_token_variance' => $this->textTokenVariance,
            'tokens_per_reference_image' => $this->tokensPerReferenceImage,
            'output' => $this->outputTokens,
            'reasoning' => $this->reasoningTokens,
        ];
    }

    /**
     * @param  array<mixed>  $cached
     */
    public static function fromCacheArray(array $cached): self
    {
        $ratesBlock = self::requireMap($cached['rates'] ?? null, 'rates');

        $rates = new GenerationRates(
            Money::ofNanos(self::requireNonNegativeInt($ratesBlock['input_text'] ?? null, 'rates.input_text')),
            Money::ofNanos(self::requireNonNegativeInt($ratesBlock['input_image'] ?? null, 'rates.input_image')),
            Money::ofNanos(self::requireNonNegativeInt($ratesBlock['output'] ?? null, 'rates.output')),
            Money::ofNanos(self::requireNonNegativeInt($ratesBlock['reasoning'] ?? null, 'rates.reasoning')),
        );

        $charactersPerTextToken = self::requireNonNegativeInt(
            $cached['characters_per_text_token'] ?? null,
            'characters_per_text_token',
        );

        if ($charactersPerTextToken < 1) {
            throw new InvalidArgumentException('characters_per_text_token must be at least 1.');
        }

        $variance = self::requireDecimalString(
            $cached['text_token_variance'] ?? null,
            'text_token_variance',
        );

        if (BigDecimal::of($variance)->isNegative()) {
            throw new InvalidArgumentException('text_token_variance must not be negative.');
        }

        $tokensPerReferenceImage = self::requireNonNegativeInt(
            $cached['tokens_per_reference_image'] ?? null,
            'tokens_per_reference_image',
        );

        $outputTokens = self::parseOutputTable($cached['output'] ?? null);
        $reasoningTokens = self::parseReasoningTable($cached['reasoning'] ?? null);

        return new self(
            $rates,
            $charactersPerTextToken,
            $variance,
            $tokensPerReferenceImage,
            $outputTokens,
            $reasoningTokens,
        );
    }

    /**
     * @return array<string, array<string, int>>
     */
    private static function parseOutputTable(mixed $value): array
    {
        $table = self::requireMap($value, 'output');
        $parsed = [];

        foreach (ImageQuality::cases() as $quality) {
            $qualityKey = $quality->value;
            $row = self::requireMap($table[$qualityKey] ?? null, "output.{$qualityKey}");
            $parsedRow = [];

            foreach (ImageSize::cases() as $size) {
                $sizeKey = $size->value;
                $parsedRow[$sizeKey] = self::requireNonNegativeInt(
                    $row[$sizeKey] ?? null,
                    "output.{$qualityKey}.{$sizeKey}",
                );
            }

            $parsed[$qualityKey] = $parsedRow;
        }

        return $parsed;
    }

    /**
     * @return array<string, array{min: int, max: int}>
     */
    private static function parseReasoningTable(mixed $value): array
    {
        $table = self::requireMap($value, 'reasoning');
        $parsed = [];

        foreach (ImageQuality::cases() as $quality) {
            $qualityKey = $quality->value;
            $row = self::requireMap($table[$qualityKey] ?? null, "reasoning.{$qualityKey}");

            $min = self::requireNonNegativeInt($row['min'] ?? null, "reasoning.{$qualityKey}.min");
            $max = self::requireNonNegativeInt($row['max'] ?? null, "reasoning.{$qualityKey}.max");

            if ($min > $max) {
                throw new InvalidArgumentException("reasoning.{$qualityKey}.min must not exceed max.");
            }

            $parsed[$qualityKey] = ['min' => $min, 'max' => $max];
        }

        return $parsed;
    }

    /**
     * @param  array<mixed>  $map
     */
    private static function requireRateString(array $map, string $key): string
    {
        $value = $map[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing or invalid rate \"{$key}\".");
        }

        return $value;
    }

    private static function requireString(mixed $value, string $field, string $expected): string
    {
        if (! is_string($value) || $value !== $expected) {
            throw new InvalidArgumentException("Expected {$field} to be \"{$expected}\".");
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function requireMap(mixed $value, string $field): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException("Expected {$field} to be an object.");
        }

        return $value;
    }

    private static function requireNonNegativeIntString(mixed $value, string $field): int
    {
        return self::requireIntString($value, $field, allowNegative: false);
    }

    private static function requireDecimalString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Expected {$field} to be a decimal string.");
        }

        try {
            BigDecimal::of($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException("Expected {$field} to be a decimal string.");
        }

        return $value;
    }

    private static function requireNonNegativeInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException("Expected {$field} to be non-negative.");
            }

            return $value;
        }

        return self::requireNonNegativeIntString($value, $field);
    }

    private static function requireIntString(mixed $value, string $field, bool $allowNegative = true): int
    {
        if (! is_string($value) || ! preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException("Expected {$field} to be an integer string.");
        }

        if (! $allowNegative && str_starts_with($value, '-')) {
            throw new InvalidArgumentException("Expected {$field} to be non-negative.");
        }

        $integer = BigInteger::of($value);

        if ($integer->isLessThan(BigInteger::of(PHP_INT_MIN)) || $integer->isGreaterThan(BigInteger::of(PHP_INT_MAX))) {
            throw new InvalidArgumentException("Integer {$field} does not fit in a PHP int.");
        }

        return $integer->toInt();
    }
}
