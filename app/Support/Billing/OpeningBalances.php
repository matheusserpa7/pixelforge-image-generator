<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\CreditPurchaseKind;
use Illuminate\Support\Facades\DB;

/**
 * Gives every user who already has charges an opening balance equal to those
 * charges, so existing users start at a balance of exactly zero.
 */
final class OpeningBalances
{
    public static function backfill(): void
    {
        DB::statement(
            'INSERT INTO credit_purchases (user_id, kind, amount_nanos, created_at) '
            .'SELECT user_id, ?, SUM(charge_nanos), NOW() FROM ledger_entries '
            .'WHERE user_id IS NOT NULL GROUP BY user_id HAVING SUM(charge_nanos) > 0',
            [CreditPurchaseKind::OpeningBalance->value],
        );
    }
}
