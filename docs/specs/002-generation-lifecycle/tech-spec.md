# 002 Generation lifecycle: tech spec

Status: **approved** · 2026-11-16 · PRD: [prd.md](prd.md)

Implements ADR 0004, ADR 0005, ADR 0006, ADR 0007, ADR 0009, ADR 0010, ADR 0012 and ADR 0014 on top of
the client and the calculators from spec 001. The provider contract is
[docs/product/pixelforge-api.md](../../product/pixelforge-api.md). Where this file and that summary
disagree, the summary wins.

No new ADR. At close, the main session adds one consequence to ADR 0012: the limiter is the cache rate
limiter plus cache locks on the default cache store (Redis in production because `CACHE_STORE=redis`;
tests stay on `CACHE_STORE=array` and must not open a Redis connection). Also set roadmap 002 to
`Done` and 003 to `Next`, and replace the README status line. Do not edit `docs/product/`, `docs/adr/`,
`docs/roadmap.md`, `README.md` or `CLAUDE.md` in the tasks below. `CLAUDE.md` already states these
rules.

## Technical context

Spec 001 left a client that can price, create and retrieve, and that classifies `retryable` plus
`retryAfterSeconds`. This spec does not change that classification and does not retry inside the
client. `GetCachedPricing`, `CostEstimator` and `ActualCost` stay as they are. Markup still comes from
`(string) config('billing.markup_percent')` at the moment it is applied. Calculators still do not read
config.

Feature tests boot Laravel against MySQL `testing` with `RefreshDatabase`, `CACHE_STORE=array` and
`QUEUE_CONNECTION=sync`. `RefreshDatabase` never commits, so a job dispatched `afterCommit()` does
**not** run during a feature test. Do not remove `afterCommit()` to make a test pass, and do not call
`migrate:fresh`. Assert the dispatch with `Queue::fake()`, and run the job by calling `handle()` on a
fresh instance. Tests that expect `release()` must call `withFakeQueueInteractions()` first: the sync
driver would otherwise run the job again immediately.

There is no Vue page and no browser check.

### Decisions this spec locks

- The submit route is `POST /generations` (`generations.store`), web middleware, `auth`. Guests are
  redirected to `login`. Success redirects to `dashboard` with flash `generation_id`. This is not a
  JSON API. Do not attach the existing `generations` rate limiter; spec 004 does that.
- The local row is inserted as `queued` before the job runs. The job is `SubmitGeneration`, dispatched
  inside that insert's transaction with `afterCommit()`, carrying only the ULID string.
- Provider limits are enforced in `PixelforgeRequestLimiter` through the default cache, not through
  `Redis::funnel` and not by counting non-final rows. A slot is held only for the duration of the HTTP
  call. Generations still in flight at the provider are spaced by the provider's `429`, which the job
  already retries. Counting local non-final rows would stall the queue until webhooks exist.
- Waiting for a slot or for the per-minute budget releases the job and does **not** increment
  `submit_attempts`. Only a call that entered `PixelforgeClient::create` increments it.
- The delay after a retryable provider error is `Retry-After` (or `0` when the exception has none)
  plus `2 ** (submit_attempts - 1)` seconds. The exponent is capped at 20. No other cap, no jitter, no
  float. Limiter waits do not use this formula.
- `invalid_response` stays non-retryable, as the client classified it. A `succeeded` body with no
  usage is not applied; the job treats that case as retryable until attempts run out, then fails
  locally with `invalid_response`. Failing it on the first bad body would make a final `failed` that a
  later complete body could not fix.
- `ApplyGenerationSnapshot` is the only writer of provider status, usage, rates, image URL and the
  ledger. `FailGeneration` is the only writer of a local failure. Spec 003 calls these and does not
  update the generation or the ledger itself. This spec does not add a webhook route, a poll command,
  or an image-download job. The first `succeeded` transition stores `image_url` and
  `image_url_expires_at`; a later snapshot of a final generation changes nothing, including the URL.
- Once `provider_id` is set, the job does not call the provider again. Correctness against a double
  worker still rests on the idempotency key and on the snapshot no-op, not on the overlap middleware.
- Account deletion must keep working. `user_id` is nullable and `nullOnDelete` on both tables. The
  ledger's `generation_id` is `restrictOnDelete`. Eloquent refuses ledger updates and deletes; do not
  add a database trigger.

## Model and contracts

