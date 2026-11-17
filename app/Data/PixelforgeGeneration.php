<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Money\Money;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PixelforgeGeneration
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public array $referenceImages,
        public array $metadata,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?TokenUsage $usage,
        public GenerationRates $rates,
        public ?PixelforgeGenerationError $error,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromDecoded(array $payload): self
    {
        if (! is_string($payload['object'] ?? null) || $payload['object'] !== 'generation') {
            throw new InvalidArgumentException('Expected object to be "generation".');
        }

        $status = self::generationStatus($payload['status'] ?? null);
        $size = self::imageSize($payload['size'] ?? null);
        $quality = self::imageQuality($payload['quality'] ?? null);

        $referenceImages = self::parseStringList($payload['reference_images'] ?? []);
        $metadata = self::parseMetadata($payload['metadata'] ?? []);

        $ratesPayload = self::requireMap($payload['rates'] ?? null, 'rates');
        $rates = new GenerationRates(
            Money::perToken(self::requireRateString($ratesPayload, 'input_text_tokens')),
            Money::perToken(self::requireRateString($ratesPayload, 'input_image_tokens')),
            Money::perToken(self::requireRateString($ratesPayload, 'output_tokens')),
            Money::perToken(self::requireRateString($ratesPayload, 'reasoning_tokens')),
        );

        $usage = self::parseUsage($payload['usage'] ?? null);
        $error = self::parseError($payload['error'] ?? null);

        $imageUrl = self::nullableString($payload['image_url'] ?? null);

        return new self(
            self::requireNonEmptyString($payload['id'] ?? null, 'id'),
            $status,
            self::requireString($payload['prompt'] ?? null, 'prompt'),
            $size,
            $quality,
            $referenceImages,
            $metadata,
            $imageUrl,
            self::parseTimestamp($payload['image_url_expires_at'] ?? null),
            $usage,
            $rates,
            $error,
            self::parseRequiredTimestamp($payload['created_at'] ?? null, 'created_at'),
            self::parseTimestamp($payload['started_at'] ?? null),
            self::parseTimestamp($payload['completed_at'] ?? null),
        );
    }

    private static function generationStatus(mixed $value): GenerationStatus
    {
        if (! is_string($value) || GenerationStatus::tryFrom($value) === null) {
            throw new InvalidArgumentException('Unknown status.');
        }

        return GenerationStatus::from($value);
    }

    private static function imageSize(mixed $value): ImageSize
    {
        if (! is_string($value) || ImageSize::tryFrom($value) === null) {
            throw new InvalidArgumentException('Unknown size.');
        }

        return ImageSize::from($value);
    }

    private static function imageQuality(mixed $value): ImageQuality
    {
        if (! is_string($value) || ImageQuality::tryFrom($value) === null) {
            throw new InvalidArgumentException('Unknown quality.');
        }

        return ImageQuality::from($value);
    }

    /**
     * @return list<string>
     */
    private static function parseStringList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('reference_images must be an array.');
        }

        $list = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('reference_images must contain only strings.');
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * @return array<string, string>
     */
    private static function parseMetadata(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $map = self::requireMap($value, 'metadata');
        $parsed = [];

        foreach ($map as $key => $item) {
            if (! is_string($key) || ! is_string($item)) {
                throw new InvalidArgumentException('metadata must be string keys and string values.');
            }

            $parsed[$key] = $item;
        }

        return $parsed;
    }

    private static function parseUsage(mixed $value): ?TokenUsage
    {
        if ($value === null) {
            return null;
        }

        $map = self::requireMap($value, 'usage');

        $usage = new TokenUsage(
            self::requireNonNegativeIntString($map['input_text_tokens'] ?? null, 'usage.input_text_tokens'),
            self::requireNonNegativeIntString($map['input_image_tokens'] ?? null, 'usage.input_image_tokens'),
            self::requireNonNegativeIntString($map['output_tokens'] ?? null, 'usage.output_tokens'),
            self::requireNonNegativeIntString($map['reasoning_tokens'] ?? null, 'usage.reasoning_tokens'),
        );

        // A total that is not the sum of the four priced types means a token type we do not price.
        if (array_key_exists('total_tokens', $map)) {
            $total = self::requireNonNegativeIntString($map['total_tokens'], 'usage.total_tokens');
            $sum = BigInteger::of($usage->inputText)
                ->plus($usage->inputImage)
                ->plus($usage->output)
                ->plus($usage->reasoning);

            if (! $sum->isEqualTo($total)) {
                throw new InvalidArgumentException('usage.total_tokens does not match the priced token types.');
            }
        }

        return $usage;
    }

    private static function parseError(mixed $value): ?PixelforgeGenerationError
    {
        if ($value === null) {
            return null;
        }

        $map = self::requireMap($value, 'error');

        return new PixelforgeGenerationError(
            self::requireString($map['code'] ?? null, 'error.code'),
            self::requireString($map['message'] ?? null, 'error.message'),
        );
    }

    private static function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Expected timestamp to be a string.');
        }

        return CarbonImmutable::parse($value)->utc();
    }

    private static function parseRequiredTimestamp(mixed $value, string $field): CarbonImmutable
    {
        $parsed = self::parseTimestamp($value);

        if ($parsed === null) {
            throw new InvalidArgumentException("Missing {$field}.");
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

    private static function requireNonEmptyString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Expected {$field} to be a non-empty string.");
        }

        return $value;
    }

    private static function requireString(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Expected {$field} to be a string.");
        }

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Expected nullable string field.');
        }

        return $value;
    }

    private static function requireNonNegativeIntString(mixed $value, string $field): int
    {
        if (! is_string($value) || ! preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException("Expected {$field} to be an integer string.");
        }

        if (str_starts_with($value, '-')) {
            throw new InvalidArgumentException("Expected {$field} to be non-negative.");
        }

        $integer = BigInteger::of($value);

        if ($integer->isLessThan(BigInteger::of(PHP_INT_MIN)) || $integer->isGreaterThan(BigInteger::of(PHP_INT_MAX))) {
            throw new InvalidArgumentException("Integer {$field} does not fit in a PHP int.");
        }

        return $integer->toInt();
    }
}
