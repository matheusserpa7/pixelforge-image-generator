<?php

declare(strict_types=1);

use App\Services\Pixelforge\SubmitBackoff;
use InvalidArgumentException;

it('returns one second for a null retry delay on the first attempt', function () {
    expect(SubmitBackoff::seconds(null, 1))->toBe(1);
});

it('treats a negative retry-after as zero', function () {
    expect(SubmitBackoff::seconds(-4, 1))->toBe(1);
});

it('adds retry-after to the exponential backoff on the first attempt', function () {
    expect(SubmitBackoff::seconds(7, 1))->toBe(8);
});

it('uses retry-after plus sixteen on attempt five', function () {
    expect(SubmitBackoff::seconds(7, 5))->toBe(23);
});

it('rejects attempt zero', function () {
    expect(fn () => SubmitBackoff::seconds(null, 0))->toThrow(InvalidArgumentException::class);
});

it('caps the exponential shift at twenty', function () {
    expect(SubmitBackoff::seconds(null, 30))->toBe(2 ** 20);
});
