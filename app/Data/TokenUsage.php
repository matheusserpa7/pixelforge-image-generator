<?php

declare(strict_types=1);

namespace App\Data;

use InvalidArgumentException;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toStoredArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText,
            'input_image_tokens' => $this->inputImage,
            'output_tokens' => $this->output,
            'reasoning_tokens' => $this->reasoning,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromStoredArray(array $stored): self
    {
        return new self(
            self::requireNonNegativeInt($stored, 'input_text_tokens'),
            self::requireNonNegativeInt($stored, 'input_image_tokens'),
            self::requireNonNegativeInt($stored, 'output_tokens'),
            self::requireNonNegativeInt($stored, 'reasoning_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function requireNonNegativeInt(array $stored, string $key): int
    {
        if (! array_key_exists($key, $stored)) {
            throw new InvalidArgumentException("Missing key \"{$key}\".");
        }

        $value = $stored[$key];

        if (! is_int($value)) {
            throw new InvalidArgumentException("Expected \"{$key}\" to be an integer.");
        }

        if ($value < 0) {
            throw new InvalidArgumentException("Expected \"{$key}\" to be non-negative.");
        }

        return $value;
    }
}
