# 001 Pixelforge client and pricing: tech spec

Status: **implemented** · 2026-11-16 · PRD: [prd.md](prd.md)

Implements ADR 0003, ADR 0004, ADR 0005 and ADR 0006 on top of the empty client from spec 000. The
provider contract is [docs/product/pixelforge-api.md](../../product/pixelforge-api.md). Where this file
and the summary disagree, the summary wins; do not invent endpoints or fields.

## Technical context

`PixelforgeClient`, `HttpPixelforgeClient` and `FakePixelforgeClient` exist and have no methods.
`AppServiceProvider::$bindings` maps the interface to the HTTP class. `Money` already converts a rate
per 1M tokens (`Money::perToken`), multiplies by a token count (`times`), sums (`plus`) and applies
markup once (`withMarkup`). Use those. Do not add money operations.

Config already has `services.pixelforge.base_url`, `api_key`, `webhook_secret`, `webhook_tolerance`,
and `billing.markup_percent` as a string. Tests boot Laravel only under `tests/Feature` (MySQL
`testing`, cache `array`). `tests/Unit` is plain PHP: no `Http::fake()`, no `config()`, no container.

No migration. No route. No Vue file.

### Decisions this spec locks

No new ADR. At close, add one sentence to the consequences of ADR 0006: the table is cached for
`billing.pricing_cache_seconds` (default 3600). Mention that default in `docs/product/overview.md` where
the estimate is described. README status line moves off "foundation only". Roadmap: 001 `Done`, 002
`Next`. `CLAUDE.md` already states the estimate and actual-cost rules; leave it.

- Token-count inputs come from the pricing payload (`characters_per_text_token`, `text_token_variance`,
  `per_reference_image`, the output table, the reasoning range). Do not hardcode 4, 0.15 or 765. A
  missing or unusable field is an invalid response, not a silent fallback.
- JSON numbers are taken from the raw body as their exact spelling. Rates are already JSON strings.
  `text_token_variance` is a JSON number (`0.15`) and must not pass through a PHP float. `100 * 1.15`
  in floating point is not a safe way to obtain 115.
- The client does not retry and does not take the Redis limiter. Both belong to the job in spec 002
  (ADR 0012). The client only classifies the failure.
- Calculators do not read config. The caller passes the markup percent. Spec 002 and spec 004 will pass
  `(string) config('billing.markup_percent')`. Tests pass `'25'` or `'0'`.
- A failed generation costs nothing, but that decision is spec 002's. These calculators require usage.
  They do not treat "no usage" as zero.

## Model and contracts

All new classes are `final`, `declare(strict_types=1)`, and live in the folders below. DTOs are
`readonly`. Lists in JSON that are empty objects (`{}`) arrive as `[]`; treat an empty array as an
empty map. Reject a non-empty list where a map is required (`array_is_list`).

### `App\Services\Pixelforge\DecimalJson`

`decode(string $json): array<mixed>`. Rewrite the raw JSON so every bare number becomes a JSON string
of the same characters, then `json_decode` with `JSON_THROW_ON_ERROR`. The walk tracks string literals
and backslash escapes, so a number inside a string stays in the string. `"note": "wait: 5"` stays
`"wait: 5"`. `"text_token_variance": 0.15` becomes the string `"0.15"`. `"5.00"` was already a string
and stays `"5.00"`. Invalid JSON throws `InvalidArgumentException`.

This is the only JSON decoder the HTTP client uses.

### `App\Services\Pixelforge\PixelforgeException`

Extends `RuntimeException`. Private constructor. Fields:

| Field | Meaning |
| --- | --- |
| `status` | HTTP status, or `0` when there was no response. |
| `errorCode` | Provider `error.code`, or `connection_failed`, or `invalid_response`. |
| `param` | Provider `error.param`, else null. |
| `retryAfterSeconds` | `Retry-After` when it is a non-negative integer, else null. `0` is kept. |
| `retryable` | Whether spec 002 may release the job. |

Factories: `connection(Throwable $previous)`, `invalidResponse(string $reason, int $status = 0, ?Throwable $previous = null)`, `fromProvider(int $status, string $errorCode, string $message, ?string $param, ?int $retryAfterSeconds)`.

`retryable` is true only for:

- `connection_failed`
- HTTP 429 with `rate_limit_exceeded` or `concurrency_limit_exceeded`
- HTTP 503 with `overloaded`

Everything else is false, including `daily_limit_exceeded`, any other 429 code, 401, 400, 404, 409,
422, other 5xx, and `invalid_response`. The message names the status and the code. It does not include
the response body, the prompt, or the API key.

`Retry-After` counts only when the whole header matches `/^\d+$/`. Otherwise null. Do not parse HTTP
dates.

