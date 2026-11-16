<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * An exact amount of US dollars, stored as an integer number of nano-dollars
 * (1 USD = 1,000,000,000 nanos). See docs/adr/0003-money-in-nano-usd.md.
 *
 * All arithmetic goes through brick/math: no float is ever involved, and any
 * result that cannot be represented exactly throws instead of being rounded
 * silently. The only rounding rules are in withMarkup() and format().
 */
final readonly class Money
{
    /** Nine decimal places: the precision of Pixelforge's `cost_usd`. */
    private const int SCALE = 9;

    private function __construct(public int $nanos) {}

    public static function ofNanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse an exact USD decimal string, e.g. "0.046125000".
     */
    public static function fromDecimal(string $usd): self
    {
        return self::exactly(
            fn (): int => BigDecimal::of($usd)->toScale(self::SCALE)->getUnscaledValue()->toInt(),
            "\"{$usd}\" is not a USD amount with at most nine decimals.",
        );
    }

    /**
     * Price of one token, from a Pixelforge rate in USD per 1M tokens.
     * USD per 1M tokens × 10⁹ nanos / 10⁶ tokens = rate × 1,000 nanos per token.
     */
    public static function perToken(string $usdPerMillionTokens): self
    {
        return self::exactly(
            fn (): int => BigDecimal::of($usdPerMillionTokens)->multipliedBy(1_000)->toScale(0)->toInt(),
            "Rate \"{$usdPerMillionTokens}\" per 1M tokens is not a whole number of nanos per token.",
        );
    }

    public function plus(self $other): self
    {
        return self::exactly(
            fn (): int => BigInteger::of($this->nanos)->plus($other->nanos)->toInt(),
            'Money addition overflowed.',
        );
    }

    /**
     * Multiply a per-token price by a number of tokens.
     */
    public function times(int $quantity): self
    {
        return self::exactly(
            fn (): int => BigInteger::of($this->nanos)->multipliedBy($quantity)->toInt(),
            'Money multiplication overflowed.',
        );
    }

    /**
     * Add a percentage on top, rounded half-up to the nano (docs/adr/0004-markup-and-rounding.md).
     * The percentage is a decimal string such as "25" or "12.5".
     */
    public function withMarkup(string $percent): self
    {
        return self::exactly(
            fn (): int => BigDecimal::of($this->nanos)
                ->multipliedBy(BigDecimal::of($percent)->plus(100))
                ->dividedBy(100, 0, RoundingMode::HalfUp)
                ->toInt(),
            "Markup \"{$percent}\" is not a valid percentage.",
        );
    }

    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    /**
     * The exact amount in USD with nine decimals, e.g. "0.046125000".
     */
    public function toDecimalString(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->nanos, self::SCALE);
    }

    /**
     * A human-readable amount, rounded half-up for display only, e.g. "$0.0461".
     *
     * @param  non-negative-int  $decimals
     */
    public function format(int $decimals = 4): string
    {
        $amount = BigDecimal::ofUnscaledValue($this->nanos, self::SCALE)
            ->toScale($decimals, RoundingMode::HalfUp);

        return ($amount->isNegative() ? '-' : '').'$'.$amount->abs();
    }

    /**
     * Run a brick/math calculation and turn any precision or overflow problem
     * into an InvalidArgumentException with a readable message.
     *
     * @param  callable(): int  $calculation
     */
    private static function exactly(callable $calculation, string $error): self
    {
        try {
            return new self($calculation());
        } catch (MathException $exception) {
            throw new InvalidArgumentException($error, previous: $exception);
        }
    }
}
