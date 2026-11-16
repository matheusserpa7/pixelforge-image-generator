# Pixelforge API summary

Faithful summary of https://pixelforge.promptedit.dev/docs (read on 2026-11-16). When in doubt, the
live docs win; update this file if they change.

## Basics

- Base URL: `https://pixelforge.promptedit.dev/v1`. JSON in and out.
- Auth: `Authorization: Bearer <api key>`. Missing, malformed or revoked key → `401 invalid_api_key`.
- Timestamps: ISO 8601 UTC (`2026-11-04T14:03:11Z`). Ids: `gen_…` (generations), `evt_…` (events).
- Money is always an exact decimal string in USD, never a float (`"0.046125000"`).
- Each API key has its own webhook URL, signing secret (`whsec_…`) and usage report. Use one key per
  environment.

## Errors

Shape (always):

```json
{ "error": { "type": "invalid_request_error", "code": "validation_failed",
  "message": "The selected size is invalid.", "param": "size",
  "errors": { "size": ["The size must be one of: 1024x1024, 1536x1024, 1024x1536."] } } }
```

Branch on `code`; `type` groups codes.

| Code                         | Type                    | Status | Meaning                                                                                  |
| ---------------------------- | ----------------------- | ------ | ---------------------------------------------------------------------------------------- |
| `invalid_api_key`            | `authentication_error`  | 401    | Key missing, malformed or revoked.                                                       |
| `validation_failed`          | `invalid_request_error` | 422    | Missing or invalid parameter. **Unknown parameters are rejected.** See `param`, `errors`. |
| `content_policy_violation`   | `invalid_request_error` | 400    | Prompt rejected. Nothing created or billed.                                              |
| `not_found`                  | `invalid_request_error` | 404    | No such resource, or it belongs to another account.                                      |
| `idempotency_key_reused`     | `idempotency_error`     | 409    | Same `Idempotency-Key` with different parameters.                                        |
| `rate_limit_exceeded`        | `rate_limit_error`      | 429    | Too many requests this minute. Wait `Retry-After` seconds.                               |
| `concurrency_limit_exceeded` | `rate_limit_error`      | 429    | Too many queued/processing generations. `Retry-After: 5`.                                |
| `daily_limit_exceeded`       | `rate_limit_error`      | 429    | Daily quota (UTC) used up.                                                               |
| `overloaded`                 | `api_error`             | 503    | Temporarily overloaded. `Retry-After: 2`. Nothing created; retry with an Idempotency-Key. |
| `date_range_too_long`        | `invalid_request_error` | 422    | Usage date range too long.                                                               |

## Limits (per account, across all keys)

| Limit                  | Value                         | When exceeded                                  |
| ---------------------- | ----------------------------- | ---------------------------------------------- |
| Requests               | 60 per minute                 | `429 rate_limit_exceeded` + `Retry-After`      |
| Concurrent generations | 3 queued or processing        | `429 concurrency_limit_exceeded` + `Retry-After` |
| Generations per day    | 500 per UTC day               | `429 daily_limit_exceeded`                     |
| Prompt length          | 4,000 characters              | `422`                                          |
| Reference images       | 4                             | `422`                                          |
| Metadata               | 20 keys, values ≤ 500 chars   | `422`                                          |

Rejected requests create and cost nothing. Respect `Retry-After`, back off more each time, cap
retries. Every retry counts towards the per-minute limit. Authenticated responses include
`X-RateLimit-Limit` and `X-RateLimit-Remaining`.

## Idempotency

`POST /v1/generations` accepts `Idempotency-Key` (any unique string ≤ 255 chars).

- Same key + same parameters → the same generation **as it is now** (status may have moved on),
  `202` with `Idempotent-Replayed: true`. Nothing new is created or billed, and it does not count
  against concurrency or daily limits.
- Same key + different parameters → `409 idempotency_key_reused`.
- Keys are scoped to the account and never expire.

## Endpoints

### `POST /v1/generations` → `202` with the generation in `queued`

