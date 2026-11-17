# 007 Credit balances: tech spec

Status: **approved** · 2026-11-17 · PRD: [prd.md](prd.md)

## Technical context

- Charges already live in `ledger_entries` (append-only, unique per generation, written in the same
  transaction that moves the generation to `succeeded` under a row lock; ADR 0010). They are the
  single source of what a user has spent. This spec does **not** change how charges are written.
- Every generation stores `maximum_charge_nanos` at creation (ADR 0006), computed by
  `CreateGeneration` from the cached pricing. That is the amount to hold.
- `CreateGeneration::handle()` already opens a DB transaction to insert the generation and dispatch
  `SubmitGeneration` after commit. The affordability check goes inside that transaction.
- Money is integer nano-USD through `App\Support\Money\Money` (ADR 0003). Browser money goes through
  `App\Http\Resources\MoneyView` (`amount` exact string, `display` formatted); the front end never
  does arithmetic or comparisons on money.
- Feature tests run on MySQL (ADR 0017), so `lockForUpdate()` is real in tests.

## Model and contracts

### Decision (proposed ADR 0024): balances are derived, never stored

No balance column. For a user:

```
purchased = Σ credit_purchases.amount_nanos            (user_id = user)
charged   = Σ ledger_entries.charge_nanos               (user_id = user)
held      = Σ generations.maximum_charge_nanos          (user_id = user, status in queued|processing)
balance   = purchased − charged
available = balance − held
```

Why: the ledger stays the only place a charge is written, so the balance can never disagree with
it; there is no second counter to keep in sync, nothing to update on webhooks, and a hold is
released automatically when the generation becomes final (in the same transaction that writes the
ledger row). Cost: three indexed aggregate queries per read, cheap at this scale.

### Table `credit_purchases` (append-only)

| Column            | Type                                                | Notes                                                  |
| ----------------- | --------------------------------------------------- | ------------------------------------------------------ |
| `id`              | bigint PK                                           |                                                        |
| `user_id`         | `foreignId`, nullable, `constrained()->nullOnDelete()` | same as `ledger_entries`                            |
| `kind`            | string(32)                                          | `CreditPurchaseKind` backed enum: `purchase`, `opening_balance` |
| `amount_nanos`    | bigint                                              | always > 0                                             |
| `idempotency_key` | ulid, nullable, **unique**                          | set for `purchase`, null for `opening_balance`         |
| `created_at`      | timestamp `useCurrent()`                            | no `updated_at`                                        |

Index `(user_id, created_at)`.

Model `App\Models\CreditPurchase`: `UPDATED_AT = null`, `kind` cast to the enum, and the same
`updating`/`deleting` guards that throw `LogicException('Credit purchases are append-only.')` as
`LedgerEntry`. `User::creditPurchases(): HasMany`.

### Migration and opening balance

One migration creates the table and, in the same `up()`, calls
`App\Support\Billing\OpeningBalances::backfill()`, which backfills one `opening_balance` row per
user that has ledger charges, with `amount_nanos = Σ charge_nanos` of that user (a single
`INSERT … SELECT user_id, 'opening_balance', SUM(charge_nanos), NOW() FROM ledger_entries WHERE
user_id IS NOT NULL GROUP BY user_id`). Existing users therefore start at a balance of exactly zero.
`down()` drops the table. Users with no charges get no row.

### Config

`config/billing.php` gains:

```php
// Fake "Buy credits" packs, USD decimal strings (no floats).
'credit_packs_usd' => ['5', '10', '25'],
```

Not read from env. Converted with `Money::fromDecimal()`.

### DTO and actions

- `App\Data\CreditBalance` (readonly): `Money $purchased`, `Money $charged`, `Money $held`, plus
  methods `balance(): Money` (`purchased − charged`) and `available(): Money` (`balance − held`),
  using `Money::minus()`.
- `App\Actions\GetCreditBalance::handle(User $user): CreditBalance`. Three `SUM` queries (use
  `(int)` on the `SUM` result, `0` when null). Held counts only `queued` and `processing`.
- `App\Services\Billing\InsufficientCredits` (exception, like `PricingUnavailable`): carries
  `Money $required` (the maximum charge) and `Money $available`; message:
  `"Not enough credits. This generation can cost up to {required->format()} and you have {available->format()} available. Buy credits to continue."`
- `App\Actions\PurchaseCredits::handle(User $user, Money $amount, string $idempotencyKey): CreditPurchase`.
  Inserts a `purchase` row. If the insert hits the unique key (`UniqueConstraintViolationException`)
  it loads the existing row by key: same user → return it (no new row); other user → throw
  `InvalidArgumentException` (the request turns it into a validation error). Never updates a row.

## Behaviour in code

### Blocking in `CreateGeneration`

Inside the existing `DB::transaction`, **before** creating the generation:

1. `User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail()`: serializes concurrent
   creates for the same user. Settling a generation never takes this lock and can only raise
   `available` (it removes `maximum` from held and adds `actual ≤ maximum` to charged), so it cannot
   make an accepted generation unaffordable.
2. `$available = GetCreditBalance::handle($user)->available()` (inject `GetCreditBalance`).
3. If `$estimate->maximumCharge->nanos > $available->nanos` throw `InsufficientCredits` (the
   transaction rolls back; no row, no job).

The rare case "actual > maximum" (rates changed, ADR 0005) is unchanged: the ledger row is written
in full and the balance may go below zero. No code in the settlement path changes.

### HTTP

