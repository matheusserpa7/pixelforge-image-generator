<?php

declare(strict_types=1);

use App\Data\ProviderUsage;

function usagePayload(array $overrides = []): array
{
    return array_replace([
        'object' => 'usage',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-02',
        'data' => [
            ['date' => '2026-11-01', 'generations' => 3, 'output_tokens' => 3300, 'cost_usd' => '0.169080000'],
            ['date' => '2026-11-02', 'generations' => 0, 'output_tokens' => 0, 'cost_usd' => '0.000000000'],
        ],
        'total' => ['generations' => 3, 'cost_usd' => '0.169080000'],
    ], $overrides);
}

it('maps nine-decimal costs per date and the total, ignoring token fields', function () {
    $usage = ProviderUsage::fromDecoded(usagePayload());

    expect($usage->startDate->format('Y-m-d'))->toBe('2026-11-01')
        ->and($usage->endDate->format('Y-m-d'))->toBe('2026-11-02')
        ->and(array_keys($usage->costByDate))->toBe(['2026-11-01', '2026-11-02'])
        ->and($usage->costByDate['2026-11-01']->nanos)->toBe(169_080_000)
        ->and($usage->costByDate['2026-11-02']->nanos)->toBe(0)
        ->and($usage->total->nanos)->toBe(169_080_000);
});

it('rejects a malformed cost', function (mixed $cost) {
    ProviderUsage::fromDecoded(usagePayload([
        'data' => [['date' => '2026-11-01', 'cost_usd' => $cost]],
    ]));
})->with([
    'not a number' => ['abc'],
    'more than nine decimals' => ['0.0000000001'],
    'numeric instead of string' => [0.17],
    'null' => [null],
])->throws(InvalidArgumentException::class);

it('rejects a bad date or a missing total', function (array $overrides) {
    ProviderUsage::fromDecoded(usagePayload($overrides));
})->with([
    'bad date' => [['start_date' => '2026-13-45']],
    'missing total' => [['total' => null]],
    'wrong object' => [['object' => 'generation']],
])->throws(InvalidArgumentException::class);

it('round-trips through its cache array of scalars', function () {
    $usage = ProviderUsage::fromDecoded(usagePayload());
    $cached = $usage->toCacheArray();

    expect($cached)->toBe([
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-02',
        'cost_by_date' => ['2026-11-01' => 169_080_000, '2026-11-02' => 0],
        'total' => 169_080_000,
    ])->and(ProviderUsage::fromCacheArray($cached))->toEqual($usage);
});

it('rejects an unreadable cache array', function (array $cached) {
    ProviderUsage::fromCacheArray($cached);
})->with([
    'empty' => [[]],
    'negative total' => [['start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'cost_by_date' => [], 'total' => -1]],
    'string nanos' => [['start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'cost_by_date' => ['2026-11-01' => '5'], 'total' => 5]],
    'bad date key' => [['start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'cost_by_date' => ['nope' => 5], 'total' => 5]],
])->throws(InvalidArgumentException::class);
