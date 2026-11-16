<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use InvalidArgumentException;

/**
 * Decodes Pixelforge JSON so numeric literals become decimal strings before
 * PHP can turn them into floats.
 */
final class DecimalJson
{
    /**
     * @return array<mixed>
     */
    public static function decode(string $json): array
    {
        $rewritten = self::quoteBareNumbers($json);

        try {
            $decoded = json_decode($rewritten, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('JSON root must be an object.');
        }

        return $decoded;
    }

    private static function quoteBareNumbers(string $json): string
    {
        $length = strlen($json);
        $output = '';
        $inString = false;
        $escape = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $json[$index];

            if ($inString) {
                $output .= $character;

                if ($escape) {
                    $escape = false;
                } elseif ($character === '\\') {
                    $escape = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
                $output .= $character;

                continue;
            }

            if ($character === '-' || ctype_digit($character)) {
                $number = self::readNumber($json, $index);

                if ($number !== null) {
                    $output .= '"'.$number.'"';
                    $index += strlen($number) - 1;

                    continue;
                }
            }

            $output .= $character;
        }

        return $output;
    }

    private static function readNumber(string $json, int $start): ?string
    {
        $tail = substr($json, $start);

        if ($tail === '' || preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $tail, $matches) !== 1) {
            return null;
        }

        $number = $matches[0];

        if ($number === '-' || $number === '+') {
            return null;
        }

        if ($number[0] === '-' && ! ctype_digit($number[1] ?? '')) {
            return null;
        }

        return $number;
    }
}
