<?php

declare(strict_types=1);

use App\Models\User;

it('promotes an existing user to admin', function (): void {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->artisan('users:make-admin', ['email' => 'ana@example.com'])
        ->expectsOutput('ana@example.com is now an admin.')
        ->assertExitCode(0);

    expect($user->fresh()->is_admin)->toBeTrue();
});

it('does nothing for a user who is already an admin', function (): void {
    $user = User::factory()->admin()->create(['email' => 'root@example.com']);
    $updatedAt = $user->updated_at;
    // Move the clock so any save would change updated_at.
    $this->travel(5)->minutes();

    $this->artisan('users:make-admin', ['email' => 'root@example.com'])
        ->expectsOutput('root@example.com is already an admin.')
        ->assertExitCode(0);

    expect($user->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('fails for an unknown email', function (): void {
    $this->artisan('users:make-admin', ['email' => 'ghost@example.com'])
        ->expectsOutput('No user with email ghost@example.com.')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});
