# 0010. Exactly-once billing through an append-only ledger

Status: accepted (2026-11-16)

## Context

A success can be observed several times (duplicate webhooks, polling, idempotent replays). It must be
charged exactly once. Failures are free.

## Decision

Charges are rows in an append-only `ledger_entries` table with a **unique constraint on
`generation_id`**. The entry is written in the same transaction that moves the generation to
`succeeded`, with the generation row locked. Rows are never updated or deleted. A failed generation
writes no entry (cost zero).

## Consequences

- Even a race between two workers cannot double charge: the constraint rejects the second row.
- Reports sum the ledger; a correction, if ever needed, is a new entry, never an edit.
- MySQL triggers refuse `UPDATE` and `DELETE` on `ledger_entries` and `credit_purchases`; the
  Eloquent guards stay for a clearer error. Foreign key `SET NULL` still works because cascaded
  actions do not fire triggers. Creating them as the app user with binary logging on needs
  `--log-bin-trust-function-creators=1` on the server (compose files; `SET GLOBAL` in CI). MySQL
  8.4 marks that variable deprecated, so a future major version will need the triggers created by a
  privileged user. Spec: [010-review-hardening](../specs/010-review-hardening/).
