# 003 Webhooks and reconciliation: tech spec

Status: **implemented** · 2026-11-16 · PRD: [prd.md](prd.md)

Implements ADR 0008, ADR 0009 and ADR 0011 on top of spec 002. The provider contract is
[docs/product/pixelforge-api.md](../../product/pixelforge-api.md); where this file and that summary
disagree, the summary wins.

No new ADR. At close, the main session adds to ADR 0011 the consequences locked below (threshold
config, touch of `updated_at`, resubmission of lost submit jobs) and to ADR 0008 that the event id is
read from the signed body and that a signed but malformed body gets `400`. Also set roadmap 003 to
`Done` and 004 to `Next`, and update the README status line. Do not edit `docs/product/`, `docs/adr/`,
`docs/roadmap.md`, `README.md` or `CLAUDE.md` in the tasks below.

## Technical context

Spec 002 left `ApplyGenerationSnapshot` (the only writer of provider status, usage, rates, image URL and
the ledger, locked and idempotent), `FailGeneration` (the only writer of a local failure),
`SubmitGeneration`, `PixelforgeRequestLimiter`, and `PixelforgeClient::retrieve`. This spec **calls**
those; it does not change them, and it never writes generation status, costs or ledger rows itself.

Already in place: `config('services.pixelforge.webhook_secret')`, `webhook_tolerance` (300),
`routes/webhooks.php` loaded from `bootstrap/app.php` outside the `web` group with prefix `webhooks`,
name prefix `webhooks.` and `throttle:webhooks`. The `scheduler` service runs `schedule:work` in dev and
prod. `PixelforgeGeneration::fromDecoded(array)` parses a generation object and throws
`InvalidArgumentException` on anything malformed.

