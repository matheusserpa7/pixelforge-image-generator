<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

dataset('dashboards', ['/horizon', '/pulse']);

it('hides internal dashboards from guests', function (string $path) {
    $this->get($path)->assertForbidden();
})->with('dashboards');

it('hides internal dashboards from regular users', function (string $path) {
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
})->with('dashboards');

it('shows internal dashboards to admins', function (string $path) {
    $this->actingAs(User::factory()->admin()->create())->get($path)->assertOk();
})->with('dashboards');

it('serves internal dashboards without CSP, which their inline scripts would break', function (string $path) {
    $this->actingAs(User::factory()->admin()->create())->get($path)
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('X-Frame-Options', 'DENY');
})->with('dashboards');

it('shares the dashboard links with admins only, at their configured paths', function () {
    config(['horizon.path' => 'ops/queues', 'pulse.path' => 'ops/metrics']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.dashboards.horizon', url('ops/queues'))
            ->where('auth.dashboards.pulse', url('ops/metrics')));

    $this->actingAs(User::factory()->create())
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.dashboards', null));
});