Every new class is `final` and `declare(strict_types=1)`. New enums are backed. DTOs stay `readonly`.
No floats, and no float literals, in `app/Services/Pixelforge` (the existing architecture test scans
that directory). Money columns are signed `bigInteger` nanos. Never a decimal cast.

### Tables

`generations` (task T1):

| Column | Type |
| --- | --- |
| `id` | ULID primary key (`HasUlids`) |
| `user_id` | nullable `foreignId`, `nullOnDelete` |
| `status` | string, 32 |
| `prompt` | text |
| `size` | string, 32 |
| `quality` | string, 32 |
| `reference_images` | json, not null |
| `provider_id` | string, nullable, unique |
| `estimated_provider_cost_nanos` | `bigInteger` |
| `maximum_provider_cost_nanos` | `bigInteger` |
| `estimated_charge_nanos` | `bigInteger` |
| `maximum_charge_nanos` | `bigInteger` |
| `provider_cost_nanos` | `bigInteger`, nullable |
| `charge_nanos` | `bigInteger`, nullable |
| `rates` | json, nullable |
| `usage` | json, nullable |
| `error_code` | string, 64, nullable |
| `error_message` | text, nullable |
| `image_url` | text, nullable |
| `image_url_expires_at` | timestamp, nullable |
| `provider_created_at` | timestamp, nullable |
| `provider_started_at` | timestamp, nullable |
| `provider_completed_at` | timestamp, nullable |
| `submit_attempts` | `unsignedTinyInteger`, default 0 |
| `created_at`, `updated_at` | timestamps |

Indexes: `(user_id, created_at)`, `(status, updated_at)`. No database-level default on
`reference_images`; the model attribute default is `'[]'`.

`ledger_entries` (task T2):

| Column | Type |
| --- | --- |
| `id` | big increments |
| `generation_id` | foreign ULID, unique, `restrictOnDelete` |
| `user_id` | nullable `foreignId`, `nullOnDelete` |
| `provider_cost_nanos` | `bigInteger` |
| `charge_nanos` | `bigInteger` |
| `markup_percent` | string, 32. The config string that was applied, e.g. `25`, not `1.25` |
| `created_at` | timestamp. No `updated_at` (`UPDATED_AT = null`) |

### `GenerationStatus::rank(): int`

`queued` is 0, `processing` is 1, `succeeded` and `failed` are 2. A snapshot may change status only
when `snapshot->rank() > local->rank()`. Same rank is not a move. The two finals cannot replace each
other.

### Stored rates and usage

Add `toStoredArray(): array<string, int>` and `fromStoredArray(array $stored): self` on
`GenerationRates` and on `TokenUsage`.

Rate keys are `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens`. The
integers are **nanos per token** (`Money::nanos`), not the provider's USD-per-1M strings.
`fromStoredArray` accepts only PHP `int` values (reject bools, strings and floats) and builds
`Money::ofNanos`. Usage uses the same four keys as token counts. Negative usage throws
`InvalidArgumentException`. Missing keys throw `InvalidArgumentException`.

The generation model casts `status`, `size` and `quality` to the enums, and `reference_images`,
`rates` and `usage` to `array`. It does not cast money columns to `Money`. `reference_images` is a
list; the action stores `array_values`.

`Generation::$fillable` is only the columns set at insert: `user_id`, `status`, `prompt`, `size`,
`quality`, `reference_images`, and the four estimate nanos. Later writes assign attributes and `save()`.
`User::generations()` is a `HasMany`. `Generation::scopeForUser(Builder $query, User $user)` constrains
`user_id`. `Generation::ledgerEntry()` is a `HasOne` (added with the ledger model).

### Ledger model

`LedgerEntry` fillable: `generation_id`, `user_id`, `provider_cost_nanos`, `charge_nanos`,
`markup_percent`. `booted` throws `LogicException` with message `Ledger entries are append-only.` on
`updating` and on `deleting`. Creates are allowed. No soft deletes.

### `GenerationPolicy`

Discovered by convention. Do not register it by hand.

- `view(User $user, Generation $generation): bool` — `$generation->user_id === $user->id`. A null
  owner is not visible.
- `create(User $user): bool` — `true`.
- `viewAny(User $user): bool` — `true`. Lists still have to call `forUser`. Spec 004 will.
- No `update` or `delete`.

### `SnapshotOutcome`

Backed string enum: `applied`, `ignored`, `incomplete`.

### Failure messages

`App\Services\Pixelforge\GenerationFailureMessage::forCode(string $code): string`.

