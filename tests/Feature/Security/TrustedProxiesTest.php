<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Route::get('/_test/ip', fn (Request $request) => $request->ip());
});

function forwardedRequest(): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
        ->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.9']);
}

it('ignores forwarding headers when no proxy is trusted', function () {
    config(['trustedproxy.proxies' => '']);

    forwardedRequest()->assertContent('172.18.0.5');
});

it('trusts forwarding headers from private networks by default', function () {
    forwardedRequest()->assertContent('203.0.113.9');
});

it('resolves the client behind the edge proxy and ignores a forged forwarding entry', function () {
    Route::get('/_test/edge', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
    ]));

    test()->withServerVariables(['REMOTE_ADDR' => '172.21.0.4'])
        ->get('/_test/edge', [
            'X-Forwarded-For' => '10.9.9.9, 203.0.113.9',
            'X-Forwarded-Proto' => 'https',
        ])
        ->assertExactJson(['ip' => '203.0.113.9', 'secure' => true]);
});