| Parameter          | Type           | Notes                                                                       |
| ------------------ | -------------- | --------------------------------------------------------------------------- |
| `prompt`           | string, req.   | ≤ 4,000 characters.                                                         |
| `size`             | string         | `1024x1024` (default), `1536x1024`, `1024x1536`.                            |
| `quality`          | string         | `low`, `medium` (default), `high`.                                          |
| `reference_images` | array of URLs  | Optional http(s) URLs, ≤ 4. Not downloaded; each billed as 765 image tokens. |
| `metadata`         | object         | String → string, ≤ 20 keys, values ≤ 500 chars. Echoed on the generation and every webhook. |

Any other parameter → `422 validation_failed`.

### `GET /v1/generations/{id}`

Returns the generation. Other accounts' generations → `404 not_found`.

### `GET /v1/generations`

Newest first, cursor pagination. Query: `limit` (1–100, default 20), `status`, `cursor`.
Response: `{ "object": "list", "data": [...], "has_more": true, "next_cursor": "…" }`.

### `GET /v1/pricing` (no auth)

```json
{ "object": "pricing", "currency": "usd", "unit": "per_1m_tokens",
  "rates": { "input_text_tokens": "5.00", "input_image_tokens": "10.00",
             "output_tokens": "40.00", "reasoning_tokens": "12.50" },
  "tokens": { "characters_per_text_token": 4, "text_token_variance": 0.15, "per_reference_image": 765,
    "output": { "low": { "1024x1024": 280, "1536x1024": 420, "1024x1536": 420 },
                "medium": { "1024x1024": 1100, "1536x1024": 1650, "1024x1536": 1650 },
                "high": { "1024x1024": 4300, "1536x1024": 6450, "1024x1536": 6450 } },
    "reasoning": { "low": { "min": 0, "max": 500 }, "medium": { "min": 200, "max": 2000 },
                   "high": { "min": 1000, "max": 8000 } } } }
```

Rates can change: read them from this endpoint, do not hardcode them.

### `GET /v1/usage`

What Pixelforge billed for generations created with **the calling key**, per UTC day. Source of truth
for provider cost.

- `start_date`, `end_date`: `YYYY-MM-DD` UTC, inclusive. Default last 30 days. Max 92 days
  (`422 date_range_too_long`).
- A generation is billed on the UTC day it **completed**, not the day it was created.
- `cost_usd`: exact decimal string with 9 decimals. Days without usage appear with zeros.

```json
{ "object": "usage", "start_date": "2026-11-01", "end_date": "2026-11-02",
  "data": [ { "date": "2026-11-01", "generations": 3, "input_text_tokens": 41, "input_image_tokens": 0,
              "output_tokens": 3300, "reasoning_tokens": 2950, "cost_usd": "0.169080000" }, … ],
  "total": { "generations": 3, …, "cost_usd": "0.169080000" } }
```

## The generation object

| Field                                                       | Notes                                                                         |
| ----------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `id`                                                        | `gen_…`                                                                       |
| `object`                                                    | Always `generation`.                                                          |
| `status`                                                    | `queued`, `processing`, `succeeded`, `failed`.                                |
| `prompt`, `size`, `quality`, `reference_images`, `metadata` | As requested, defaults filled in. `metadata` is always an object.             |
| `image_url`                                                 | Signed PNG URL once succeeded, else null.                                     |
| `image_url_expires_at`                                      | When `image_url` stops working (24 h after completion).                       |
| `usage`                                                     | Null until succeeded; then `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens`, `total_tokens`. |
| `rates`                                                     | USD per 1M tokens this generation is billed at, **fixed at creation**.        |
| `error`                                                     | Null unless failed; then `{ code, message }`. Codes: `generation_failed`, `internal_error` (retrying usually works). |
| `created_at`, `started_at`, `completed_at`                  | Accepted, started processing, finished.                                       |

Lifecycle: `queued → processing → succeeded | failed`. A few seconds queued, then 5–30 s processing.
`succeeded` and `failed` are final. **Failed generations are never billed.**

## Pricing and token counting

Only succeeded generations are billed, at the rates in force when they were created.

