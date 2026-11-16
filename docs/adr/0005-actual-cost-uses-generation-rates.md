# 0005. Actual cost uses the generation's own rates

Status: accepted (2026-11-16)

## Context

Pixelforge rates can change. Each generation object carries `rates` fixed when it was created, and
Pixelforge bills the generation at those rates.

## Decision

Actual provider cost = Σ (`usage[type]` × `rates[type]`) using the `rates` and `usage` of the
generation object returned by Pixelforge (webhook or polling), never the cached pricing table. The
rates and usage we billed with are stored on the local generation.

## Consequences

- Our provider cost matches `GET /v1/usage` even if prices change between estimate and completion.
- Estimate and actual cost may legitimately use different rates; the UI shows both.
