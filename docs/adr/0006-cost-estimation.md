# 0006. Cost estimation

Status: accepted (2026-11-16)

## Context

Users must see the price before submitting, but text and reasoning tokens are only known after
completion.

## Decision

Estimates use `GET /v1/pricing`, cached (rates and token tables):

- Text tokens: `ceil(chars / 4)` (Unicode characters). Maximum: `ceil(estimate × 1.15)`.
- Image tokens: 765 × number of reference images (exact).
- Output tokens: exact, from the table by quality and size.
- Reasoning tokens: a range by quality. The estimate uses the midpoint, the maximum uses the top.

The UI shows the **estimated** user charge and the **maximum possible** user charge, both including
markup. The estimate is stored on the generation when it is created.

## Consequences

- Users never pay more than the maximum shown, unless Pixelforge changes rates between page load and
  submission (then the generation's own rates win, see ADR 0005).
- If the pricing endpoint is down and the cache is empty, the form cannot show a price, so
  submission is blocked with a clear message.
- The table is cached for `billing.pricing_cache_seconds` (default 3600).