| Token type         | Price per 1M tokens (2026-11-16) |
| ------------------ | -------------------------------- |
| Input text         | $5.00                            |
| Input image        | $10.00                           |
| Output             | $40.00                           |
| Reasoning          | $12.50                           |

- **Input text:** about one token per 4 Unicode characters → estimate `ceil(chars / 4)`. Real count is
  within ±15% of that estimate, known only after completion.
- **Input image:** exactly 765 per reference image.
- **Output:** exact, by quality and size:

  | Quality | 1024x1024 | 1536x1024 | 1024x1536 |
  | ------- | --------- | --------- | --------- |
  | low     | 280       | 420       | 420       |
  | medium  | 1,100     | 1,650     | 1,650     |
  | high    | 4,300     | 6,450     | 6,450     |

- **Reasoning:** known only after completion, within a range by quality: low 0–500, medium 200–2,000,
  high 1,000–8,000.

Worked maximum (high, 1536x1024, 400-char prompt, 2 reference images): text 115 × $5 = 0.000575000,
image 1,530 × $10 = 0.015300000, output 6,450 × $40 = 0.258000000, reasoning 8,000 × $12.50 =
0.100000000 → **0.373875000**. Costs are exact and never rounded; use integers or decimals.

## Webhooks

Sent to the webhook URL of the key that created the generation, signed with that key's secret.

| Event                   | Sent when                                         |
| ----------------------- | ------------------------------------------------- |
| `generation.processing` | A generation leaves the queue.                    |
| `generation.succeeded`  | Finished, with `usage` and `image_url`.           |
| `generation.failed`     | Failed, with `error`.                             |
| `ping`                  | "Send test event" pressed in the dashboard.       |

Body: `{ "id": "evt_…", "object": "event", "type": "generation.succeeded", "created_at": "…",
"data": { "object": { …full generation object at that moment… } } }`.

Headers: `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt` (counts
retries and dashboard resends), `Pixelforge-Signature: t=<unix ts>,v1=<hex sig>`.

**Verification:** `v1` = hex HMAC-SHA256 of `<timestamp>.<raw request body>` keyed with the signing
secret. Compare in constant time. Reject old timestamps (docs example: more than 300 s). Each attempt is
signed with a fresh timestamp. Verify against the raw body before JSON decoding. The route must be
outside CSRF protection.

**Responding:** any 2xx within 10 s counts as delivered. Otherwise retried after 10 s, 30 s, 2 min,
10 min, 15 min (6 attempts total). Redirects are not followed. Respond fast, work in a queued job.

**Delivery is at least once, in any order:**

- The same event can arrive more than once → deduplicate by event id.
- Events can arrive out of order (a retried `processing` after `succeeded`) → go by status, never move
  back from `succeeded` or `failed`.
- A webhook can arrive **before** the `POST /v1/generations` response returns.
- Delivery is not guaranteed → poll `GET /v1/generations/{id}` as a fallback.

Webhook URLs must be public http(s); private and loopback addresses are rejected. Use a tunnel locally.
The dashboard lists every delivery attempt and can resend events.

## Images

`image_url` is a signed PNG URL that stops working 24 h after completion (`image_url_expires_at`).
Fetching the generation again gives a fresh URL with the same expiry. Download it to keep it longer.

## Test keywords (anywhere in the prompt, case-insensitive, still count as text tokens)

| Keyword        | Behaviour                                                                   |
| -------------- | --------------------------------------------------------------------------- |
| `[blocked]`    | `400 content_policy_violation` at submission. Nothing created or billed.    |
| `[overloaded]` | `503 overloaded` at submission. Nothing created or billed.                  |
| `[fail]`       | Accepted, then fails while processing (`generation.failed`). Not billed.    |
| `[succeed]`    | Accepted and never fails at random.                                         |
| `[slow]`       | Processes for 60–90 s instead of 5–30 s.                                    |
| `[duplicate]`  | Every webhook event for the generation is delivered twice.                  |

Without keywords Pixelforge still misbehaves sometimes: random failures, random `503 overloaded` on
create, and some webhooks delivered twice.
