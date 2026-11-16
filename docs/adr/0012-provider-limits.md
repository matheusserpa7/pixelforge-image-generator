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
