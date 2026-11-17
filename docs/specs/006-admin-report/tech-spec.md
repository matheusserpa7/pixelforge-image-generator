# 006 Admin report: tech spec

Status: **approved** · 2026-11-17 · PRD: [prd.md](prd.md)

## Technical context

- Charges live in `ledger_entries` (one row per succeeded generation, ADR 0010) with
  `provider_cost_nanos`, `charge_nanos`, `user_id` (nullable, `nullOnDelete`) and `generation_id`.
  The ledger's `created_at` is when *we* applied the success, which can lag (webhook delay,
  reconciliation), so it is **not** the day Pixelforge bills.
- `generations.provider_completed_at` is Pixelforge's `completed_at` and is set on every succeeded
  generation. Pixelforge bills a generation on the UTC day it completed
  (`docs/product/pixelforge-api.md`, `GET /v1/usage`). The report buckets by that column.
- Admin access: `Gate::define('admin', ...)` in `AppServiceProvider` (ADR 0014).
- Provider calls go through `PixelforgeClient` (`pricing`, `create`, `retrieve`); controllers never
  touch HTTP (architecture test). Money is `App\Support\Money\Money`; JSON with decimals is decoded
  with `DecimalJson` so numbers never pass through floats.
- The app timezone is UTC (check `config/app.php`; if not, all date math here uses `CarbonImmutable`
  in `UTC` explicitly).

## Model and contracts

No migration. Proposed ADR **0023 — Admin report attributes money to the provider completion day**
(written in the closing task): days are UTC dates of `generations.provider_completed_at`, local figures
come only from the ledger, provider cost is compared to `GET /v1/usage` exactly in nanos, and the usage
response is cached for 60 seconds per range.

### Client

Add to `PixelforgeClient`:

```php
public function usage(CarbonImmutable $startDate, CarbonImmutable $endDate): ProviderUsage;
```

- `HttpPixelforgeClient`: `GET {base}/usage?start_date=Y-m-d&end_date=Y-m-d` with the same
  `request()` / `withConnectionHandling()` as `retrieve`; non-2xx → `providerException()`; body
  decoded with `DecimalJson`; malformed → `PixelforgeException::invalidResponse`.
- `App\Data\ProviderUsage` (readonly): `CarbonImmutable $startDate`, `CarbonImmutable $endDate`,
  `array<string, Money> $costByDate` (key `Y-m-d`, every `data[].date`), `Money $total` (from
  `total.cost_usd`). Built by `ProviderUsage::fromDecoded(array)`; `cost_usd` via
  `Money::fromDecimal`. Token counts are not needed and not kept.
- `FakePixelforgeClient`: `usage()` returns a configurable `ProviderUsage` (setter like its existing
  ones) or throws a configured `PixelforgeException`; it records the calls so tests can count them.

### Report

`App\Actions\BuildAdminReport::handle(CarbonImmutable $start, CarbonImmutable $end): AdminReport`
(dates are UTC days, inclusive).

Local figures, both from one base query: `ledger_entries` joined to `generations` on
`generation_id`, filtered `generations.provider_completed_at >= start 00:00:00 UTC` and
`< (end + 1 day) 00:00:00 UTC` (half-open, so 23:59:59.x and midnight fall on the right day).

- Per day: `GROUP BY DATE(generations.provider_completed_at)`, selecting `COUNT(*)`,
  `SUM(ledger_entries.charge_nanos)`, `SUM(ledger_entries.provider_cost_nanos)`.
- Per user: `LEFT JOIN users`, `GROUP BY ledger_entries.user_id`, same aggregates plus `users.name`,
  `users.email`; `ORDER BY SUM(charge_nanos) DESC, user_id`. `user_id` null → one "Deleted user" row.
- MySQL returns `SUM` of `BIGINT` as a decimal string: convert with
  `BigInteger::of((string) $value)->toInt()` (null → 0), then `Money::ofNanos`. Never `(float)`.

Provider figures: `Cache::remember("admin-report-usage:{start}:{end}", 60, fn () => client->usage(...))`
wrapped in a `try/catch (PixelforgeException)` → `null` (log at `warning` with the error code; do not
cache failures). The request is spent through the shared minute budget: before calling, if
`RateLimiter::tooManyAttempts(PixelforgeRequestLimiter::RATE_KEY, requestsPerMinute())` treat as
unavailable; otherwise `RateLimiter::hit(RATE_KEY, 60)`. No concurrency lock (it is not a generation).
Put that in a small public method on `PixelforgeRequestLimiter`, `attempt(callable): mixed` that
throws `PixelforgeBusy` when the minute budget is spent, and catch `PixelforgeBusy` as unavailable too.

`App\Data\AdminReport` (readonly):

