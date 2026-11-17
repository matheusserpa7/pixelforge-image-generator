<?php

declare(strict_types=1);

use App\Services\Pixelforge\WebhookSignature;

const WEBHOOK_NOW = 1_800_000_000;
const WEBHOOK_BODY = '{"id":"evt_1","type":"ping"}';
const WEBHOOK_SECRET = 'whsec_test';

function webhookHmac(string $body = WEBHOOK_BODY, int $t = WEBHOOK_NOW, string $secret = WEBHOOK_SECRET): string
{
    return hash_hmac('sha256', $t.'.'.$body, $secret);
}

function webhookValid(string $header, string $body = WEBHOOK_BODY, string $secret = WEBHOOK_SECRET): bool
{
    return WebhookSignature::isValid($header, $body, $secret, 300, WEBHOOK_NOW);
}

it('accepts a valid header', function () {
    expect(webhookValid('t='.WEBHOOK_NOW.',v1='.webhookHmac()))->toBeTrue();
});

it('rejects a wrong secret', function () {
    expect(webhookValid('t='.WEBHOOK_NOW.',v1='.webhookHmac(secret: 'other')))->toBeFalse();
});

it('rejects a flipped hex digit', function () {
    $hmac = webhookHmac();
    $flipped = ($hmac[0] === 'a' ? 'b' : 'a').substr($hmac, 1);

    expect(webhookValid('t='.WEBHOOK_NOW.',v1='.$flipped))->toBeFalse();
});

it('rejects a body changed by one byte', function () {
    expect(webhookValid('t='.WEBHOOK_NOW.',v1='.webhookHmac(), WEBHOOK_BODY.' '))->toBeFalse();
});

it('rejects a header without t, without v1, with a non-digit t, or empty', function (string $header) {
    expect(webhookValid($header))->toBeFalse();
})->with([
    'missing t' => fn () => 'v1='.webhookHmac(),
    'missing v1' => 't='.WEBHOOK_NOW,
    'non-digit t' => fn () => 't=abc,v1='.webhookHmac(),
    'empty header' => '',
]);

it('rejects an empty secret', function () {
    expect(webhookValid('t='.WEBHOOK_NOW.',v1='.webhookHmac(secret: ''), secret: ''))->toBeFalse();
});

it('enforces the timestamp tolerance both ways', function () {
    foreach ([-301, 301] as $skew) {
        $t = WEBHOOK_NOW + $skew;
        expect(webhookValid('t='.$t.',v1='.webhookHmac(t: $t)))->toBeFalse();
    }

    foreach ([-300, 300] as $skew) {
        $t = WEBHOOK_NOW + $skew;
        expect(webhookValid('t='.$t.',v1='.webhookHmac(t: $t)))->toBeTrue();
    }
});

it('accepts when either of two v1 values matches', function () {
    expect(webhookValid('t='.WEBHOOK_NOW.',v1='.str_repeat('0', 64).',v1='.webhookHmac()))->toBeTrue();
});

it('accepts spaces after commas', function () {
    expect(webhookValid('t='.WEBHOOK_NOW.', v1='.webhookHmac()))->toBeTrue();
});
