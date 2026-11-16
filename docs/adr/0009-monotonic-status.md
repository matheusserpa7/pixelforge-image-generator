# 0009. Monotonic generation status

Status: accepted (2026-11-16)

## Context

Events can arrive out of order: a retried `generation.processing` can arrive after
`generation.succeeded`.

## Decision

Status ranks `queued (0) < processing (1) < succeeded | failed (2)`. A transition is applied only when
it moves forward. `succeeded` and `failed` are final and never overwritten. Transitions run inside a
transaction with the generation row locked (`SELECT … FOR UPDATE`).

## Consequences

- The final state is the same regardless of delivery order.
- Late events are still stored in `webhook_events` but change nothing.