### DTOs (`App\Data`)

`GenerationRates`: four `Money` values, nanos per token: `inputText`, `inputImage`, `output`,
`reasoning`.

`TokenUsage`: four `int`s: `inputText`, `inputImage`, `output`, `reasoning`. Ignore `total_tokens` for
money. Negative counts are rejected by the calculator, not by this DTO.

`Pricing`:

- `GenerationRates $rates`
- `int $charactersPerTextToken` (≥ 1)
- `string $textTokenVariance` (decimal string, ≥ 0, parsed with `BigDecimal`, never a float)
- `int $tokensPerReferenceImage` (≥ 0)
- output tokens: `array<string, array<string, int>>` keyed by quality value then size value
- reasoning: `array<string, array{min: int, max: int}>` keyed by quality value, `min` ≤ `max`, both ≥ 0

`Pricing::fromDecoded(array $payload): self` checks `object === "pricing"`, `currency === "usd"`,
`unit === "per_1m_tokens"`, the four rate keys, and the token tables for every `ImageQuality` and
`ImageSize`. Rates go through `Money::perToken`. A rate that is not a whole number of nanos, or any
broken field, throws `InvalidArgumentException`. Integers arrive as decimal strings because of
`DecimalJson`; accept only an optional minus and digits (`/^-?\d+$/`), via `BigInteger`, and reject a
value that does not fit in a PHP `int`.

`toCacheArray(): array` and `fromCacheArray(array $cached): self` round-trip through ints and the
variance string only. `fromCacheArray` uses the same field checks. Do not cache `Pricing` or `Money`
objects.

`CreateGenerationRequest`: `prompt` (string), `size` (`ImageSize`), `quality` (`ImageQuality`),
`referenceImages` (`list<string>`), `metadata` (`array<string, string>`), `idempotencyKey` (string).

`PixelforgeGenerationError`: `code` and `message`, both strings.

`PixelforgeGeneration`: `id`, `status` (`GenerationStatus`), `prompt`, `size`, `quality`,
`referenceImages`, `metadata` (`array<string, string>`), `imageUrl` (`?string`),
`imageUrlExpiresAt` (`?CarbonImmutable`, UTC), `usage` (`?TokenUsage`), `rates` (`GenerationRates`),
`error` (`?PixelforgeGenerationError`), `createdAt`, `startedAt`, `completedAt`
(`?CarbonImmutable`; `createdAt` required). Unknown `status`, `size` or `quality` is an invalid
response. `usage` and `error` are null when the JSON nulls them.

`CreatedGeneration`: `PixelforgeGeneration $generation`, `bool $replayed`.

`CostEstimate`: `estimatedProviderCost`, `maximumProviderCost`, `estimatedCharge`, `maximumCharge`,
all `Money`.

`GenerationCost`: `providerCost`, `charge`, both `Money`.

### `PixelforgeClient`

```php
public function pricing(): Pricing;

public function create(CreateGenerationRequest $request): CreatedGeneration;

public function retrieve(string $id): PixelforgeGeneration;
```

`GET /v1/usage` and `GET /v1/generations` (the list) are not on this interface.

### Sample payload

`App\Services\Pixelforge\SamplePricing` holds the pricing JSON from the API summary (the block dated
2026-11-16) as a public string constant, unchanged. `pricing(): Pricing` decodes it with `DecimalJson`
and `Pricing::fromDecoded`. The fake's default and the HTTP tests both use this constant, so there is
one copy of the table.

## Behaviour in code

### HTTP client

Replace the `$bindings` entry with a singleton closure that constructs `HttpPixelforgeClient` from
`config('services.pixelforge.base_url')`, `api_key` (`?string`), and `timeout`. Add
`services.pixelforge.timeout` from `PIXELFORGE_TIMEOUT`, default `10`, and the matching commented line
in `.env.example`. A configured timeout below 1 is treated as 10. One timeout value covers the request
and the connection.

The client trims a trailing slash on the base URL and calls `pricing`, `generations`, and
`generations/{id}` under it. Encode the id with `rawurlencode`. Use `Illuminate\Support\Facades\Http`
(allowed here; the architecture test only bans it in controllers). Send `Accept: application/json`.
Send `Authorization: Bearer {key}` only when the key is a non-empty string. Pricing is public; an empty
key still calls it, with no Authorization header.

`create` rejects an empty idempotency key or one longer than 255 characters with
`InvalidArgumentException`, before any HTTP. The header is `Idempotency-Key`. The JSON body always
includes `prompt`, `size` and `quality` (enum values). Include `reference_images` only when the list is
non-empty, and `metadata` only when the map is non-empty. No other field.

