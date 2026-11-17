# 0021. Fail a generation locally only when the provider cannot have it

Status: accepted (2026-11-16)

## Context

`failed` is final (ADR 0009). Spec 002 failed a generation locally when its submit attempts ran out or
on any non-retryable error, including a dropped connection, an unreadable `2xx`, a `5xx` or a worker
that died mid-call. In those cases Pixelforge may have created the generation. If it then succeeded,
the provider billed us, the webhook was ignored because the row was already final, and the user was
never charged. The ledger stopped matching `GET /v1/usage`.

## Decision

- Each generation has a `submit_unconfirmed` flag: "a create request may have been accepted and we
  have not seen the answer". It is set right before each create call.
- After a provider error, the flag becomes `false` when the answer proves the idempotency key was
  never accepted (`429 concurrency_limit_exceeded`, `429 daily_limit_exceeded`, `422
  validation_failed`, `400 content_policy_violation`). It keeps its previous value when the answer
  only proves this attempt created nothing (other `4xx` except `409`, `503 overloaded`). Otherwise it
  stays `true`.
- A generation that is unconfirmed and has no provider id is never failed by the submit job. It stays
  `queued` until a webhook (matched by `metadata.generation_id`) or a resubmission with the same
  idempotency key resolves it. After `PIXELFORGE_SUBMIT_GIVE_UP_SECONDS` (default 86 400) since
  creation it is failed at no cost.
- A `succeeded` answer without usage keeps the provider id, so polling fetches the full result.

## Consequences

- A generation the provider has is charged once when it succeeds, so the ledger keeps matching the
  usage report.
- An unconfirmed generation can stay `queued` for up to the give-up window when the provider is
  unreachable for good.
- The proof codes rely on the documented replay behaviour (replays skip the concurrency and daily
  limits; validation and content policy are deterministic). If that changes, the result falls back to
  the previous behaviour, never worse.
  Spec: [008-safe-submit-failures](../specs/008-safe-submit-failures/).
