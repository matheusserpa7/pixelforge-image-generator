<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditPurchaseKind;
use App\Models\CreditPurchase;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_admin' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * An operator allowed through the `admin` Gate.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    /**
     * Fund the user with a credit purchase of the given USD decimal string.
     */
    public function withCredit(string $usd = '10'): static
    {
        return $this->afterCreating(function (User $user) use ($usd): void {
            CreditPurchase::query()->create([
                'user_id' => $user->id,
                'kind' => CreditPurchaseKind::Purchase,
                'amount_nanos' => Money::fromDecimal($usd)->nanos,
                'idempotency_key' => (string) Str::ulid(),
            ]);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
