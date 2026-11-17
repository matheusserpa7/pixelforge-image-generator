<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GetCreditBalance;
use App\Actions\PurchaseCredits;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Http\Resources\MoneyView;
use App\Models\CreditPurchase;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class CreditController extends Controller
{
    public function index(GetCreditBalance $getCreditBalance, #[CurrentUser] User $user): Response
    {
        $balance = $getCreditBalance->handle($user);

        /** @var list<string> $packs */
        $packs = config('billing.credit_packs_usd');

        return Inertia::render('credits/Index', [
            'balance' => [
                'available' => MoneyView::of($balance->available()->nanos),
                'balance' => MoneyView::of($balance->balance()->nanos),
                'held' => MoneyView::of($balance->held->nanos),
                'purchased' => MoneyView::of($balance->purchased->nanos),
                'charged' => MoneyView::of($balance->charged->nanos),
            ],
            'packs' => array_map(fn (string $pack): array => [
                'value' => $pack,
                'label' => Money::fromDecimal($pack)->format(2),
            ], $packs),
            'purchases' => CreditPurchase::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (CreditPurchase $purchase): array => [
                    'id' => $purchase->id,
                    'kind' => $purchase->kind->value,
                    'amount' => MoneyView::of($purchase->amount_nanos),
                    'created_at' => $purchase->created_at?->toIso8601String(),
                ])
                ->all(),
            'purchaseKey' => (string) Str::ulid(),
        ]);
    }

    public function purchase(
        PurchaseCreditsRequest $request,
        PurchaseCredits $purchaseCredits,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        try {
            $purchase = $purchaseCredits->handle($user, $request->amount(), $request->idempotencyKey());
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This purchase key cannot be used. Reload the page and try again.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Added '.Money::ofNanos($purchase->amount_nanos)->format(2).' in credits.',
        ]);

        return redirect()->route('credits.index');
    }
}