| Code | Message |
| --- | --- |
| `generation_failed` | The generation failed. |
| `internal_error` | The image service failed while generating the image. |
| `content_policy_violation` | The prompt was rejected by the content policy. |
| `daily_limit_exceeded` | The daily generation limit has been reached. |
| `validation_failed` | The generation request was rejected. |
| `idempotency_key_reused` | This generation could not be submitted again. |
| `invalid_api_key` | The image service rejected the API key. |
| `not_found` | The generation could not be found. |
| `invalid_response` | The image service returned an unexpected response. |
| `connection_failed` | The image service could not be reached. |
| `rate_limit_exceeded` | The image service is rate limiting requests. |
| `concurrency_limit_exceeded` | The image service is at its concurrency limit. |
| `overloaded` | The image service is overloaded. |
| `submit_failed` | The generation could not be submitted. |

Any other code returns `The generation failed.` Store that sentence, never the provider's `message`
(it can echo the prompt). Logs for a retry or a local failure include the generation id and the error
code only. Do not log the prompt, the image URL, the API key, the response body, or the exception
message.

### Limiter and backoff

Config under `services.pixelforge`, each `(int)` of the env value, and each falling back to the
default when the value is not numeric or is below 1. Mirror the timeout fallback already in
`AppServiceProvider`. Add commented lines to `.env.example`.

| Key | Env | Default |
| --- | --- | --- |
| `concurrency_limit` | `PIXELFORGE_CONCURRENCY_LIMIT` | 3 |
| `requests_per_minute` | `PIXELFORGE_REQUESTS_PER_MINUTE` | 60 |
| `max_submit_attempts` | `PIXELFORGE_MAX_SUBMIT_ATTEMPTS` | 5 |
| `concurrency_retry_seconds` | `PIXELFORGE_CONCURRENCY_RETRY_SECONDS` | 5 |

`PixelforgeRequestLimiter` (concrete, no interface) reads those through public static methods so the
job and the tests share one fallback. Constants: `RATE_KEY = 'pixelforge-requests'`,
`LOCK_PREFIX = 'pixelforge-concurrency-'`, `LOCK_SECONDS = 60`.

`run(callable $callback): mixed`

1. Try `Cache::lock(LOCK_PREFIX.$i, LOCK_SECONDS)->get()` for `$i` from 1 to the concurrency limit.
   The first lock wins. If none are free, throw `PixelforgeBusy` with `retryAfterSeconds` equal to
   `concurrency_retry_seconds`. Do not call the callback and do not hit the rate limiter.
2. Hold that lock in `try` / `finally` and always `release()` it, including when the callback throws.
3. Inside the lock, if `RateLimiter::tooManyAttempts(RATE_KEY, requestsPerMinute())`, throw
   `PixelforgeBusy` with `max(1, RateLimiter::availableIn(RATE_KEY))`. Do not hit.
4. Otherwise `RateLimiter::hit(RATE_KEY, 60)` and then call the callback. A throw from the callback
   still consumes the permit. That matches the provider, which counts failed requests.

`PixelforgeBusy extends RuntimeException` and has `public readonly int $retryAfterSeconds`. It is not a
`PixelforgeException`.

`SubmitBackoff::seconds(?int $retryAfterSeconds, int $submitAttempts): int` throws
`InvalidArgumentException` when `$submitAttempts < 1`. Negative `Retry-After` is treated as 0. Null is
0. `$shift = min($submitAttempts - 1, 20)`. Return `$retryAfter + (2 ** $shift)`, all integers.

### Fake client hooks

On `FakePixelforgeClient`, add public properties the create-builder reads. Defaults keep today's
behaviour (queued, no usage, no error, rates copied from pricing):

- `GenerationStatus $createStatus = GenerationStatus::Queued`
- `?TokenUsage $createUsage = null`
- `?PixelforgeGenerationError $createError = null`
- `?GenerationRates $createRates = null` — when not null, use it instead of copying pricing rates

Do not change idempotency, replay, or the `$failure` one-shot.

### Job

`SubmitGeneration implements ShouldQueue`, using `Illuminate\Foundation\Queue\Queueable`. Constructor
takes `public string $generationId` only.

- `public int $tries = 100000`
- `public int $timeout = 60`
- `retryUntil(): DateTimeInterface` returns `now()->addHours(6)`
- No `$backoff`.
- `middleware()`: one `WithoutOverlapping` for `$this->generationId`, `expireAfter(60)`,
  `releaseAfter(5)`.

