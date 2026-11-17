# 010 Review hardening: tech spec

Status: **approved** · 2026-11-17 · PRD: [prd.md](prd.md)

Amends ADR 0004, 0005, 0006, 0010, 0011, 0012 and 0024. It adds no new ADR: each change is a
consequence of a decision that already exists. The provider contract is
[docs/product/pixelforge-api.md](../../product/pixelforge-api.md). Where this file and that summary
disagree, the summary wins.

### Documents at close

The main session updates these after the last task. The tasks do not edit `docs/`, `README.md` or
`CLAUDE.md`. They do edit `.env.example` and `config/`.

- **ADR 0004**, consequences: the markup is copied onto the generation (`generations.markup_percent`)
  when it is created, and its charge uses that copy. A config change only affects new generations.
- **ADR 0005**, consequences: a usage whose `total_tokens` differs from the sum of the four priced
  types is refused as unreadable and never charged in part.
- **ADR 0006**, consequences: the pricing cache defaults to 300 s.
- **ADR 0010**, consequences: MySQL triggers refuse `UPDATE` and `DELETE` on `ledger_entries` and
  `credit_purchases`. Foreign key `SET NULL` still works because cascaded actions do not fire triggers.
- **ADR 0011**, consequences: reconciliation also redispatches webhook events left unprocessed, and
  reports once at error level a generation with a provider id still in flight after
  `PIXELFORGE_STUCK_AFTER_SECONDS` (default 3600). The admin report lists them. They are never failed
  locally.
- **ADR 0012**, consequences: `429 rate_limit_exceeded` waits without using an attempt, like
  concurrency. The per-minute counter is incremented before it is checked (atomic). A waiting submit job
  touches `updated_at`.
- **ADR 0024**, decision: the generation stores its maximum token counts. When its own rates are first
  seen, the hold rises to the maximum charge at those rates if that is higher, and never goes down.
- **`CLAUDE.md`** fixed decisions:
  - 2: append "frozen on the generation at creation".
  - 8: append "enforced by DB triggers".
  - 9: append "and reports generations still in flight after an hour".
  - 10: "Concurrency and per-minute waits never use an attempt."
  - 16: append "raised to the maximum at the generation's own rates once they are known".
- **`docs/product/overview.md`**, glossary: Markup "(configurable; fixed on each generation when it is
  created)". Held credit: "… maximum charge, recomputed at the generation's own rates once known".
- **`docs/roadmap.md`**: add the row `010 | 010-review-hardening | Freeze markup, honest holds, refuse
  unpriced usage, report stuck generations, per-minute waits without attempts. | Done`.

## Technical context

The relevant code today:

- `CreateGeneration` estimates with the config markup, then locks the user row, checks `available` and
  stores the four estimate columns.
- `ApplyGenerationSnapshot` is the only writer of provider status, rates, usage and the ledger, under a
  row lock on the generation. `applySucceeded` reads `config('billing.markup_percent')` at line 132.
  `fillNullsOnly` and `applyProcessing` store `rates` the first time they see them.
- `ActualCost::calculate(TokenUsage, GenerationRates, string $markup)` returns `providerCost` and
  `charge`. It works for any token counts, including maxima.
- `CostEstimator::estimate()` already computes the maximum token counts (`$textMaximum`, `$imageTokens`,
  `$outputTokens`, `$reasoningMaximum`) but does not return them.
- `GetCreditBalance` sums `maximum_charge_nanos` of `queued` and `processing` rows as the hold.
- `PixelforgeGeneration::parseUsage()` reads the four known keys and ignores the rest. `DecimalJson` has
  already turned every number into a string.
- `ProcessWebhookEvent` marks an unreadable payload as processed (`InvalidPayload`). `PollGeneration`
  logs a warning on `invalid_response` and returns.
- `ReconcileGenerations::handle()` returns `['polled' => int, 'resubmitted' => int]`. It touches
  `updated_at` on what it dispatches.
- `SubmitGeneration` handles `429 concurrency_limit_exceeded` without an attempt (it decrements
  `submit_attempts` and releases). Every other retryable error counts an attempt, and attempts are
  capped at 5.
- `PixelforgeRequestLimiter::run()` and `attempt()` call `RateLimiter::tooManyAttempts()` and then
  `RateLimiter::hit()`.
- `LedgerEntry` and `CreditPurchase` refuse updates and deletes in Eloquent model events only.

