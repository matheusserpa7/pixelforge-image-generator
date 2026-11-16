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