`failed(Throwable $exception): void` calls `FailGeneration` with code `submit_failed` and
`GenerationFailureMessage::forCode('submit_failed')`. It does not log the exception message.
`FailGeneration` no-ops when the row is already final, so this is safe after a local failure that
already saved.

## Behaviour in code

### Create (task T1)

`StoreGenerationRequest` authorizes `create` on `Generation::class`. Rules, after applying defaults in
`prepareForValidation`:

| Field | Rule |
| --- | --- |
| `prompt` | required string, min 1, max 4000 |
| `size` | required, in `ImageSize` values. Default `1024x1024` when missing |
| `quality` | required, in `ImageQuality` values. Default `medium` when missing |
| `reference_images` | presentable array, max 4. Missing becomes `[]` |
| `reference_images.*` | string, max 2048, `url:http,https` |

Ignore unknown input keys. The global trim middleware trims the prompt.

`GenerationController::store` is the only action. It maps validated input to enums and the URL list,
calls `CreateGeneration::handle(User $user, string $prompt, ImageSize $size, ImageQuality $quality, array $referenceImages): Generation`, and on `PricingUnavailable` returns
`back()->withErrors(['pricing' => $e->getMessage()])`. Success is
`redirect()->route('dashboard')->with('generation_id', $generation->id)`. The controller does not
touch the client, the cache, or the queue.

`CreateGeneration::handle` calls `GetCachedPricing::handle()` **before** opening a transaction. On
`PricingUnavailable`, nothing is inserted. Then:

```php
$estimate = CostEstimator::estimate(
    $pricing,
    $prompt,
    $size,
    $quality,
    count($referenceImages),
    (string) config('billing.markup_percent'),
);
```

Insert inside `DB::transaction`: status `queued`, the four estimate nanos, `submit_attempts` 0,
`reference_images` as `array_values` of the list, no `provider_id`. Task T1 stops here and returns the
model. It must not dispatch a job and must not call `PixelforgeClient::create`. Task T3 adds, inside
this same transaction:

```php
SubmitGeneration::dispatch($generation->id)->afterCommit();
```

### Snapshot and charge (task T2)

`FailGeneration::handle(string $generationId, string $code, string $message): void`

In a transaction, `lockForUpdate` the row. Missing row, or `status->isFinal()`, return. Otherwise set
`failed`, `error_code`, `error_message`. Do not write a ledger row. Do not change `provider_id`,
costs, usage, or rates. Empty `$code` throws `InvalidArgumentException`.

`ApplyGenerationSnapshot::handle(string $generationId, PixelforgeGeneration $snapshot): SnapshotOutcome`

In a transaction, `lockForUpdate`. Missing row returns `ignored`. If `provider_id` is already set and
differs from `$snapshot->id`, return `ignored` without writing.

If local status is final, return `ignored` without writing.

If `$snapshot->status` is `succeeded` and `$snapshot->usage` is null, return `incomplete` without
writing. Do not save `provider_id` in this branch. A saved id would make the job skip the retry.

If the snapshot does not advance the rank:

- Fill only nulls: `provider_id` (when it was null), `rates` (from `$snapshot->rates->toStoredArray()`),
  `provider_created_at`, `provider_started_at`, `provider_completed_at` (each only when the column is
  null and the snapshot value is not null).
- Do not change status, usage, costs, error columns, or image columns.
- Return `applied` when at least one null was filled, otherwise `ignored`.

If the snapshot advances to `processing`: set status, `provider_id`, rates from the snapshot
(overwrite), and any non-null provider timestamps. No ledger row. Return `applied`.

If it advances to `failed`: set status, `provider_id`, rates, non-null provider timestamps,
`error_code` from `$snapshot->error->code` or `generation_failed` when the error is null, and
`error_message` from `GenerationFailureMessage::forCode` of that code. Leave both cost columns null.
No ledger row. Return `applied`.

If it advances to `succeeded` (usage is present, otherwise the incomplete branch already returned):

```php
$cost = ActualCost::calculate(
    $snapshot->usage,
    $snapshot->rates,
    (string) config('billing.markup_percent'),
);
```

Create one `LedgerEntry` in this same transaction: generation id, the generation's `user_id` (may be
null), both nanos, and the markup string. Set the generation's `provider_cost_nanos`, `charge_nanos`,
`usage`, `rates` from the snapshot (these are the rates just billed, not the cache), `provider_id`,
image URL and expiry, non-null provider timestamps, and status `succeeded`. A zero cost still inserts
a ledger row of `0` / `0`. Do not catch a unique-constraint failure and continue; the lock makes the
second caller see a final row and return `ignored`. If the insert throws, the transaction rolls back
and the status is unchanged. Return `applied`.