Feature tests: MySQL `testing`, `RefreshDatabase`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`. Run
jobs by calling `handle()` on a fresh instance. Call `withFakeQueueInteractions()` first when a test
expects `release()`. Never call the real API.

## Model and contracts

### Migration A (T1): `add_markup_and_maximum_tokens_to_generations_table`

- `markup_percent` `string(32)`, added nullable after `maximum_charge_nanos`, then backfilled:
  1. From `ledger_entries.markup_percent` where a ledger row exists for the generation.
  2. Every other row from `config('billing.markup_percent')` at migration time.

  Then change it to `NOT NULL`. `down()` drops it.
- `maximum_tokens` `json`, nullable, after `markup_percent`. It is not backfilled: old rows keep null,
  and their hold is never recomputed.

### Migration B (T2): `add_stuck_reported_at_to_generations_table`

- `stuck_reported_at` `timestamp`, nullable. It means "operators were told this generation is stuck".

### Migration C (T4): `add_append_only_triggers`

Four triggers created with `DB::unprepared`, each `BEFORE UPDATE` or `BEFORE DELETE … FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '<table> is append-only'`:

- `ledger_entries_no_update`
- `ledger_entries_no_delete`
- `credit_purchases_no_update`
- `credit_purchases_no_delete`

`down()` drops them. MySQL does not fire triggers for cascaded foreign key actions, so `nullOnDelete`
on `user_id` keeps working.

### `Generation` model

- Add `markup_percent` and `maximum_tokens` to `#[Fillable]`.
- Casts: `maximum_tokens` → `array`, `stuck_reported_at` → `datetime`.
- `@property` lines: `string $markup_percent`, `array<string, int>|null $maximum_tokens`,
  `Carbon|null $stuck_reported_at`.
- Factory defaults: `markup_percent => '25'`, `maximum_tokens => null`.

### `CostEstimate`

Add `public TokenUsage $maximumTokens`, built from the maximum counts the estimator already computes
(text maximum, image tokens, output tokens, reasoning maximum).

### Config

- `billing.pricing_cache_seconds`: default `300`. Change the `.env.example` line to `300`.
- `services.pixelforge.stuck_after_seconds`: `env('PIXELFORGE_STUCK_AFTER_SECONDS', 3600)`. Add a
  commented `.env.example` line next to the other `PIXELFORGE_*` tunables. Read it through a static
  helper `ReconcileGenerations::stuckAfterSeconds()` with the same fallback rules as
  `reconcileAfterSeconds()`.

### `GenerationResource`

Add `awaiting_confirmation`: `true` when the status is `queued`, `provider_id` is null and
`submit_unconfirmed` is true. Add the same field to the TS `Generation` type.

### `AdminReport`

Add `public array $stuck` (`list<AdminReportStuckGeneration>`). It is a new readonly DTO with:

- `string $id`
- `?string $userEmail`
- `string $status`
- `string $providerId`
- `CarbonImmutable $createdAt`

Expose it through `AdminReportController` props as `stuck`. It does not depend on the date range.

## Behaviour in code

### T1. Frozen markup and honest holds

- **`CreateGeneration`**: read `$markup = config()->string('billing.markup_percent')` once, pass it to
  `CostEstimator::estimate()`, and store `markup_percent => $markup` and
  `maximum_tokens => $estimate->maximumTokens->toStoredArray()` with the other estimate columns.
- **`ApplyGenerationSnapshot::applySucceeded`**: use `$generation->markup_percent` instead of config,
  for both the charge and the ledger's `markup_percent`.
- **`ApplyGenerationSnapshot`, new private `raiseHold(Generation, GenerationRates)`**:
  - When `maximum_tokens` is null, it does nothing.
  - Otherwise:
    `$max = ActualCost::calculate(TokenUsage::fromStoredArray($generation->maximum_tokens), $rates, $generation->markup_percent)`.
  - When `$max->charge->nanos > $generation->maximum_charge_nanos`, it sets `maximum_provider_cost_nanos`
    and `maximum_charge_nanos` to the new values.
  - The caller saves.
- **When `raiseHold` runs**: call it exactly where `rates` goes from null to a value on a non-final
  outcome, before the save.
  - In `fillNullsOnly`, inside the `rates === null` branch. The `Incomplete` branch reaches it too,
    which is fine: that generation stays in flight.
  - In `applyProcessing`, only if `rates` was null before the assignment.
  - Not in `applyFailed` or `applySucceeded`: the hold disappears there.
- **Unchanged**: the estimate columns and `estimated_charge_nanos`. The "up to" in the history reads
  `maximum_charge`, so it shows the raised value.

### T2. Refuse unpriced usage, report stuck generations, sweep webhooks

- **`PixelforgeGeneration::parseUsage`**: when the map has `total_tokens`, parse it with
  `requireNonNegativeIntString`. If it differs from the sum of the four types (compute the sum with
  `BigInteger`), throw
  `InvalidArgumentException('usage.total_tokens does not match the priced token types.')`. When it is
  absent, behave as today. Every caller already treats this exception as an unreadable snapshot:
  - the webhook gives `InvalidPayload`, logged at error level;
  - a poll gives `invalid_response`, logged as a warning;
  - a create gives `invalid_response`, which keeps the generation unconfirmed.
