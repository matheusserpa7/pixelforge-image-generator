<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * What Pixelforge billed for the calling key in a date range (GET /v1/usage).
 * Token counts are not needed by the app and are not kept.
 */
final readonly class ProviderUsage
{
    /**
     * @param  array<string, Money>  $costByDate  Keyed by UTC date (Y-m-d).
     */
    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public array $costByDate,
        public Money $total,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromDecoded(array $payload): self
    {
        if (($payload['object'] ?? null) !== 'usage') {
            throw new InvalidArgumentException('Expected object to be "usage".');
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new InvalidArgumentException('Expected data to be a list.');
        }

        $costByDate = [];

        foreach ($data as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Expected each data entry to be an object.');
            }

            $date = self::parseDate($row['date'] ?? null, 'data[].date');
            $costByDate[$date->format('Y-m-d')] = self::parseCost($row['cost_usd'] ?? null, 'data[].cost_usd');
        }

        $total = $payload['total'] ?? null;

        if (! is_array($total)) {
            throw new InvalidArgumentException('Expected total to be an object.');
        }

        return new self(
            self::parseDate($payload['start_date'] ?? null, 'start_date'),
            self::parseDate($payload['end_date'] ?? null, 'end_date'),
            $costByDate,
            self::parseCost($total['cost_usd'] ?? null, 'total.cost_usd'),
        );
    }

    private static function parseDate(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException("Expected {$field} to be a Y-m-d date.");
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            $date = null;
        }

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Expected {$field} to be a valid date.");
        }

        return $date;
    }

    private static function parseCost(mixed $value, string $field): Money
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Expected {$field} to be a decimal string.");
        }

        return Money::fromDecimal($value);
    }
}
