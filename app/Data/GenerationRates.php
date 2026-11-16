<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Money;
use InvalidArgumentException;

final readonly class GenerationRates
{
    public function __construct(
        public Money $inputText,
        public Money $inputImage,
        public Money $output,
        public Money $reasoning,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toStoredArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText->nanos,
            'input_image_tokens' => $this->inputImage->nanos,
            'output_tokens' => $this->output->nanos,
            'reasoning_tokens' => $this->reasoning->nanos,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromStoredArray(array $stored): self
    {
        return new self(
            Money::ofNanos(self::requireInt($stored, 'input_text_tokens')),
            Money::ofNanos(self::requireInt($stored, 'input_image_tokens')),
            Money::ofNanos(self::requireInt($stored, 'output_tokens')),
            Money::ofNanos(self::requireInt($stored, 'reasoning_tokens')),
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function requireInt(array $stored, string $key): int
    {
        if (! array_key_exists($key, $stored)) {
            throw new InvalidArgumentException("Missing key \"{$key}\".");
        }

        $value = $stored[$key];

        if (! is_int($value)) {
            throw new InvalidArgumentException("Expected \"{$key}\" to be an integer.");
        }

        return $value;
    }
}
