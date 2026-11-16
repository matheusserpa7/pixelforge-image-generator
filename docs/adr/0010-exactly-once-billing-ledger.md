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
