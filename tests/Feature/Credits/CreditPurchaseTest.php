<?php

declare(strict_types=1);

use App\Actions\GetCreditBalance;
use App\Enums\CreditPurchaseKind;
use App\Models\CreditPurchase;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function purchaseKey(): string
{
    return (string) Str::ulid();
}

it('sends guests to login', function () {
    $this->get(route('credits.index'))->assertRedirect(route('login'));
    $this->post(route('credits.purchase'), ['pack' => '5', 'idempotency_key' => purchaseKey()])
        ->assertRedirect(route('login'));
});

it('adds the chosen pack to the available credit', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.purchase'), ['pack' => '5', 'idempotency_key' => purchaseKey()])
        ->assertRedirect(route('credits.index'));

    expect(app(GetCreditBalance::class)->handle($user)->available()->nanos)->toBe(5_000_000_000);
    expect(CreditPurchase::query()->where('user_id', $user->id)->first()->kind)->toBe(CreditPurchaseKind::Purchase);
});

it('adds a pack once when the same key is sent twice', function () {
    $user = User::factory()->create();
    $key = purchaseKey();

    $this->actingAs($user)->post(route('credits.purchase'), ['pack' => '10', 'idempotency_key' => $key]);
    $this->actingAs($user)->post(route('credits.purchase'), ['pack' => '10', 'idempotency_key' => $key])
        ->assertRedirect(route('credits.index'));

    expect(CreditPurchase::query()->count())->toBe(1)
        ->and(app(GetCreditBalance::class)->handle($user)->available()->nanos)->toBe(10_000_000_000);
});

it('rejects a key that belongs to another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $key = purchaseKey();

    $this->actingAs($owner)->post(route('credits.purchase'), ['pack' => '5', 'idempotency_key' => $key]);

    $this->actingAs($other)
        ->post(route('credits.purchase'), ['pack' => '5', 'idempotency_key' => $key])
        ->assertSessionHasErrors('idempotency_key');

    expect(CreditPurchase::query()->count())->toBe(1)
        ->and(app(GetCreditBalance::class)->handle($other)->available()->nanos)->toBe(0);
});

it('rejects a pack that is not configured and a malformed key', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.purchase'), ['pack' => '7', 'idempotency_key' => purchaseKey()])
        ->assertSessionHasErrors('pack');

    $this->actingAs($user)
        ->post(route('credits.purchase'), ['pack' => '5', 'idempotency_key' => 'nope'])
        ->assertSessionHasErrors('idempotency_key');

    expect(CreditPurchase::query()->count())->toBe(0);
});

it('throttles purchases per user', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->post(route('credits.purchase'), ['pack' => '5', 'idempotency_key' => purchaseKey()]);
    }

    $this->actingAs($user)
        ->post(route('credits.purchase'), ['pack' => '5', 'idempotency_key' => purchaseKey()])
        ->assertStatus(429);
});

it('shows only the user figures and purchases on the credits page', function () {
    $user = User::factory()->withCredit('10')->create();
    User::factory()->withCredit('25')->create();

    $this->actingAs($user)->get(route('credits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('credits/Index', false)
            ->where('balance.available.amount', '10.000000000')
            ->where('balance.held.amount', '0.000000000')
            ->has('purchases', 1)
            ->where('purchases.0.kind', 'purchase')
            ->where('packs.0', ['value' => '5', 'label' => '$5.00'])
            ->has('packs', 3)
            ->whereType('purchaseKey', 'string'));
});

it('shares the available credit for users and null for guests', function () {
    $user = User::factory()->withCredit('5')->create();

    $this->actingAs($user)->get(route('credits.index'))
        ->assertInertia(fn (Assert $page) => $page->where('credits.available.amount', '5.000000000'));

    auth()->logout();
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('credits', null));
});
