# 0007. Idempotent creation with a local ULID

Status: accepted (2026-11-16)

## Context

`POST /v1/generations` can time out or fail with `503` after we sent it. A webhook can arrive before
the `POST` response. Retries must never create a second generation, and webhooks must match local
records.

## Decision

Each local generation is created first, with a ULID primary key. The ULID is sent as the
`Idempotency-Key` header and as `metadata.generation_id`. Retries reuse the same key. Webhooks are
matched by Pixelforge id when known, otherwise by `metadata.generation_id`.

## Consequences

- Retrying a create is always safe; Pixelforge replays the same generation.
- A webhook that arrives before the `POST` response still finds its local generation.
