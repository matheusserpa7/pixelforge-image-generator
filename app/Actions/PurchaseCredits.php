<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditPurchaseKind;
use App\Models\CreditPurchase;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

final class PurchaseCredits
{
    public function handle(User $user, Money $amount, string $idempotencyKey): CreditPurchase
    {
        try {
            return CreditPurchase::query()->create([
                'user_id' => $user->id,
                'kind' => CreditPurchaseKind::Purchase,
                'amount_nanos' => $amount->nanos,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = CreditPurchase::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing === null) {
                throw $exception;
            }

            if ($existing->user_id !== $user->id) {
                throw new InvalidArgumentException('This purchase key belongs to another user.');
            }

            return $existing;
        }
    }
}
