# 0024. Credit balances are derived, with holds at the maximum charge

Status: accepted (2026-11-17)

## Context

Users get a credit balance, a fake "Buy credits" button, and generation is blocked when they cannot
afford it. The real charge is only known when a generation succeeds; up front we only know the
maximum (ADR 0006). A user can have several generations queued or processing at once, and two
submits can arrive at the same moment.

## Decision

- No balance column. Purchases are rows in an append-only `credit_purchases` table (kinds `purchase`
  and `opening_balance`); charges stay in the ledger (ADR 0010) and nowhere else.
- `balance = Σ purchases − Σ ledger charges`. Every `queued` or `processing` generation **holds** its
  `maximum_charge_nanos`. `available = balance − held`.
- `CreateGeneration` locks the user row, reads `available` and accepts the generation only when its
  maximum charge is at most `available`; otherwise nothing is created and nothing is sent.
- Settlement does not change: a success writes its ledger row and the hold disappears with the status
  change; a failure just releases the hold. A charge for work Pixelforge did is never blocked, so the
  balance may go below zero when rates change between estimate and submission (ADR 0005).
- The generation stores its maximum token counts (`maximum_tokens`). When its own rates are first
  seen, the hold rises to the maximum charge at those rates if that is higher, and never goes down.
  Later submits then see the real hold; only the overrun of that one generation can still push the
  balance below zero.
- A purchase carries a ULID idempotency key, unique in the table, so a resent form adds a pack once.
- On release, each user with charges got one `opening_balance` row equal to them, so everyone starts
  at zero.

## Consequences

- The balance cannot drift from the ledger: there is no second counter to update on webhooks.
- Concurrent submits for one user are serialized; settling only ever raises `available`.
- Reading the balance costs three indexed `SUM` queries.
- An unconfirmed generation keeps its hold for up to the give-up window of ADR 0021.
- Purchases are fake and are not revenue; the admin report (ADR 0023) is unchanged.
  Spec: [007-credit-balances](../specs/007-credit-balances/). Holds at the generation's own rates:
  [010-review-hardening](../specs/010-review-hardening/).
