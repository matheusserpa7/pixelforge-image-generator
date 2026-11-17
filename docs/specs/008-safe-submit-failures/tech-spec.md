# 008 Safe submit failures: tech spec

Status: **implemented** · 2026-11-16 · PRD: [prd.md](prd.md)

Corrects spec 002 on top of spec 003. Implements ADR 0007, ADR 0009, ADR 0010 and ADR 0012 more
faithfully. The provider contract is [docs/product/pixelforge-api.md](../../product/pixelforge-api.md);
where this file and that summary disagree, the summary wins.

**Precondition:** spec 003 is `implemented` (webhook processing finds a generation by
`metadata.generation_id`; `ReconcileGenerations` polls rows with a `provider_id` and redispatches
`SubmitGeneration` for `queued` rows without one after 600 s; `PollGeneration` exists). If it is not,
stop and ask.

### Documents at close

- New ADR `0021-local-failure-only-when-provider-cannot-have-it.md` (context, decision, consequences
  from "Decisions this spec locks"), listed in `docs/adr/README.md`, pointing to this folder.
- ADR 0012 consequences: the submit job counts our generations in flight at the provider (queued or
  processing with a provider id) against the concurrency limit; a full count or a
  `429 concurrency_limit_exceeded` waits without using an attempt; every `5xx` is retried.
- `CLAUDE.md` fixed decision 10: append "Concurrency waits never use an attempt. A generation is failed
  locally only when the provider cannot have it (ADR 0021)."
- `docs/roadmap.md`: add the row `008 | 008-safe-submit-failures | Never fail locally a generation the
  provider may have; bursts wait for a concurrency slot. | Done`, placed right after 003 (it is
  delivered before 004). Do not renumber the planned specs.
- `docs/product/` does not change.

Do not edit `docs/product/`, `docs/adr/`, `docs/roadmap.md`, `README.md` or `CLAUDE.md` in the tasks.

## Technical context

Spec 002 left `SubmitGeneration` (rate-limited create, backoff, `submit_attempts`, local failure through
`FailGeneration`), `PixelforgeRequestLimiter` (cache locks for HTTP concurrency, cache rate limiter for
60 per minute), `ApplyGenerationSnapshot` (only writer of provider status, usage, rates, ledger) and
`PixelforgeException::retryable` from spec 001. Spec 003 added webhooks and reconciliation and does not
change those classes.

The provider facts this spec relies on (API summary, "Idempotency" and "Errors"):

- Rejected requests (`429`, `503 overloaded`, `4xx`) create nothing.
- An idempotent replay returns the existing generation and **does not count against the concurrency or
  daily limits**. So a `429 concurrency_limit_exceeded` or `429 daily_limit_exceeded` for our key
  proves the key was never accepted: had it been, the provider would have replayed it.
- Validation (`422`) and content policy (`400`) depend only on the request, which never changes for a
  generation. If they reject it now, they rejected every earlier attempt too.