| Route                          | Name                | Middleware                        | Controller                    |
| ------------------------------ | ------------------- | --------------------------------- | ----------------------------- |
| `GET /credits`                 | `credits.index`     | `auth`                            | `CreditController@index`      |
| `POST /credits/purchases`      | `credits.purchase`  | `auth`, `throttle:credit-purchases` (10/min per user, defined next to the existing limiters) | `CreditController@purchase` |

- `GenerationController@store` catches `InsufficientCredits` and returns
  `back()->withErrors(['credits' => $exception->getMessage()])`.
- `GenerationController@estimate` additionally injects `GetCreditBalance` and the current user and
  adds to the JSON: `available` (`MoneyView`) and `affordable` (bool,
  `maximumCharge->nanos <= available->nanos`). The comparison is done here, not in Vue.
- `PurchaseCreditsRequest`: `pack` required, string, `in:` the configured packs; `idempotency_key`
  required, `ulid`. Method `amount(): Money`.
- `CreditController@index` renders `credits/Index` with: `balance` (`available`, `balance`, `held`,
  `purchased`, `charged`, each `MoneyView`), `packs` (list of `{value: '5', label: MoneyView display
  with 2 decimals, e.g. "$5.00"}`), `purchases` (latest 20 of the user, `{id, kind, amount: MoneyView,
  created_at}` ISO 8601), and `purchaseKey` (a fresh `Str::ulid()` per render).
- `CreditController@purchase` calls `PurchaseCredits`, flashes toast
  `"Added {amount} in credits."` and redirects to `credits.index`. A key owned by another user
  returns a validation error on `idempotency_key`. Because the redirect renders a new `purchaseKey`,
  each deliberate click is a new purchase and a resent form is not.
- `HandleInertiaRequests::share()` adds `credits` → for an authenticated user a **lazy closure**
  returning `{available: MoneyView}`, `null` for guests.

Controllers stay thin (validate → action → response).

### UI

- `resources/js/Pages/credits/Index.vue`: three figures (Available, Balance, Held by generations in
  progress) with one sentence explaining held credit; one button per pack ("Buy $5.00"), each an
  Inertia form posting `{pack, idempotency_key: purchaseKey}`, disabled while processing; the note
  "Fake purchase for this demo: no payment is taken."; a table of recent purchases (date, kind as
  "Purchase" / "Opening balance", amount).
- Sidebar: a "Credits" nav item (icon `Wallet` from lucide) for every signed-in user, showing
  `credits.available.display` and linking to `credits.index`.
- `GenerateForm.vue`: under the estimate and maximum, "Available: {available.display}". When the
  estimate response says `affordable: false`, disable submit and show
  "Not enough credits for the maximum charge. Buy credits" with a link to `credits.index`. Show the
  server error `errors.credits` the same way as `errors.pricing`.
- Use the existing Wayfinder route helpers, as the admin report did.

### Seed and factories

- `UserFactory` gets a state `withCredit(string $usd = '10')` that creates a `purchase` row after
  creating the user (`afterCreating`, `idempotency_key` = new ULID).
- `DatabaseSeeder` gives the seeded test user a 10 USD purchase so local generation still works.
- Existing feature tests that create generations through `CreateGeneration` or `POST /generations`
  must fund the user with `withCredit()`; update them in T1.

## Tests

Unit:

- `CreditBalance`: balance and available arithmetic, including negative results.

Feature (MySQL):

- `GetCreditBalance`: sums purchases and ledger charges of the user only; held counts queued and
  processing only, never succeeded or failed; other users' rows ignored.
- Opening balance backfill: given ledger rows for two users (and none for a third), each charged
  user gets one `opening_balance` row equal to their charges and the third gets none. Never roll back
  or re-run migrations in tests; put the backfill in a public static method of a small class
  (`App\Support\Billing\OpeningBalances::backfill()`) that the migration calls, and test that method.
- `CreateGeneration`: rejects when maximum > available (no generation row, `SubmitGeneration` not
  pushed, `Queue::fake()`); accepts when maximum == available exactly; a queued generation holds its
  maximum so a second one that no longer fits is rejected; after the first is failed the second fits.
- Concurrency of the gate: assert the user row is locked by checking the query log for
  `for update` on `users` within the create transaction (a true two-connection race is not needed).
- `POST /generations` with insufficient credit: redirect back with `errors.credits`, message contains
  both amounts.
- `POST /generations/estimate`: returns `available` and `affordable` true/false.
- `POST /credits/purchases`: adds the pack; same key twice adds once; key of another user → 422 /
  validation error; pack not in config → validation error; guest → redirect to login.
- `GET /credits`: shows only the user's purchases and the right figures.
- Shared prop `credits.available` present for a user, null for a guest.
- `CreditPurchase` update and delete throw.
- `PurchaseCredits` and the gate never use floats (covered by the existing arch test on money code;
  extend its path list if the new files are outside it).

## Risks

- **Overspending through concurrency.** Mitigated by locking the user row in the create transaction
  and holding the maximum charge of every unfinished generation.
- **Long holds.** An unconfirmed generation can stay `queued` up to
  `PIXELFORGE_SUBMIT_GIVE_UP_SECONDS` (ADR 0021) and keeps its hold that long. Acceptable: it may
  still be charged.
- **Negative balance** when rates change between estimate and submission (ADR 0005). Accepted and
  visible; it only blocks new generations.
- **Backfill on deploy.** The migration runs once on the production database; the opening balance is
  a new row, nothing existing is edited. Rolling back drops only the new table.
- **Existing tests** start failing for lack of credit until they are funded; this is expected and
  fixed in T1.
