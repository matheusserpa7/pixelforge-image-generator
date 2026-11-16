<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;

it('reports ok when MySQL and Redis answer', function () {
    $this->getJson(route('health'))
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'redis' => 'ok'],
        ]);
});

it('answers 503 when a dependency is down', function () {
    Redis::shouldReceive('connection->ping')->andThrow(new RuntimeException('Connection refused'));

    $this->getJson(route('health'))
        ->assertServiceUnavailable()
        ->assertJsonPath('status', 'fail')
        ->assertJsonPath('checks.database', 'ok')
        ->assertJsonPath('checks.redis', 'fail');
});

it('does not start a session', function () {
    $this->get(route('health'))->assertCookieMissing(config('session.cookie'));
});
