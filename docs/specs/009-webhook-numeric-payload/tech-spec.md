# 009 Webhook numeric payload: tech spec

Status: **implemented** · 2026-11-17 · PRD: [prd.md](prd.md)

Fixes the webhook processing path so it decodes payloads exactly like the HTTP client does. Follows
ADR 0003 (no floats), ADR 0008 (webhooks), ADR 0009 (monotonic status) and ADR 0010 (charge once);
none of them changes. No new ADR. At close: add one consequence line to ADR 0008 ("Stored payloads
are decoded with `DecimalJson`, like API responses; spec 009"), roadmap row 009 `Done`, README
status line if it mentions webhooks. Do not edit `docs/adr/`, `docs/product/`, `docs/roadmap.md`,
`README.md` or `CLAUDE.md` in the task.

## Technical context

- `App\Services\Pixelforge\DecimalJson::decode(string): array` rewrites every bare JSON number into
  a string before `json_decode`, so nothing becomes a float; it throws `InvalidArgumentException` on
  invalid JSON or a non-object root. `HttpPixelforgeClient` uses it for every response.
- `App\Data\PixelforgeGeneration::fromDecoded(array)` parses a generation object. Token counts go
  through `requireNonNegativeIntString`, which accepts only strings matching `^-?\d+$`; rates go
  through `requireRateString` (non-empty string). It throws `InvalidArgumentException` on anything
  else. It is shared by the polling path and the webhook path and stays as it is: its contract is
  "input from `DecimalJson`".
- `App\Jobs\ProcessWebhookEvent::parseSnapshot()` does `json_decode($event->payload, true)` and
  passes `data.object` to `fromDecoded`. With a real payload, `usage.*` are PHP ints and parsing
  fails; the job logs a warning "Pixelforge webhook payload is invalid." and finishes the event with
  `WebhookEventOutcome::InvalidPayload`.
- `App\Actions\ReceivePixelforgeWebhook` also uses plain `json_decode`, but only reads `id` and
  `type` (strings) before storing the raw body. It is correct and does not change.
- Tests: `tests/Support/Webhooks.php` builds generation objects (`pixelforgeGenerationObject`) and
  event bodies (`pixelforgeEvent`, via `json_encode`); `tests/Feature/Webhooks/ProcessWebhookEventTest.php`
  uses `usageObject()` with token counts as strings (`'1000'`), which is why the suite passed.
- Real payload captured in production (event `evt_01M7MRN5ZWE111HMGRXFNBW6HY`), usage part:
  `"usage":{"input_text_tokens":17,"input_image_tokens":0,"output_tokens":1100,"reasoning_tokens":1819,"total_tokens":2936}`,
  rates `"5.00"`, `"10.00"`, `"40.00"`, `"12.50"`. Provider cost 66,822,500 nanos, charge 83,528,125
  nanos (verified against the ledger entry reconciliation created).

## Model and contracts

No migration, no new class, no config.

### `ProcessWebhookEvent::parseSnapshot()`

```php
try {
    $decoded = DecimalJson::decode($event->payload);
} catch (InvalidArgumentException) {
    return null;
}

$object = is_array($decoded['data'] ?? null) ? ($decoded['data']['object'] ?? null) : null;
// rest unchanged: not an array → null; fromDecoded in try/catch → null
```

### Invalid payload log level

In `handle()`, the `$snapshot === null` branch logs with `Log::error('Pixelforge webhook payload is
invalid.', ['event_id' => ..., 'type' => $event->type])` instead of `warning`. Outcome stays
`InvalidPayload` and the event is still finished (retrying would fail the same way; reconciliation
covers the generation).

## Behaviour in code

- A real `generation.succeeded` event is applied through the existing `ApplyGenerationSnapshot`
  path: status `succeeded`, one ledger entry, image download dispatched after commit.
- Numbers anywhere in the payload are strings after decoding; `metadata` and other string fields are
  unchanged because `DecimalJson` only touches bare numbers outside strings.

## Tests

| Test | Where |
| ---- | ----- |
| Feature: storing the production payload above (as a raw JSON string with numbers, `metadata.generation_id` pointing at a factory generation, `provider_id` matching) and processing it gives outcome `applied`, status `succeeded`, exactly one `LedgerEntry` with `provider_cost_nanos` 66822500 and `charge_nanos` 83528125 (markup 25), and `DownloadGenerationImage` pushed. | `ProcessWebhookEventTest` |
| Feature: processing a second event with the same payload but another event id leaves one ledger entry. | same |
| Feature: an event whose `data.object` cannot be parsed logs at error level (`Log::spy()` or `Log::shouldReceive`) and finishes with `invalid_payload`. | same |
| Change `usageObject()` to return integers (`100`, `765`, `1000`, `200`) and fix its PHPDoc, so every existing webhook test now sends numbers as Pixelforge does. Keep `pixelforgeEvent` encoding with `json_encode` (ints stay JSON numbers). Existing tests must stay green. | `ProcessWebhookEventTest` |
| Any other test that feeds a webhook payload with string token counts into `ProcessWebhookEvent` switches to numbers (search `tests/` for `ProcessWebhookEvent` and `usage`). Polling and client tests that go through `DecimalJson` stay as they are. | `tests/` |

The new production-payload test must fail on the current code (verify by running it before the
fix).

## Risks

- **Changing the decoder changes what reaches `fromDecoded` for every field.** Mitigated: it is the
  same decoder the polling path has used since spec 001, on the same object shape; `fromDecoded`
  already expects its output.
- **Large numbers.** `DecimalJson` keeps them as strings; `requireNonNegativeIntString` already
  rejects values beyond `PHP_INT_MAX`.
- **Events already marked `invalid_payload`** are not re-processed. Only one exists in production and
  its generation is final and charged; nothing to do.