Any failure to interpret a 2xx body — malformed JSON, wrong shape, unknown enum, a rate that is not an
exact nano count — becomes `PixelforgeException::invalidResponse`. The `Money` or decode exception is
the previous exception. A non-2xx response becomes `fromProvider`. A missing or non-JSON error body
still throws, with `errorCode` `unknown_error` and the HTTP status. `Illuminate\Http\Client\ConnectionException`
becomes `connection`. Do not follow a private retry loop.

`create` reads `Idempotent-Replayed`: the header value `true`, case-insensitive, means `replayed`.
Absent means false. Create and retrieve both parse a generation object (the body is the object, not a
list wrapper). Parse timestamps with `CarbonImmutable` in UTC. Null timestamps stay null.

### Fake client

In-memory, no network. Default `pricing()` returns `SamplePricing::pricing()`. A public `?Pricing
$pricing` override replaces it when not null.

`create` appends the request to a public `list<CreateGenerationRequest> $created`. It stores
generations in a public `array<string, PixelforgeGeneration> $generations` keyed by id, so a later test
can replace an entry. Ids are `gen_fake_1`, `gen_fake_2`, … Status `queued`, usage null, error null,
image URL null, `createdAt` now UTC, the other timestamps null, rates copied from the pricing in effect.
The response echoes prompt, size, quality, reference images and metadata.

Idempotency, matching the API: remember the key together with a canonical request (prompt, size value,
quality value, reference list in order, metadata with keys sorted). The same key and the same canonical
request return the stored generation and `replayed: true`, even if a test has since replaced that
entry. The same key and a different canonical request throw `PixelforgeException` 409
`idempotency_key_reused`, not retryable. Empty reference list and empty metadata compare equal whether
or not they would have been omitted on the wire.

`retrieve` of an unknown id throws 404 `not_found`, not retryable.

A public `?PixelforgeException $failure` is thrown by the next `pricing`, `create` or `retrieve` and
then cleared.

### Cached pricing

`App\Actions\GetCachedPricing::handle(): Pricing`. Cache key `pixelforge.pricing` (a public constant on
the action). TTL from new config `billing.pricing_cache_seconds`, env `BILLING_PRICING_CACHE_SECONDS`,
default `3600`. Add the commented line in `.env.example`.

Read the cache first. An array that `Pricing::fromCacheArray` accepts is returned and the client is
not called. Anything else stored under that key is forgotten and treated as a miss. On a miss, call
`PixelforgeClient::pricing()`, `Cache::put` the `toCacheArray()`, and return it. If that call throws
`PixelforgeException`, throw `App\Services\Billing\PricingUnavailable` (extends `RuntimeException`) with
the provider exception as `previous` and the message `Pricing is unavailable. Try again in a moment.`
Do not write the cache. One corrupt entry is refetched once; do not loop.

`PricingUnavailable` is not an HTTP renderer. Spec 004 will catch it. This action does not estimate.

### Estimate

`App\Services\Billing\CostEstimator::estimate(Pricing $pricing, string $prompt, ImageSize $size, ImageQuality $quality, int $referenceImageCount, string $markupPercent): CostEstimate`.

Character count is `mb_strlen($prompt, 'UTF-8')` (code points, not bytes, not grapheme clusters).

| Token | Estimate | Maximum |
| --- | --- | --- |
| Text | `ceil(chars / charactersPerTextToken)` via `intdiv($chars + $per - 1, $per)` | `ceil(estimate × (1 + variance))` via `BigDecimal`, scale 0, `RoundingMode::Ceiling`. An exact integer stays. |
| Image | `tokensPerReferenceImage × count` | the same count |
| Output | the table cell for this quality and size | the same cell |
| Reasoning | `(min + max) / 2`, scale 0, `RoundingMode::HalfUp` | `max` |

A negative reference count, or a quality/size missing from this table, throws `InvalidArgumentException`.
Image-token multiplication that does not fit in a PHP `int` throws `InvalidArgumentException` (same
attitude as `Money`). Do not call PHP `ceil`, and do not multiply with a float. The architecture test
that bans float tokens in `App\Support\Money` is extended to `App\Services\Billing` and
`App\Services\Pixelforge` (float casts, float literals, the `float` type, and `floatval`). `intdiv` and
`BigDecimal` stay allowed there. Do not ban `ceil` by name inside Billing if it is not called; banning
the float tokens is the guard that matters. Leave the existing Money bans as they are.

Provider cost is `rateOf(type)->times(tokens)` summed with `plus` across the four types. Call
`withMarkup($markupPercent)` on that sum once. Do it again, independently, for the maximum. That fills
the four `CostEstimate` fields.

Worked example, using `SamplePricing`, markup `25`, quality high, size landscape, prompt of 400 `a`s,
2 reference images. Assert these exact decimal strings:

