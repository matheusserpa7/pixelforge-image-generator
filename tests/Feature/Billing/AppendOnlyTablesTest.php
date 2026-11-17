<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function seedLedgerEntry(): int
{
    $generation = Generation::factory()->create(['status' => GenerationStatus::Succeeded]);

    return DB::table('ledger_entries')->insertGetId([
        'generation_id' => $generation->id,
        'user_id' => $generation->user_id,
        'provider_cost_nanos' => 100,
        'charge_nanos' => 125,
        'markup_percent' => '25',
    ]);
}

it('refuses raw updates and deletes on ledger_entries', function () {
    $id = seedLedgerEntry();

    expect(fn () => DB::table('ledger_entries')->where('id', $id)->update(['charge_nanos' => 1]))
        ->toThrow(QueryException::class, 'ledger_entries is append-only')
        ->and(fn () => DB::table('ledger_entries')->where('id', $id)->delete())
        ->toThrow(QueryException::class, 'ledger_entries is append-only')
        ->and(DB::table('ledger_entries')->where('id', $id)->exists())->toBeTrue();
});

it('refuses raw updates and deletes on credit_purchases', function () {
    $user = User::factory()->create();
    $id = DB::table('credit_purchases')->insertGetId([
        'user_id' => $user->id,
        'kind' => 'purchase',
        'amount_nanos' => 1000,
    ]);

    expect(fn () => DB::table('credit_purchases')->where('id', $id)->update(['amount_nanos' => 1]))
        ->toThrow(QueryException::class, 'credit_purchases is append-only')
        ->and(fn () => DB::table('credit_purchases')->where('id', $id)->delete())
        ->toThrow(QueryException::class, 'credit_purchases is append-only')
        ->and(DB::table('credit_purchases')->where('id', $id)->exists())->toBeTrue();
});

it('still nulls user_id on credit purchases when the user is deleted', function () {
    $user = User::factory()->create();
    $id = DB::table('credit_purchases')->insertGetId([
        'user_id' => $user->id,
        'kind' => 'purchase',
        'amount_nanos' => 1000,
    ]);

    $user->delete();

    expect(DB::table('credit_purchases')->where('id', $id)->value('user_id'))->toBeNull();
});
