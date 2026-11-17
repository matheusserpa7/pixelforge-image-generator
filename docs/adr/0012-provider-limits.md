# 0012. Respecting provider limits

Status: accepted (2026-11-16)

## Context

Pixelforge allows 3 concurrent generations and 60 requests per minute per account. It answers `429`
with `Retry-After`, and `503 overloaded` with `Retry-After: 2`.

## Decision

The job that creates a generation takes a Redis-backed limiter (3 concurrent, 60 per minute) before
calling Pixelforge. On `429` or `503` the job is released back to the queue after `Retry-After`
seconds plus exponential backoff, up to a maximum number of attempts. When attempts run out, the
generation is marked `failed` locally with a clear reason and costs nothing. `daily_limit_exceeded`
is not retried.

## Consequences

- Bursts of user requests wait in the queue instead of failing.
- Under load users see "queued" for longer; the UI explains why.
- The limiter is the cache rate limiter plus cache locks on the default cache store: Redis in
  production (`CACHE_STORE=redis`), the array store in tests, which do not open a Redis connection.
  A slot is held only for the HTTP call. Waiting for a slot does not count as a submit attempt.
- The submit job also counts our generations in flight at the provider (`queued` or `processing`
  with a provider id) against the concurrency limit and waits while it is full. A full count or a
  `429 concurrency_limit_exceeded` waits without using an attempt. Every `5xx` is retried. When
  attempts run out on a generation the provider may have, it stays queued (ADR 0021).
  Spec: [008-safe-submit-failures](../specs/008-safe-submit-failures/).
- `429 rate_limit_exceeded` waits without using an attempt, like concurrency: the provider's limits
  are per account, across environments. The per-minute counter is incremented before it is checked,
  so parallel callers cannot overshoot it. A waiting submit job touches `updated_at`, so
  reconciliation does not mistake it for a lost job. Spec: [010-review-hardening](../specs/010-review-hardening/).