- **`ReconcileGenerations::handle()`** adds two steps after the current two. It returns
  `['polled', 'resubmitted', 'stuck', 'webhooks']`, and the info log includes them when any is above
  zero.
  - **Stuck generations**:
    - Selection: rows with status `queued` or `processing`, `provider_id` not null,
      `stuck_reported_at` null and `created_at <= now - stuckAfterSeconds()`, ordered by `created_at`,
      `id`, limit `BATCH_SIZE`.
    - For each row: `Log::error('Pixelforge generation stuck.', ['generation_id', 'provider_id',
      'status', 'created_at' (ISO 8601)])`.
    - Then one `update(['stuck_reported_at' => now()])` on those ids.
    - It never fails a generation and never touches `updated_at`.
  - **Unprocessed webhooks**:
    - Selection: `webhook_events` with `processed_at` null and `created_at` between `now - 1 day` and
      `now - 300 s`. It also needs `updated_at <= now - 300 s`, so an event is redispatched at most
      every 5 minutes. Ordered by `id`, limit `BATCH_SIZE`.
    - Touch `updated_at` on those rows, then `ProcessWebhookEvent::dispatch($id)` for each.
    - `ProcessWebhookEvent` already returns early when `processed_at` is set, so a duplicate dispatch is
      harmless.
- **`BuildAdminReport::handle()`**: fill `stuck` with every in-flight generation with a provider id
  older than `stuckAfterSeconds()`, reported or not, ordered by `created_at`, limit 50. It
  left-joins users for the email.
- **`admin/Report.vue`**: when `stuck` is not empty, add a "Needs attention" section above the totals.
  - It is a table with Generation id, User (email or "Deleted user"), Status, Provider id and Created
    (UTC).
  - Its intro line: "In flight longer than expected. Pixelforge may have billed these; check them in
    the Pixelforge dashboard."
  - Same table components and styles as the existing tables.

### T3. Per-minute waits without attempts, atomic budget, no duplicate jobs

- **`PixelforgeRequestLimiter::run()` and `attempt()`**: replace the check-then-hit with
  `$hits = RateLimiter::hit(self::RATE_KEY, 60);`. If `$hits > requestsPerMinute()`, throw
  `new PixelforgeBusy(max(1, RateLimiter::availableIn(self::RATE_KEY)))`. In `run()` this happens after
  the slot lock is taken, and the lock is still released in `finally`. Over-budget hits only raise the
  counter until the window resets, which is fine.
- **`SubmitGeneration`, the `429` branch**:
  - It applies to `errorCode` `concurrency_limit_exceeded` **or** `rate_limit_exceeded`.
  - Same body as today: decrement `submit_attempts` when it is above 0, then release after
    `max(retryAfterSeconds ?? 0, concurrencyRetrySeconds())`.
  - The `submit_unconfirmed` assignment before it does not change. `rate_limit_exceeded` falls under
    `createdNothing()`, which keeps the previous value.
- **`SubmitGeneration`, waits**: every path that calls `$this->release()` to wait first runs
  `Generation::query()->whereKey($this->generationId)->update(['updated_at' => now()])`. Those paths
  are:
  - the in-flight count is full;
  - `PixelforgeBusy`;
  - the `429` branch above;
  - the retryable-error backoff.

  This keeps the reconciler's "lost submit job" rule (queued, no provider id, `updated_at` older than
  600 s) true only for jobs that are really gone.

### T4. Append-only in the database

Migration C only. Keep the model guards: they give a clear exception before the query.

### T5. Clearer states and errors

- **`GenerationCard.vue`**: when `generation.awaiting_confirmation`, replace the queued line with
  "Still confirming with the image service. You are only charged if it completes."
- **`GenerationFailureMessage::forCode`**: append " Try again." to the messages for `generation_failed`,
  `internal_error`, `connection_failed`, `rate_limit_exceeded`, `concurrency_limit_exceeded`,
  `overloaded`, `submit_failed`, `invalid_response` and the `default`. Messages already stored on failed
  rows do not change.
- **`HealthController`**: respond `{"status": "ok"}` (200) or `{"status": "fail"}` (503). It keeps
  logging each failed check by name as today.

## Tests

Pest, in the existing files where they fit. Values are in nanos, with rates 5 000 / 10 000 / 40 000 /
12 500 per token unless stated.