| | Provider | User charge |
| --- | --- | --- |
| Estimate | `0.330050000` | `0.412562500` |
| Maximum | `0.373875000` | `0.467343750` |

The maximum provider cost is the figure in the API summary. Token counts behind it: text 100 / 115,
image 1530, output 6450, reasoning 4500 / 8000.

Also cover, with small hand-built `Pricing` objects where the fixture cannot: four thumbs (`👍`) count
as 4 code points so the text estimate is 1 token at 4 characters per token (16 bytes would be 4);
`charactersPerTextToken` of 10 turns 11 characters into 2 tokens; variance `0` makes the text maximum
equal the estimate; reasoning min 1 and max 2 estimates 2 tokens; markup `0` leaves the charge equal to
the provider cost; a missing size throws.

Markup-once test: three token types at 1 nano each (and the fourth at 0), markup `25`. The total is 3
nanos, and 3 × 1.25 rounds half-up to 4. Per-type markup would yield 1 + 1 + 1 = 3. Assert 4.

### Actual cost

`App\Services\Billing\ActualCost::calculate(TokenUsage $usage, GenerationRates $rates, string $markupPercent): GenerationCost`.

Sum `rates->{type}->times(usage->{type})` for the four types, then `withMarkup` once. A negative count
throws `InvalidArgumentException`. Build the rates with `Money::perToken` or `Money::ofNanos` in the
test. One test uses rates that are not the sample table (text `6.00` instead of `5.00`) and shows the
result follows the generation rates. One test repeats the 3-nano markup-once case. Zero tokens cost
`Money::zero()` before and after a `0` markup, and also after `25`.

## Tests

Pest. Never call `pixelforge.promptedit.dev`. No floats in assertions about money: compare
`toDecimalString()` or `nanos`.

| Test | Kind | Proves |
| --- | --- | --- |
| `tests/Unit/Services/Pixelforge/DecimalJsonTest.php` | unit | `0.15` stays the string `0.15`; a string `"wait: 5"` is untouched; `"5.00"` stays a string; invalid JSON throws. |
| `tests/Feature/Services/Pixelforge/HttpPixelforgeClientTest.php` | feature | `Http::fake()` + `Http::preventStrayRequests()`. Pricing GET, Bearer only when a key is set, sample rates 5000 / 10000 / 40000 / 12500 nanos and variance `"0.15"`. Create sends the idempotency header and omits empty `reference_images` and `metadata`. `Idempotent-Replayed: true` sets `replayed`. Retrieve maps usage, rates, and a null usage. 429 rate limit with `Retry-After: 7` is retryable with delay 7. 429 concurrency with `Retry-After: 5` is retryable. 429 `daily_limit_exceeded` is not. 503 `overloaded` with `Retry-After: 2` is retryable. 422 `validation_failed` keeps `param`. 409, 401 and 400 are not retryable. A connection exception is retryable with a null delay. A 200 body that is not JSON is `invalid_response` and not retryable. An empty idempotency key throws `InvalidArgumentException` and sends nothing. |
| `tests/Unit/Services/Pixelforge/FakePixelforgeClientTest.php` | unit | Default pricing matches `SamplePricing`. Create then retrieve. Replay. 409 on a changed prompt. 404. `$failure` throws once. |
| `tests/Feature/Services/PixelforgeClientBindingTest.php` | feature | Still resolves `HttpPixelforgeClient`. Update the construction if the singleton replaces `$bindings`; the assertion stays. |
| `tests/Feature/Actions/GetCachedPricingTest.php` | feature | Two `handle()` calls send one HTTP request. A 503 with an empty cache throws `PricingUnavailable` and leaves the key empty. A warm cache is returned when the next HTTP response is 503, and that second phase sends nothing. A non-array cache value is discarded and refetched. |
| `tests/Unit/Services/Billing/CostEstimatorTest.php` | unit | The worked example and the cases in Estimate. |
| `tests/Unit/Services/Billing/ActualCostTest.php` | unit | The cases in Actual cost. |
| `tests/Arch/ArchitectureTest.php` | arch | Extend the float-token scan from `app/Support/Money` to `app/Services/Billing` and `app/Services/Pixelforge`. |

The binding test and the new feature tests are the HTTP proof. Do not add a browser check: there is no
UI.

## Risks

- A regex that quotes numbers without tracking strings will corrupt `"wait: 5"`. The walk is the
  requirement; a test locks it.
- Caching a PHP object would unserialize `Money` without its constructor. The cache array is ints and
  one decimal string for that reason.
- Spec 002 will retry only what `retryable` says. Mis-classifying `daily_limit_exceeded` as retryable
  would hammer the provider until the cap. The feature test locks the four retryable cases and the
  daily limit.
