<?php

declare(strict_types=1);

use App\Models\User;

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
