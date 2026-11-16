# 0003. Money as integer nano-USD

Status: accepted (2026-11-16)

## Context

Pixelforge prices are USD per 1M tokens (`"5.00"`, `"12.50"`), so a single token costs fractions of a
cent. `GET /v1/usage` reports `cost_usd` as an exact decimal with 9 places. Floats drift.

## Decision

Every amount is an integer number of **nano-USD** (1 USD = 1,000,000,000 nanos), stored as `BIGINT`.

- Per-token price in nanos = `rate per 1M tokens × 1,000`. Today's rates give 5,000 (text), 10,000
  (image), 40,000 (output) and 12,500 (reasoning) nanos per token, so provider cost is always an
  integer and matches the 9 decimals of `GET /v1/usage` exactly.
- Converting a rate that would not give a whole number of nanos per token (more than 3 decimals)
  throws instead of rounding. We would rather fail loudly than charge a wrong amount.
- `App\Support\Money\Money` wraps the integer and does all arithmetic with `brick/math`.
- Floats are forbidden wherever money is handled (an architecture test guards `App\Support\Money`).
- Amounts cross the HTTP boundary as decimal strings (`"0.046125000"`), never as JSON numbers.

## Consequences

- Exact equality with Pixelforge's records; sums never drift.
- `BIGINT` holds up to about 9.2 billion USD, far beyond our needs.
- Rounding for display is a presentation concern; stored values are never rounded.
