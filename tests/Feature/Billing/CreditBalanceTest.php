<?php

declare(strict_types=1);

use App\Actions\GetCreditBalance;
use App\Enums\CreditPurchaseKind;
use App\Enums\GenerationStatus;
use App\Models\CreditPurchase;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Billing\OpeningBalances;
use Database\Seeders\DatabaseSeeder;

function chargeUser(User $user, int $chargeNanos): void
{
    $generation = Generation::factory()->create([
        'user_id' => $user->id,
        'status' => GenerationStatus::Succeeded,
    ]);

    LedgerEntry::query()->create([
        'generation_id' => $generation->id,
        'user_id' => $user->id,
        'provider_cost_nanos' => $chargeNanos,
        'charge_nanos' => $chargeNanos,
        'markup_percent' => '25',
    ]);
}

it('sums only the user purchases, charges and holds', function () {
    $user = User::factory()->withCredit('5')->create();
    $other = User::factory()->withCredit('100')->create();

    chargeUser($user, 1_000_000_000);
    chargeUser($other, 7_000_000_000);

    Generation::factory()->create(['user_id' => $user->id, 'status' => GenerationStatus::Queued, 'maximum_charge_nanos' => 300_000_000]);
    Generation::factory()->create(['user_id' => $user->id, 'status' => GenerationStatus::Processing, 'maximum_charge_nanos' => 200_000_000]);
    Generation::factory()->create(['user_id' => $user->id, 'status' => GenerationStatus::Failed, 'maximum_charge_nanos' => 900_000_000]);
    Generation::factory()->create(['user_id' => $other->id, 'status' => GenerationStatus::Queued, 'maximum_charge_nanos' => 800_000_000]);

    $balance = app(GetCreditBalance::class)->handle($user);

    expect($balance->purchased->nanos)->toBe(5_000_000_000)
        ->and($balance->charged->nanos)->toBe(1_000_000_000)
        ->and($balance->held->nanos)->toBe(500_000_000)
        ->and($balance->balance()->nanos)->toBe(4_000_000_000)
        ->and($balance->available()->nanos)->toBe(3_500_000_000);
});

it('is all zero for a user with no rows', function () {
    $balance = app(GetCreditBalance::class)->handle(User::factory()->create());

    expect($balance->purchased->nanos)->toBe(0)
        ->and($balance->available()->nanos)->toBe(0);
});

it('backfills one opening balance per charged user and none for the others', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $third = User::factory()->create();

    chargeUser($first, 1_000);
    chargeUser($first, 2_500);
    chargeUser($second, 4_000);

    OpeningBalances::backfill();

    $rows = CreditPurchase::query()->orderBy('user_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->user_id)->toBe($first->id)
        ->and($rows[0]->kind)->toBe(CreditPurchaseKind::OpeningBalance)
        ->and($rows[0]->amount_nanos)->toBe(3_500)
        ->and($rows[0]->idempotency_key)->toBeNull()
        ->and($rows[1]->user_id)->toBe($second->id)
        ->and($rows[1]->amount_nanos)->toBe(4_000)
        ->and(CreditPurchase::query()->where('user_id', $third->id)->exists())->toBeFalse();

    $balance = app(GetCreditBalance::class)->handle($first);

    expect($balance->balance()->nanos)->toBe(0)
        ->and($balance->available()->nanos)->toBe(0);
});

it('keeps credit purchases append-only', function () {
    $user = User::factory()->withCredit()->create();
    $purchase = CreditPurchase::query()->where('user_id', $user->id)->sole();

    expect(fn () => $purchase->update(['amount_nanos' => 1]))->toThrow(LogicException::class, 'Credit purchases are append-only.')
        ->and(fn () => $purchase->delete())->toThrow(LogicException::class, 'Credit purchases are append-only.');
});

it('funds the seeded test user', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->sole();

    expect(app(GetCreditBalance::class)->handle($user)->available()->nanos)->toBe(10_000_000_000);
});