Do not call `GetCachedPricing` or `CostEstimator` on this path.

### Submit job (task T3)

`handle` resolves the client, the limiter, `ApplyGenerationSnapshot` and `FailGeneration` from the
container (constructor injection or `app()`; pick one and type it).

1. Load the generation. If it is missing, final, or `provider_id` is not null, return without taking
   a slot and without calling the client.
2. Build `CreateGenerationRequest` from the stored prompt, size, quality and reference list (a null
   list is `[]`). `idempotencyKey` is the ULID. `metadata` is exactly `['generation_id' => $id]`.
3. Call `$limiter->run(...)`. Inside the callback, `increment('submit_attempts')`, `refresh()`, then
   `create()`. The increment happens only after a slot and a permit were acquired.
4. `PixelforgeBusy`: `release($busy->retryAfterSeconds)` and return. Do not increment (the throw
   happened before the callback, or the callback was not entered). Do not use `SubmitBackoff`.
5. `PixelforgeException`: `refresh()`. If `$e->retryable` and `submit_attempts` is still below
   `maxSubmitAttempts()`, log the warning and `release(SubmitBackoff::seconds($e->retryAfterSeconds, $generation->submit_attempts))`.
   Otherwise `FailGeneration` with `$e->errorCode` and `GenerationFailureMessage::forCode`. Return
   either way. Do not rethrow.
6. Any other exception propagates. The worker retries until `retryUntil` or `$tries`, then `failed()`
   marks `submit_failed` if the row is still not final.
7. On a `CreatedGeneration`, call `ApplyGenerationSnapshot` with `$created->generation`.
   `Incomplete`: `refresh()`, and if `submit_attempts >= maxSubmitAttempts()` then `FailGeneration`
   with `invalid_response`, else `release(SubmitBackoff::seconds(null, $generation->submit_attempts))`.
   `Applied` and `Ignored` end the job. Do not `release` after a saved provider id.

The first retryable failure therefore has `submit_attempts === 1` and a backoff of 1 second, so a
`Retry-After` of 7 waits 8 seconds. The fifth failure under the default max fails the row instead of
releasing. Tests set `max_submit_attempts` rather than looping five real calls.

## Tests

Pest. Bind `FakePixelforgeClient` with `$this->app->instance(...)`. `Http::preventStrayRequests()` on
every feature test in this spec. Never call `pixelforge.promptedit.dev`. Compare money by nanos or
`toDecimalString()`. Forget `GetCachedPricing::CACHE_KEY` in `beforeEach` of tests that create a
generation. Tests that run the limiter or the job also `RateLimiter::clear(PixelforgeRequestLimiter::RATE_KEY)`
and `Cache::lock(PixelforgeRequestLimiter::LOCK_PREFIX.$i, 60)->forceRelease()` for `$i` in 1..10.