- `start`, `end`, `providerUsageAvailable: bool`.
- `days`: list of `AdminReportDay { date (Y-m-d), generations int, charged Money, providerCost Money,
  profit Money, pixelforgeCost ?Money, difference ?Money (providerCost − pixelforgeCost),
  matches ?bool }`, one per day from start to end ascending, zeros filled. `pixelforgeCost` null only
  when usage is unavailable; a day missing from Pixelforge's `data` counts as zero.
- `users`: list of `AdminReportUser { userId ?int, name string, email ?string, generations,
  charged, providerCost, profit }`.
- `totals`: generations, charged, providerCost, profit, `pixelforgeCost ?Money`, `matches ?bool`
  (true when every day matches).

`Money` gets `minus(self $other): self` (exact, overflow throws, unit-tested). Profit is
`charged − providerCost`, both from the ledger.

### HTTP

- `GET /admin/report` → `AdminReportController@show`, route name `admin.report`, middleware
  `auth` and `can:admin`.
- `AdminReportRequest` (FormRequest): optional `start` and `end`, `date_format:Y-m-d`; `end` defaults
  to today UTC, `start` to `end − 29 days`; `start <= end`; span ≤ 92 days inclusive; `end` not after
  today UTC. Validation errors redirect back to the default range with errors (Inertia standard).
- Controller: validate → action → `Inertia::render('admin/Report', [...])`. Each money value is sent
  as `{ nanos: int, usd: string (toDecimalString), display: string (format(6)) }`; reuse an existing
  money serialisation in `app/Http/Resources` if one exists, else add `MoneyResource`-like static
  helper there. The frontend never does money arithmetic.
- `HandleInertiaRequests::share` adds `auth.isAdmin` (bool, `Gate::allows('admin')`, false for guests).

### UI (`resources/js/pages/admin/Report.vue`)

- Sidebar (`AppSidebar.vue`, and `AppHeader.vue` if it lists nav items) shows "Report" only when
  `auth.isAdmin`. Wayfinder route import like the generations link.
- Top: range form (two date inputs, Apply) and the reconciliation banner: green "Matches Pixelforge
  for every day", amber "N days differ from Pixelforge" with the explanation from the PRD, or neutral
  "Pixelforge usage unavailable right now; showing our own figures".
- Totals as stat tiles: Generations, Charged, Provider cost, Profit, Pixelforge cost.
- Per-day table, newest first: Date, Generations, Charged, Provider cost, Profit, Pixelforge cost,
  Difference (blank when matching, highlighted when not). Amounts show `display`; `title` shows
  `$usd`.
- Per-user table: User (name, email below; "Deleted user"), Generations, Charged, Provider cost,
  Profit. Empty state: "No charges in this range."

## Behaviour in code

1. Admin opens `/admin/report` → defaults to last 30 UTC days.
2. Action runs the two aggregate queries and (cached, budgeted) `GET /v1/usage` for the same range.
3. Per day, compare nanos exactly; difference is ours minus Pixelforge's.
4. Failures of the usage call never fail the page.

## Tests

- Unit: `Money::minus` (normal, negative result, overflow); `ProviderUsage::fromDecoded` (9-decimal
  strings, numeric token fields ignored, malformed `cost_usd` throws).
- Feature (`Http::fake`): `HttpPixelforgeClient::usage` sends the query string and bearer token,
  decodes, maps 422 `date_range_too_long` and 5xx to `PixelforgeException`.
- Feature, action, with factories and the fake client:
  - sums only ledger rows whose generation completed in range; failed and non-final generations add
    nothing; boundary 23:59:59 vs 00:00:00 next day;
  - a ledger row written days after completion counts on the completion day;
  - zero-filled days; deleted user grouped as "Deleted user";
  - matching day, mismatching day with signed difference, day absent from Pixelforge data = zero;
  - usage exception and spent minute budget → `providerUsageAvailable false`, local figures intact;
  - two builds within 60 s call `usage()` once.
- Feature, HTTP: guest → login; non-admin → 403; admin → 200 Inertia `admin/Report` with props;
  invalid ranges (> 92 days, start after end, future end, bad format) → validation errors;
  `auth.isAdmin` shared true/false.

## Risks

- **Bucketing by the wrong timestamp** would make days never match: covered by the late-ledger and
  boundary tests.
- **SUM returned as string / float coercion**: covered by converting through `BigInteger`, tested with
  values above 2^31.
- **Spending the provider's minute budget** needed by generations: cache + shared limiter key.
- **Pixelforge usage includes generations not in our DB** (same key used before deploy, manual calls):
  shows as a mismatch, which is the honest answer; one key per environment avoids it.
- Performance: the date range is small and both queries use the generations primary key join; add no
  index unless a test or `EXPLAIN` shows a need (would need a migration and a spec update).