| Task | Test |
| --- | --- |
| T1 | `CostEstimator`: `maximumTokens` for high, 1536x1024, 400 chars, 2 references is text 115, image 1 530, output 6 450, reasoning 8 000 (the API doc's worked example). |
| T1 | `CreateGeneration`: stores the config markup and `maximum_tokens`. |
| T1 | Markup frozen: generation created with `'25'`, config set to `'40'`, `succeeded` applied → charge = provider cost × 1.25 and the ledger `markup_percent` is `'25'`. |
| T1 | Markup with half-up: `markup_percent '12.5'`, rates 1 nano per token, usage text 3 → provider 3, charge 3 (3.375 rounds to 3). Usage text 4 → charge 5 (4.5 rounds up). |
| T1 | Hold raised: `maximum_tokens` set, `maximum_charge_nanos` at single rates. A `processing` snapshot with doubled rates raises both maxima to `ActualCost` of the maximum tokens at the doubled rates. `GetCreditBalance::held` reflects it. A second `CreateGeneration` that fitted only the old hold throws `InsufficientCredits`. |
| T1 | Hold never lowered: rates halved → maxima unchanged. |
| T1 | Hold only on first rates: a later `processing` snapshot with different rates changes nothing. |
| T1 | Legacy row (`maximum_tokens` null) → maxima unchanged. |
| T1 | Migration backfill: a succeeded row with a ledger row at `'30'` gets `'30'`; a queued row gets the config value. One test that runs the migration's backfill code against seeded rows is enough. |
| T2 | `PixelforgeGeneration::fromDecoded`: `total_tokens` equal to the sum → accepted. Different → `InvalidArgumentException`. Absent → accepted. |
| T2 | Webhook with a mismatched `total_tokens` on `succeeded` → no ledger row, status unchanged, outcome `InvalidPayload`. |
| T2 | Reconcile stuck: an in-flight row with a provider id created 61 minutes ago → one `Log::error`, `stuck_reported_at` set. A second run → no new log. A row 59 minutes old → nothing. A final row → nothing. A row without a provider id → nothing. |
| T2 | Reconcile webhooks: an unprocessed event 6 minutes old is dispatched and touched. A second run within 5 minutes skips it. 2 minutes old → skipped. 2 days old → skipped. Processed → skipped. |
| T2 | Admin report: the stuck list contains the old in-flight row (including a deleted user, shown as null email), not the recent or final ones. The page renders the section only when the list is not empty. |
| T3 | Limiter: with the budget at its last request, the first `run()` reaches the callback and the second throws `PixelforgeBusy`, the counter having been incremented before the check. Same for `attempt()`. |
| T3 | Submit: `429 rate_limit_exceeded` with `Retry-After: 7` → released after 7, `submit_attempts` unchanged, status `queued`. Ten in a row → still `queued`, attempts unchanged. |
| T3 | Submit: each wait path touches `updated_at` (freeze time, travel, assert it moved). |
| T3 | Reconcile: a queued row whose job is waiting (recent `updated_at`) is not resubmitted. |
| T4 | Raw `DB::table('ledger_entries')->update(...)` and `->delete()` throw `QueryException`. Same for `credit_purchases`. The existing "nulls user id … when the user is deleted" test still passes. |
| T5 | `GenerationResource`: `awaiting_confirmation` true only for queued + no provider id + unconfirmed. |
| T5 | `GenerationFailureMessage`: transient codes end with "Try again."; `content_policy_violation` and `daily_limit_exceeded` do not. |
| T5 | Health: the body is exactly `{"status":"ok"}`, and `{"status":"fail"}` with 503 when Redis fails. Update `HealthCheckTest`. |

Existing tests that set `billing.markup_percent` to change a charge must now set `markup_percent` on the
generation instead.

## Risks

- **Trigger privilege.** With binary logging on (the MySQL 8.4 default), `CREATE TRIGGER` may require
  `SUPER` or `log_bin_trust_function_creators=1` for the app user. Check it in dev, in `testing` and
  against the production compose before writing T4. If the app user cannot create triggers, **stop and
  ask**. Do not grant privileges or change the MySQL command on your own.
- **Production env.** If the production `.env` sets `BILLING_PRICING_CACHE_SECONDS=3600` explicitly, the
  new default does not apply. The closing note to the maintainer must say so.
- **A stuck generation keeps its hold.** That is deliberate: the provider may bill it. The report makes
  it visible. A refund or operator action is out of scope.
- **A new token type stops charging**, by design. The generation stays in flight and is reported after
  an hour, so it is noticed instead of undercharged.
- **Touching `updated_at` while waiting** costs one small `UPDATE` per wait (every 5 s per waiting
  generation). That is acceptable at this scale.
- **The raised hold is not a quote.** The user was shown the old maximum. The history then shows the
  new "up to", and the existing copy already says "unless Pixelforge changes its prices".
