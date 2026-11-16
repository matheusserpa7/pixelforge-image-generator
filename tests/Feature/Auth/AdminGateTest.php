<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets admins through the admin gate', function () {
    expect(Gate::forUser(User::factory()->admin()->create())->allows('admin'))->toBeTrue();
});

it('keeps regular users out of the admin gate', function () {
    expect(Gate::forUser(User::factory()->create())->allows('admin'))->toBeFalse();
});

it('never makes a user admin from the registration form', function () {
    $this->post(route('register.store'), [
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'is_admin' => true,
    ]);

    expect(User::query()->where('email', 'mallory@example.com')->sole()->is_admin)->toBeFalse();
});
