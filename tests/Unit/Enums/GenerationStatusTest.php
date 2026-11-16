<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;

it('uses the Pixelforge status values', function () {
    expect(array_map(fn (GenerationStatus $status) => $status->value, GenerationStatus::cases()))
        ->toBe(['queued', 'processing', 'succeeded', 'failed']);
});

it('treats only succeeded and failed as final', function (GenerationStatus $status, bool $final) {
    expect($status->isFinal())->toBe($final);
})->with([
    [GenerationStatus::Queued, false],
    [GenerationStatus::Processing, false],
    [GenerationStatus::Succeeded, true],
    [GenerationStatus::Failed, true],
]);

it('ranks queued lowest, processing in the middle, and both finals at the top', function (GenerationStatus $status, int $rank) {
    expect($status->rank())->toBe($rank);
})->with([
    [GenerationStatus::Queued, 0],
    [GenerationStatus::Processing, 1],
    [GenerationStatus::Succeeded, 2],
    [GenerationStatus::Failed, 2],
]);
