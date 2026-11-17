# 0023. Admin report attributes money to the provider completion day

Status: accepted (2026-11-17)

## Context

Operators need charged, provider cost and profit per day and per user, and the provider cost must
match `GET /v1/usage`. Pixelforge bills a generation on the UTC day it **completed**; our ledger row
is written when we apply the success, which can be later (webhook delay, reconciliation). Reading
usage also spends the shared 60 requests per minute that generations need.

## Decision

- Local figures come only from the ledger (ADR 0010), joined to the generation and bucketed by the
  UTC date of `generations.provider_completed_at`, on a half-open `[day 00:00, next day 00:00)` window.
- Profit is charged − provider cost, both from the ledger. Pixelforge's `cost_usd` is only compared,
  per day and in exact nanos; a day absent from its data counts as zero, and the signed difference is
  ours minus Pixelforge's.
- `GET /v1/usage` goes through `PixelforgeClient::usage()`, is cached 60 seconds per range, and
  spends the shared minute budget through `PixelforgeRequestLimiter::attempt()` (no concurrency slot).
  When it fails or the budget is spent, the report shows local figures and says the comparison is
  unavailable; failures are not cached.
- The report is a page behind the `admin` Gate (ADR 0014); ranges are UTC days, at most 92.

## Consequences

- A day matches to the nano once every success of that day is applied; a temporary mismatch means a
  generation has not caught up yet, and reconciliation (ADR 0011) closes it.
- Usage billed to the key outside this app shows as a mismatch, which is why each environment has its
  own key.
- Opening the report costs at most one provider request per range per minute.
  Spec: [006-admin-report](../specs/006-admin-report/).