Feature tests use MySQL `testing` with `RefreshDatabase`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`.
Same traps as spec 002: assert dispatches with `Queue::fake()` and run jobs by calling `handle()` on a
fresh instance; a test that expects `release()` calls `withFakeQueueInteractions()` first.

There is no Vue page and no browser check.

### Decisions this spec locks

- The route is `POST /webhooks/pixelforge`, name `webhooks.pixelforge`, invokable
  `PixelforgeWebhookController`. Not authenticated; the signature is the authentication.
- The signature is checked against the **raw** body (`$request->getContent()`) before any JSON decoding.
  The event id used for deduplication is the `id` field of the signed body, never the
  `Pixelforge-Event-Id` header, which is not signed.
- Response codes: invalid or missing signature, stale timestamp, or no configured secret → `401`
  `{"error":"invalid_signature"}`. Signed but unreadable body → `400` `{"error":"malformed_payload"}`.
  New or duplicate event → `200` `{"received":true}`.
- A duplicate (unique key on `event_id`) is answered `200` and not dispatched again, as ADR 0008 says.
  If the dispatch of a new event is lost, reconciliation recovers the generation.
- Processing finds the local generation by `metadata.generation_id` first (it exists before the provider
  answers our POST) and by `provider_id` second. It ignores the event `type` and goes by the generation
  object's `status`, as the provider docs say.
- Each processed event stores an `outcome`. Processing never throws for bad provider data; it records
  `invalid_payload` and stops. Database errors propagate so the queue retries.
- Reconciliation is a scheduled command, every minute, that only **dispatches** jobs. A generation it
  dispatches for has its `updated_at` touched, so the next sweep skips it until the threshold passes
  again. `updated_at` therefore means "last changed or last checked".
- Polling goes through `PixelforgeRequestLimiter`. Busy or a retryable provider error just ends the poll
  job; the next sweep is the retry. A poll never increments `submit_attempts`.
- Resubmission dispatches the existing `SubmitGeneration` again. Its idempotency key, its
  `WithoutOverlapping` middleware and its early return on a known `provider_id` make that safe.

## Model and contracts

Every new class is `final` with `declare(strict_types=1)`. Enums are backed. No floats anywhere, and no
float literals in `app/Services/Pixelforge`.

### Table `webhook_events` (task T1)

| Column | Type |
| --- | --- |
| `id` | big increments |
| `event_id` | string 255, **unique** |
| `type` | string 64 |
| `payload` | `mediumText`, the raw body exactly as received |
| `generation_id` | nullable foreign ULID to `generations`, `nullOnDelete` |
| `outcome` | string 32, nullable |
| `processed_at` | timestamp, nullable |
| `created_at`, `updated_at` | timestamps (`created_at` is the reception time) |

Index on `generation_id` (the foreign key gives it).

### `WebhookEvent` model

Fillable: `event_id`, `type`, `payload`. Casts: `outcome` → `WebhookEventOutcome`, `processed_at` →
`datetime`. `generation(): BelongsTo`. No `$hidden` change; the model is never serialized to a page.

### `WebhookEventOutcome`

Backed string enum: `applied`, `ignored`, `incomplete`, `unknown_generation`, `invalid_payload`,
`not_a_generation_event`. Static `fromSnapshot(SnapshotOutcome $outcome): self` maps the first three
one to one.

### `WebhookReceipt`

Backed string enum returned by the receiving action: `accepted`, `duplicate`, `invalid_signature`,
`malformed`.

### `WebhookSignature`

`App\Services\Pixelforge\WebhookSignature::isValid(string $header, string $payload, string $secret, int $toleranceSeconds, int $now): bool`

1. Empty `$secret` → `false`.
2. Split the header on `,`; each part is `key=value` (trim spaces). Take the single `t` value; it must be
   all digits. Collect every `v1` value. No `t` or no `v1` → `false`.
3. `abs($now - (int) $t) > $toleranceSeconds` → `false`.
4. `$expected = hash_hmac('sha256', $t.'.'.$payload, $secret)`. Return `true` if `hash_equals($expected, $v1)`
   for any `v1`. Lower-case hex only; do not lower-case the received value.

No exception paths; any malformed header is `false`. Never log the header, the secret or the body.

### Config

Under `services.pixelforge`, read through a public static on `ReconcileGenerations`
(`reconcileAfterSeconds()`) with the same numeric / below-1 fallback as `PixelforgeRequestLimiter`:

| Key | Env | Default |
| --- | --- | --- |
| `reconcile_after_seconds` | `PIXELFORGE_RECONCILE_AFTER_SECONDS` | 120 |

Add the commented line to `.env.example`. Constants on `ReconcileGenerations`:
`RESUBMIT_AFTER_SECONDS = 600`, `BATCH_SIZE = 20`.

`webhook_tolerance` already exists; read it as an int with fallback 300 when not numeric or below 1.

## Behaviour in code

### Receive (task T1)

`ReceivePixelforgeWebhook::handle(string $signatureHeader, string $rawBody): WebhookReceipt`

1. `WebhookSignature::isValid($signatureHeader, $rawBody, (string) config('services.pixelforge.webhook_secret'), $tolerance, now()->getTimestamp())`.
   When the secret is empty, log `error` "Pixelforge webhook secret is not configured." (no context)
   before returning. Invalid → `InvalidSignature`.
2. `json_decode($rawBody, true)`. Not an array, `id` not a non-empty string of at most 255 characters,
   or `type` not a non-empty string of at most 64 characters → `Malformed`. Do not require the `evt_`
   prefix.
3. `WebhookEvent::query()->create([...])` with `event_id`, `type`, `payload` = `$rawBody`. Catch
   `Illuminate\Database\UniqueConstraintViolationException` only → `Duplicate`. Anything else propagates
   (the provider will retry on our `500`).
4. `ProcessWebhookEvent::dispatch($event->id)` and return `Accepted`. There is no transaction around
   the insert, so no `afterCommit()`.

Log `info` "Pixelforge webhook received." with `event_id`, `type` and `receipt` for `Accepted` and
`Duplicate`; `warning` with only `receipt` for the refusals. Never the body.

`PixelforgeWebhookController::__invoke(Request $request, ReceivePixelforgeWebhook $receive): JsonResponse`
passes `$request->header('Pixelforge-Signature', '')` (as a string) and `$request->getContent()`, then
maps the receipt to the codes in "Decisions". Nothing else. Register it in `routes/webhooks.php` as
`Route::post('pixelforge', PixelforgeWebhookController::class)->name('pixelforge')` and replace the
"arrives with spec 003" line of the comment.

### Process (task T2)

`ProcessWebhookEvent implements ShouldQueue` (`Queueable`), constructor `public int $webhookEventId`,
`public int $tries = 5`, `backoff(): array` returns `[5, 30, 120, 300]`. No middleware.

`handle(ApplyGenerationSnapshot $apply): void`

1. Load the event. Missing, or `processed_at` not null → return.
2. If `type` does not start with `generation.` → finish with `NotAGenerationEvent`.
3. Decode `payload`; take `data.object`. If it is not an array, or `PixelforgeGeneration::fromDecoded`
   throws `InvalidArgumentException` → finish with `InvalidPayload` and log `warning` with `event_id`
   only.
4. Find the generation: if `$snapshot->metadata['generation_id']` is a string and a row with that key
   exists, use it; else `Generation::query()->where('provider_id', $snapshot->id)->first()`. None →
   finish with `UnknownGeneration`, log `info` with `event_id` and the provider id.
5. `$outcome = $apply->handle($generation->id, $snapshot)`. Finish with
   `WebhookEventOutcome::fromSnapshot($outcome)` and `generation_id`. On `Incomplete` also log `warning`
   with `event_id` and generation id; reconciliation will fetch the full object.

"Finish" means set `outcome`, `generation_id` when known, `processed_at = now()`, and `save()`. The save
happens after `ApplyGenerationSnapshot` committed; if the job dies between the two, the retry applies the
snapshot again, which is a no-op by design.

### Reconcile (task T3)

`ReconcileGenerations::handle(): array` (action) returns `['polled' => int, 'resubmitted' => int]`,
called by the command `pixelforge:reconcile` (`App\Console\Commands\ReconcileGenerationsCommand`,
prints nothing or one line with the two counts).
Schedule it in `routes/console.php`:
`Schedule::command('pixelforge:reconcile')->everyMinute()->withoutOverlapping()`.

1. Poll set: `status` in `queued`, `processing`; `provider_id` not null;
   `updated_at <= now() - reconcileAfterSeconds()`; order by `updated_at`, then `id`; limit `BATCH_SIZE`.
   Touch them in one query (`Generation::query()->whereKey($ids)->update(['updated_at' => now()])`),
   then `PollGeneration::dispatch($id)` for each.
2. Resubmit set: `status` `queued`; `provider_id` null; `updated_at <= now() - RESUBMIT_AFTER_SECONDS`;
   same order and limit. Touch them the same way, then `SubmitGeneration::dispatch($id)` for each.
3. Log `info` "Pixelforge reconciliation dispatched." with both counts, only when at least one is
   non-zero.

The `(status, updated_at)` index from spec 002 serves both queries.

`PollGeneration implements ShouldQueue, ShouldBeUnique` (`Queueable`), constructor
`public string $generationId`, `uniqueId()` returns the generation id, `public int $uniqueFor = 300`,
`public int $tries = 1`, `public int $timeout = 60`.

`handle(PixelforgeClient $client, PixelforgeRequestLimiter $limiter, ApplyGenerationSnapshot $apply, FailGeneration $fail): void`

1. Load the generation. Missing, final, or `provider_id` null → return without taking a slot.
2. `$snapshot = $limiter->run(fn () => $client->retrieve($generation->provider_id))`.
3. `PixelforgeBusy` → return (no release; the next sweep retries).
4. `PixelforgeException` with `errorCode === 'not_found'` → `$fail->handle($id, 'not_found', GenerationFailureMessage::forCode('not_found'))`.
   Any other `PixelforgeException` → log `warning` with generation id and error code, return.
5. `$apply->handle($generation->id, $snapshot)`. On `Incomplete`, log `warning` with the generation id.
   Return in every case.

Other exceptions propagate (one try; the next sweep is the retry). Logs never include the prompt, URLs,
the API key or exception messages.

## Tests

Pest. `Http::preventStrayRequests()` in every feature test of this spec; never call
`pixelforge.promptedit.dev`. Bind `FakePixelforgeClient` with `$this->app->instance(...)`. Compare money
by nanos. Tests that run `PollGeneration` clear the limiter keys like spec 002's job tests.

Add Pest helpers in a new `tests/Support/Webhooks.php`, loaded from `tests/Pest.php`:

- `pixelforgeGenerationObject(array $overrides = []): array` — a valid generation object
  (`gen_test_1`, `queued`, rates `5.00` / `10.00` / `40.00` / `12.50`, `metadata` empty, no usage),
  merged with `$overrides`.
- `pixelforgeEvent(string $type, array $object, string $eventId = 'evt_test_1'): string` — the JSON body.
- `signPixelforge(string $body, ?int $timestamp = null, string $secret = 'whsec_test'): string` — the
  header value. Tests set `services.pixelforge.webhook_secret` to `whsec_test`.

| Test | Task | Proves |
| --- | --- | --- |
| `tests/Unit/Services/Pixelforge/WebhookSignatureTest.php` | T1 | Valid header passes. Wrong secret, flipped hex digit, body changed by one byte, missing `t`, missing `v1`, non-digit `t`, empty header and empty secret fail. Timestamp 301 s in the past and 301 s in the future fail; 300 s passes. A header with two `v1` values passes when either matches. Spaces after commas are accepted. |
| `tests/Feature/Webhooks/ReceivePixelforgeWebhookTest.php` | T1 | With `Queue::fake()`: a signed event returns `200` `{"received":true}`, stores one row with the raw body, and pushes `ProcessWebhookEvent` for that row. The same body re-signed with a new timestamp returns `200`, keeps one row and pushes nothing new. Missing header, bad signature, stale timestamp and an empty configured secret return `401` and store nothing. Signed `not json`, signed `{}`, an `id` of 256 characters and a `type` of 65 characters return `400` and store nothing. The route works without a session or CSRF token (plain `postJson`/`call` with the raw body, no `withoutMiddleware`). `ping` is accepted and stored. |
| `tests/Feature/Webhooks/ProcessWebhookEventTest.php` | T2 | Built on a generation created by the factory as `queued` with no `provider_id`. `processing` with `metadata.generation_id` set moves it to `processing`, sets `provider_id`, outcome `applied`, `processed_at` set. Then `succeeded` with usage writes one ledger row whose nanos equal `ActualCost` of that usage and those rates at markup 25, outcome `applied`. A second event id carrying the same `succeeded` object gives outcome `ignored` and still one ledger row. `processing` after `succeeded` is `ignored` and the status stays. `failed` gives `failed`, the mapped message, no ledger row; a later `succeeded` is `ignored` and charges nothing. An object without `metadata.generation_id` is found by `provider_id`. An unknown generation gives `unknown_generation` and no `generation_id`. A `ping` gives `not_a_generation_event`. A `data.object` missing `rates` gives `invalid_payload`. `succeeded` with null usage gives `incomplete`. Running `handle()` twice on the same event row does not apply it twice (second run returns at `processed_at`). |
| `tests/Feature/Reconciliation/ReconcileGenerationsTest.php` | T3 | With `Queue::fake()` and `travelTo`: a `processing` row with `provider_id` older than 120 s pushes `PollGeneration` and gets a fresh `updated_at`; a second sweep right after pushes nothing. Rows younger than the threshold, final rows and `queued` rows without `provider_id` younger than 600 s push nothing. A `queued` row without `provider_id` older than 600 s pushes `SubmitGeneration`. Twenty-five stale rows push twenty. `reconcile_after_seconds` set to `abc` falls back to 120. The schedule contains `pixelforge:reconcile` every minute (assert through `Schedule::events()`). |
| `tests/Feature/Jobs/PollGenerationTest.php` | T3 | The fake's `generations[...]` holds a `succeeded` snapshot with usage: the job charges once and a second `handle()` adds nothing. A `failed` snapshot fails the row with no charge. A `not_found` from the fake fails the row with `not_found` and no charge. A retryable `429` (fake `failure`) leaves the row untouched and does not throw. With the only concurrency slot held, it returns without calling the fake. A final row or a row without `provider_id` does not call the fake. `submit_attempts` never changes. `uniqueId()` is the generation id. |

## Risks

- A webhook delivered with a stale timestamp because our queue or clock is slow is refused; Pixelforge
  re-signs each attempt, so a retry passes. Clock skew beyond 300 s refuses everything; the reconciler
  still brings generations to their final state.
- A lost dispatch of `ProcessWebhookEvent` (queue down right after the insert) is not redelivered,
  because duplicates are not re-dispatched. Reconciliation is the recovery, within about two minutes.
- Touching `updated_at` on reconciliation hides when a generation last really changed. Provider
  timestamps keep the real timeline.
- Resubmitting can run beside a live `SubmitGeneration` that waited more than ten minutes for a limiter
  slot. Both share the idempotency key and `WithoutOverlapping`; the second returns once `provider_id`
  is set. The extra attempt counts toward `submit_attempts`.
- A local failure from spec 002 (attempts exhausted) whose provider generation later succeeds stays
  `failed` and is not charged, while the provider bills us. Recorded in the PRD as out of scope.
- Polling 20 generations per minute uses up to a third of the 60 requests per minute. Under heavy load
  submissions wait longer; the limiter keeps the total under the provider's limit.