- `429 rate_limit_exceeded` **does** apply to replays ("every retry counts towards the per-minute
  limit"), so it proves nothing about earlier attempts. Neither does `401`, a dropped connection, a
  `5xx`, or an unreadable `2xx`.

Feature tests: MySQL `testing`, `RefreshDatabase`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`. Run
jobs by calling `handle()` on a fresh instance; call `withFakeQueueInteractions()` first when a test
expects `release()`. Clear the limiter keys like spec 002's job tests.

No Vue page, no browser check.

### Decisions this spec locks

- New column `generations.submit_unconfirmed` (boolean): "a create request for this generation may
  have been accepted by the provider and we have not seen the answer". It is only meaningful while
  `provider_id` is null.
- It is set to `true` in the same save that increments `submit_attempts`, right before
  `PixelforgeClient::create`. A worker that dies during the call therefore leaves it `true`.
- After a `PixelforgeException` it becomes: `false` when the error proves the key unknown; its value
  from before this attempt when the error proves only that this attempt created nothing; otherwise it
  stays `true`.
- A generation with `submit_unconfirmed = true` and no `provider_id` is never failed by the submit job
  or by its `failed()` hook, except by the give-up window (default 86 400 s since `created_at`). It
  stays `queued`; a webhook or spec 003's resubmission (same idempotency key, so a replay) resolves it.
- Every `5xx` is retryable. `invalid_response` stays non-retryable (spec 001), but it is uncertain, so
  it no longer fails a generation by itself.
- A `succeeded` snapshot without usage now stores the provider id, rates and provider timestamps (fill
  nulls only) and still returns `incomplete`, with no status change and no ledger row. The submit job
  ends on it; spec 003's polling fetches the full object.
- Concurrency means generations in flight at the provider, as the API defines it. Before taking a
  limiter slot, the submit job counts generations with status `queued` or `processing` and a non-null
  `provider_id`. At or above `concurrency_limit` it releases for `concurrency_retry_seconds` without
  calling the provider and without using an attempt. The HTTP-call locks in `PixelforgeRequestLimiter`
  stay as they are (they also guard `PollGeneration`).
- A `429 concurrency_limit_exceeded` gives the attempt back (`submit_attempts` decremented) and releases
  for `max(Retry-After, concurrency_retry_seconds)`, with no backoff. Only `retryUntil()` (6 h) bounds
  it.

## Model and contracts

All PHP files `declare(strict_types=1)`, classes `final`. No floats.

### Migration (task T2)

New migration `add_submit_unconfirmed_to_generations_table`:

- `submit_unconfirmed` boolean, not null, default `false`, after `submit_attempts`.
- Change `submit_attempts` from `unsignedTinyInteger` to `unsignedSmallInteger`, default 0. A row kept
  unconfirmed is resubmitted every 10 minutes for up to 24 hours, which can pass 255 attempts with a
  larger give-up window.

`down()` reverses both. Cast `submit_unconfirmed` to `boolean` on `Generation`. Do not add it to
`$fillable`; the factory default is `false`.

### Config (task T2)

Under `services.pixelforge`, read by a public static `PixelforgeRequestLimiter::submitGiveUpSeconds()`
with the same numeric / below-1 fallback as the others. Add the commented line to `.env.example`.

| Key | Env | Default |
| --- | --- | --- |
| `submit_give_up_seconds` | `PIXELFORGE_SUBMIT_GIVE_UP_SECONDS` | 86400 |

### `PixelforgeException` (task T1)

Classification changes:

- `retryable` is also true for **any** `status >= 500` (any code, including `unknown_error`). The
  existing true cases stay. `invalid_response` stays false whatever its status.

New public methods, pure functions of `status` and `errorCode`:

- `provesKeyUnknown(): bool` — true only for `429 concurrency_limit_exceeded`,
  `429 daily_limit_exceeded`, `422 validation_failed`, `400 content_policy_violation`.
- `createdNothing(): bool` — true when `provesKeyUnknown()`, or `status` is 400–499 and `errorCode` is
  not `idempotency_key_reused`, or `status === 503 && errorCode === 'overloaded'`. False for
  `connection_failed`, `invalid_response`, any other `5xx`, and `409 idempotency_key_reused` (the key
  exists at the provider with other parameters, which should be impossible for us).

### `ApplyGenerationSnapshot` (task T1)

Only the incomplete branch changes. When the snapshot is `succeeded` with null usage (after the missing
row, provider id mismatch and final checks, which stay first): call the existing `fillNullsOnly` and
then return `SnapshotOutcome::Incomplete` whatever it returned. Never set status, usage, costs, image
columns or a ledger row in this branch.

## Behaviour in code

### Submit job (task T2)

`SubmitGeneration::handle`, in this order:

1. Load the generation. Missing, final, or `provider_id` not null → return (unchanged).
2. **Give-up.** If `submit_unconfirmed` and `created_at <= now()->subSeconds(submitGiveUpSeconds())` →
   `FailGeneration` with `submit_failed` and its mapped message, log `warning`
   "Pixelforge submit gave up while unconfirmed." with `generation_id`, return.
3. **In-flight gate.** Count `Generation` rows with status in (`queued`, `processing`) and
   `provider_id` not null. If the count is `>= concurrencyLimit()` → `release(concurrencyRetrySeconds())`
   and return. Do not touch `submit_attempts` or `submit_unconfirmed`.
4. Build the request (unchanged). Keep `$wasUnconfirmed = $generation->submit_unconfirmed`.
5. `$limiter->run(...)`. Inside the callback: `submit_attempts` + 1 and `submit_unconfirmed = true` in
   one `save()`, then `create()`.
6. `PixelforgeBusy` → `release($busy->retryAfterSeconds)`, return (unchanged; the callback did not run).
7. `PixelforgeException $e`:
   1. `refresh()`. Set `submit_unconfirmed` to `false` if `$e->provesKeyUnknown()`, else to
      `$wasUnconfirmed` if `$e->createdNothing()`, else `true`. `save()`.
   2. If `$e->status === 429 && $e->errorCode === 'concurrency_limit_exceeded'`: decrement
      `submit_attempts` (guarded so it never goes below 0) and
      `release(max($e->retryAfterSeconds ?? 0, concurrencyRetrySeconds()))`. Return.
   3. If `$e->retryable` and `submit_attempts < maxSubmitAttempts()`: log the existing warning and
      `release(SubmitBackoff::seconds(...))` as today. Return.
   4. If `submit_unconfirmed`: log `warning` "Pixelforge submit unconfirmed; waiting for the
      provider." with `generation_id` and `error_code`. Return without releasing and without failing.
   5. Otherwise `FailGeneration` with `$e->errorCode` and the mapped message (unchanged).
8. Any other exception propagates (unchanged). `submit_unconfirmed` stays `true` from step 5.
9. On a `CreatedGeneration`, `ApplyGenerationSnapshot`. Every outcome ends the job. On `Incomplete`,
   log `warning` "Pixelforge submit returned success without usage." with `generation_id`. Remove the
   spec 002 branch that released or failed with `invalid_response` on `Incomplete`.

`failed(Throwable $exception)`: load the row. If it exists, `provider_id` is null and
`submit_unconfirmed` is true → log `warning` "Pixelforge submit job failed while unconfirmed." with
`generation_id` and return. Otherwise `FailGeneration` with `submit_failed` as today. Spec 003's
resubmission picks the unconfirmed row up again, and step 2 bounds it.

Logs never include the prompt, URLs, the API key, response bodies or exception messages.

### What recovers an unconfirmed row (no new code)

- A webhook: spec 003's `ProcessWebhookEvent` finds the row by `metadata.generation_id` and applies the
  snapshot; the row is still `queued`, so the rank rules apply normally.
- Resubmission: after 600 s untouched, `ReconcileGenerations` dispatches `SubmitGeneration`. Step 5
  sends the same idempotency key; a provider that has it replays it (`2xx`), and step 9 stores it.

## Tests

Pest. `Http::preventStrayRequests()` in feature tests; never call `pixelforge.promptedit.dev`. Bind
`FakePixelforgeClient` with `$this->app->instance(...)`. Compare money by nanos.

| Test | Task | Proves |
| --- | --- | --- |
| `tests/Unit/Services/Pixelforge/PixelforgeExceptionTest.php` (new) | T1 | `provesKeyUnknown` true for the four cases and false for `429 rate_limit_exceeded`, `401`, `503 overloaded`, `connection`, `invalidResponse`. `createdNothing` true for those four, `429 rate_limit_exceeded`, `401 invalid_api_key`, `404`, `503 overloaded`; false for `connection`, `invalidResponse`, `500 unknown_error`, `502`, `503 other_code`, `409 idempotency_key_reused`. `retryable` true for `500`, `502`, `504`; still false for `daily_limit_exceeded` and `invalidResponse`. |
| `tests/Feature/Services/Pixelforge/HttpPixelforgeClientTest.php` | T1 | A `500` with a non-JSON body is `unknown_error`, status 500, retryable. Existing cases stay green. |
| `tests/Feature/Generations/ApplyGenerationSnapshotTest.php` | T1 | Replace "returns incomplete for succeeded without usage and leaves provider id null": now it returns `incomplete`, stores `provider_id`, rates nanos and `provider_created_at`, keeps status `queued`, writes no ledger row. A later `succeeded` with usage on the same row charges once. A mismatched provider id still returns `ignored`. |
| `tests/Feature/Jobs/SubmitGenerationTest.php` | T2 | **Unconfirmed:** with `max_submit_attempts` 1, a connection failure leaves `queued`, `provider_id` null, `submit_unconfirmed` true, no ledger, no release. The same for `invalidResponse`. A `500` with attempts left releases with backoff and keeps `submit_unconfirmed` true. **Replay recovery:** register the key in the fake by calling `$fake->create($request)` directly, then a job run with a connection failure (max 1) stays unconfirmed, and a second `handle()` stores the replayed `provider_id`. **Restore:** an unconfirmed row whose next attempt gets `429 rate_limit_exceeded` (max reached) stays `queued` and unconfirmed; a confirmed row in the same case fails with the rate-limit message. **Proof:** an unconfirmed row that gets `422 validation_failed`, `400 content_policy_violation` or `429 daily_limit_exceeded` fails at once and ends `submit_unconfirmed` false. **Give-up:** with `travel`, an unconfirmed row older than `submit_give_up_seconds` fails with `submit_failed` and the fake's `created` list is unchanged. **Worker death:** a client bound in the test whose `create` throws `RuntimeException` leaves `submit_unconfirmed` true and `submit_attempts` 1; `failed()` then leaves it `queued`. `failed()` on a confirmed row still stores `submit_failed`. **Incomplete:** a scripted `succeeded` with null usage stores `provider_id`, keeps `queued`, writes no ledger, does not release. **Gate:** with `concurrency_limit` 3 and three factory rows `queued`/`processing` with provider ids, the job releases for `concurrency_retry_seconds`, the fake's `created` is empty and `submit_attempts` stays 0; two such rows plus one `succeeded` row let it call `create`. **Concurrency 429:** a fake `429 concurrency_limit_exceeded` with `Retry-After: 5` and `concurrency_retry_seconds` 7 releases for 7, leaves `submit_attempts` 0 and `submit_unconfirmed` false; with `max_submit_attempts` 1 it still releases instead of failing. Update or delete the spec 002 cases that expected the old incomplete and attempt behaviour. |
| `tests/Feature/Webhooks/ProcessWebhookEventTest.php` | T2 | One new case: a row left unconfirmed by a connection failure (max 1) receives a signed `succeeded` event with `metadata.generation_id` and usage through `ProcessWebhookEvent`; it ends `succeeded` with one ledger row equal to `ActualCost` of that usage and those rates. Use spec 003's helpers in `tests/Support/Webhooks.php`. |

## Risks

- The proof codes rest on the provider docs: replays skip the concurrency and daily limits, and
  validation and content policy are deterministic. If the provider checked a limit before the replay,
  a `429 concurrency_limit_exceeded` after an uncertain attempt would clear the flag wrongly, and a
  later cap could fail a generation the provider has. That is the 002 behaviour, so this spec is never
  worse than today.
- An unconfirmed generation stays `queued` for up to 24 hours when the provider is unreachable for good
  (bad key, outage). It costs nothing and the give-up fails it. Spec 004 shows it as queued.
- The in-flight gate counts rows a lost webhook left `queued` or `processing`. They hold a slot until
  spec 003's polling settles them (about two minutes). Two workers can both pass the count; the
  provider's `429 concurrency_limit_exceeded` is the backstop and costs no attempt.
- The gate counts every environment's rows only in its own database. Another environment on the same
  account still triggers provider `429`s, which now wait instead of failing.
- A `409 idempotency_key_reused` keeps the row unconfirmed until the give-up window. It should never
  happen, because a generation's request never changes.
- `invalid_response` on `retrieve` in `PollGeneration` is unchanged (logged, next sweep retries).
