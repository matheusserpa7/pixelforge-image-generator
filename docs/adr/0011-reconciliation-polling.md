# 0011. Reconciliation by polling

Status: accepted (2026-11-16)

## Context

Webhook delivery is not guaranteed; after 6 failed attempts Pixelforge gives up.

## Decision

A scheduled job finds local generations that are not final and have not changed for longer than a
threshold, and polls `GET /v1/generations/{id}`. The result goes through the same transition and
billing code as webhooks.

## Consequences

- A lost webhook only delays the final state; it never loses or duplicates money.
- Polling shares the provider rate limiter with every other Pixelforge call.
- `pixelforge:reconcile` runs every minute and only dispatches jobs. It polls up to 20 non-final
  generations that have a provider id and have not changed for `PIXELFORGE_RECONCILE_AFTER_SECONDS`
  (default 120), and resubmits up to 20 still `queued` without a provider id after 10 minutes (a lost
  submit job; the idempotency key prevents a duplicate). It touches `updated_at` on what it dispatches,
  so `updated_at` means "last changed or last checked".
- A generation the provider answers `404` for is failed locally at no cost.
  Spec: [003-webhooks-and-reconciliation](../specs/003-webhooks-and-reconciliation/).
- The same run redispatches webhook events stored more than 5 minutes ago and never processed (up to
  a day old), and reports once at error level a generation with a provider id still in flight after
  `PIXELFORGE_STUCK_AFTER_SECONDS` (default 3600), setting `stuck_reported_at`. The admin report
  lists them under "Needs attention". They are never failed locally, since the provider may have
  billed them. Spec: [010-review-hardening](../specs/010-review-hardening/).
