<?php

declare(strict_types=1);

use App\Services\Pixelforge\DecimalJson;

it('quotes bare JSON numbers as strings', function () {
    $decoded = DecimalJson::decode('{"text_token_variance":0.15}');

    expect($decoded['text_token_variance'])->toBe('0.15');
});

it('leaves numbers inside string literals untouched', function () {
    $decoded = DecimalJson::decode('{"note":"wait: 5"}');

    expect($decoded['note'])->toBe('wait: 5');
});

it('keeps values that were already JSON strings', function () {
    $decoded = DecimalJson::decode('{"rate":"5.00"}');

    expect($decoded['rate'])->toBe('5.00');
});

it('throws on invalid JSON', function () {
    DecimalJson::decode('{not json');
})->throws(InvalidArgumentException::class);
