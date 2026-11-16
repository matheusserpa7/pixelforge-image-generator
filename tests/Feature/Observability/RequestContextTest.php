<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test/context', fn () => Context::only(['request_id', 'user_id']));
});

it('gives every response a request id', function () {
    $response = $this->get(route('health'));

    expect($response->headers->get('X-Request-Id'))->toBeString()->toHaveLength(36);
});

it('reuses a safe request id from the proxy', function () {
    $this->withHeader('X-Request-Id', 'proxy-request-12345')
        ->get(route('health'))
        ->assertHeader('X-Request-Id', 'proxy-request-12345');
});

it('replaces an unsafe request id', function () {
    $response = $this->withHeader('X-Request-Id', "bad\nid")->get(route('health'));

    expect($response->headers->get('X-Request-Id'))->not->toBe("bad\nid")->toHaveLength(36);
});

it('puts the request and user ids in the log context', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/_test/context');

    $response->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('request_id', $response->headers->get('X-Request-Id'));
});