| Test | Task | Proves |
| --- | --- | --- |
| `tests/Unit/Enums/GenerationStatusTest.php` | T2 | Ranks 0, 1, 2, 2. Existing final-status cases stay. |
| `tests/Feature/Generations/CreateGenerationTest.php` | T1 | Guest is redirected to `login` and the table is empty. Invalid prompt, size, quality, a fifth URL, and `ftp://` store nothing. The worked example stores nanos `330050000`, `373875000`, `412562500`, `467343750`. Omitted size and quality store `1024x1024` and `medium`. Two posts create two ULIDs. `pricing` error and an empty cache store nothing, and the fake's `created` list is empty. A successful post pushes `SubmitGeneration` for that id with `afterCommit`. `Gate` denies `view` to another user and allows it to the owner; `forUser` returns only the owner's rows. `$user->delete()` leaves the generation with `user_id` null. |
| `tests/Feature/Generations/ApplyGenerationSnapshotTest.php` | T2 | Queued snapshot on a queued row fills `provider_id` and rate nanos and writes no ledger row. A second queued snapshot changes nothing. `processing` moves queued forward and does not charge. `succeeded` with usage writes one ledger row and both cost columns; the worked half-up case is provider `2` nanos and charge `3` at markup `25`. Markup `0` leaves the charge equal to the provider cost. A text rate of `6.00` (`6000` nanos per token) times 2 tokens charges `15000` even when `GetCachedPricing::CACHE_KEY` holds a junk array. Calling the action again leaves one row and the same nanos. `succeeded` with null usage returns `incomplete` and does not set `provider_id` or a ledger row. A zero-usage success writes one `0`/`0` row. A failed snapshot writes no row, null costs, and the mapped message, not the provider's message text. `succeeded` then `failed`, and `failed` then `succeeded`, stay put and do not charge. `processing` then `queued` stays `processing`. A mismatched `provider_id` is `ignored`. `FailGeneration` on a queued row fails it with no ledger row; on an already `succeeded` row it changes nothing. Updating or deleting a `LedgerEntry` throws `LogicException`. A second `LedgerEntry::create` for the same generation throws a query exception. Deleting the user nulls `user_id` on the generation and on the ledger row, and both rows remain. |
| `tests/Unit/Services/Pixelforge/SubmitBackoffTest.php` | T3 | Null delay and attempt 1 is 1. A negative delay counts as 0. `Retry-After` 7 and attempt 1 is 8. Attempt 5 is `Retry-After + 16`. Attempt 0 throws. Attempt 30 uses exponent 20, not 30. |
| `tests/Feature/Services/Pixelforge/PixelforgeRequestLimiterTest.php` | T3 | A nested `run` with `concurrency_limit` 1 throws `PixelforgeBusy` and does not run the inner callback; after the outer call returns, a new `run` succeeds. With `requests_per_minute` 1 the second `run` throws and does not run the callback, and `retryAfterSeconds` is at least 1. |
| `tests/Feature/Jobs/SubmitGenerationTest.php` | T3 | `Http::preventStrayRequests()`. Happy path: one create, idempotency key and metadata equal the ULID, `provider_id` stored, status `queued`, `submit_attempts` 1, no ledger row. A second `handle()` does not append to `created`. `Queue` is not used to run it. While the only concurrency slot is held, the job releases for `concurrency_retry_seconds` and leaves `submit_attempts` at 0. Retryable `429` with `Retry-After: 7` on a job built with `withFakeQueueInteractions()` releases with delay 8, leaves status `queued`, `provider_id` null, no ledger row, `submit_attempts` 1. With `max_submit_attempts` 2, a second such failure marks `failed`, stores the mapped rate-limit message, does not release, and writes no ledger row. `daily_limit_exceeded` fails on the first call. `content_policy_violation` fails on the first call. A scripted `createStatus` of `succeeded`, usage of 2 text tokens, and `createRates` text `Money::perToken('6.00')` with the other rates `Money::zero()` writes one ledger row of provider `12000` and charge `15000` and does not call `GetCachedPricing`. A scripted `succeeded` with null usage releases (fake queue interactions) and leaves status `queued`. With `max_submit_attempts` 1 that body fails with `invalid_response`, leaves `provider_id` null, and writes no ledger row. A final generation and a generation that already has `provider_id` do not append to `created`. `middleware()` returns a `WithoutOverlapping` for that id, expiring after 60 seconds and releasing after 5. `failed()` stores `submit_failed` and the mapped message, and writes no ledger row. `retryUntil()` is between five and seven hours ahead. `tries` is 100000. |
| `tests/Unit/Services/Pixelforge/FakePixelforgeClientTest.php` | T3 | One case: `createStatus` succeeded plus `createUsage` is what `create()` returns. Existing replay and `409` cases still pass. |

The suite does not spawn two workers. The unique index and the second `handle()` of the snapshot are
the double-charge proof. Do not add a browser test.

## Risks

- Removing `afterCommit()` makes the job run inside the request on the sync queue and hides a
  production race where the worker cannot see the row. The `RefreshDatabase` note above is the trap.
- Holding the concurrency lock until the generation finishes would deadlock this spec, because nothing
  here moves a successful submit off `queued`. The lock covers the HTTP call only.
- `invalid_response` is not retried, even though the idempotency key would make a retry safe. A
  malformed `2xx` can leave a provider generation we then mark failed locally and never bill. That
  follows the client's `retryable` flag on purpose.
- A `succeeded` body without usage is retried until the cap and then failed locally, which can disagree
  with a provider that actually billed. The alternative, marking `failed` on the first body, cannot be
  repaired. The cap is the bound.
- `tries` of `100000` and `retryUntil()` of six hours are both set because Laravel versions disagree on
  which one wins. Either ceiling is enough for this app. `failed()` still records `submit_failed`.
- The per-minute check is not atomic. Two workers can both pass `tooManyAttempts` and exceed 60. The
  provider's `429` is the backstop.
