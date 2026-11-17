# 0008. Webhook verification and deduplication

Status: accepted (2026-11-16)

## Context

Anyone can POST to the webhook URL. Pixelforge delivers at least once, in any order, and retries on a
non-2xx answer or after 10 seconds.

## Decision

1. Verify `Pixelforge-Signature` (`t=…,v1=…`): HMAC-SHA256 of `<t>.<raw body>` keyed with
   `PIXELFORGE_WEBHOOK_SECRET`, compared with `hash_equals`. Reject when the timestamp is more than
   `PIXELFORGE_WEBHOOK_TOLERANCE` (300) seconds away from now.
2. Insert the event into `webhook_events` with a unique key on the event id. A duplicate means
   "already received": answer 2xx and do nothing else.
3. Answer 2xx immediately and process the event in a queued job.
4. The route lives outside the `web` middleware group: no session, no CSRF, its own rate limiter.

## Consequences

- Duplicates are dropped by a database constraint, not by application logic alone.
- Processing failures do not trigger Pixelforge retries; our job retries and reconciliation cover them.
- The event id used for deduplication is the `id` of the signed body, not the unsigned
  `Pixelforge-Event-Id` header. A signed body without a readable `id` or `type` gets `400`; a bad or
  stale signature gets `401`. Each stored event records what processing did with it (`outcome`).
  Spec: [003-webhooks-and-reconciliation](../specs/003-webhooks-and-reconciliation/).
