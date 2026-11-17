<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('creates the development accounts outside production', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->whereIn('email', ['test@example.com', 'admin@example.com'])->count())->toBe(2);
});

it('creates no accounts in production, even when the seed is forced', function (): void {
    $original = app()['env'];
    app()['env'] = 'production';

    try {
        // --force skips db:seed's own production prompt, so only the seeder guard can stop it.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->assertExitCode(0);
    } finally {
        app()['env'] = $original;
    }

    expect(User::query()->count())->toBe(0);
});
