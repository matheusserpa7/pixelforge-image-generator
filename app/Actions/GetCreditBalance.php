<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\CreditBalance;
use App\Enums\GenerationStatus;
use App\Models\CreditPurchase;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Money\Money;

final class GetCreditBalance
{
    public function handle(User $user): CreditBalance
    {
        $purchased = (int) CreditPurchase::query()->where('user_id', $user->id)->sum('amount_nanos');
        $charged = (int) LedgerEntry::query()->where('user_id', $user->id)->sum('charge_nanos');
        $held = (int) Generation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->sum('maximum_charge_nanos');

        return new CreditBalance(
            Money::ofNanos($purchased),
            Money::ofNanos($charged),
            Money::ofNanos($held),
        );
    }
}
