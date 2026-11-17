# 009 Webhook numeric payload: PRD

Status: **implemented** · 2026-11-17

## Problem

On the first production generation (2026-11-17, spec 005), Pixelforge delivered a signed
`generation.succeeded` webhook within 10 seconds, but the app discarded it as an invalid payload.
The generation only reached `succeeded`, was charged and got its image about two and a half minutes
later, when the reconciliation job polled Pixelforge.

The cause: Pixelforge sends token counts in `usage` as JSON numbers (`"output_tokens": 1100`). The
webhook path decodes the payload with plain JSON decoding, which turns them into PHP integers, while
the shared parser of a generation object only accepts integer strings. The polling path decodes the
same object with the decimal-safe decoder, which turns every number into a string, so it works. The
webhook tests built their payloads with token counts as strings, so they never saw a real payload.

Every successful generation is affected; failures and `processing` events are not (they carry no
`usage`). Money is not at risk: nothing is charged twice or wrongly. But users wait minutes instead of
seconds, and the main robustness path the brief evaluates (signed webhooks) silently does not apply
successes.

## Users

- Signed-in users, who see their image and charge seconds after Pixelforge finishes instead of
  minutes later.
- The operator, who must notice when a signed webhook cannot be applied.

## In scope

- A `generation.succeeded` webhook exactly as Pixelforge sends it (numbers as JSON numbers) is
  applied: status, charge exactly once, image copy, the same as the polling path does.
- Webhook payloads are decoded the same decimal-safe way as API responses, so no number ever becomes
  a float on the webhook path either.
- Tests use payloads shaped like the real ones, including the one captured in production.
- A signed generation event the app cannot parse is logged as an error (reported to Sentry when
  configured), not a warning: a signed payload we cannot read means our parser is wrong.

## Out of scope

- Re-processing events already stored as `invalid_payload` (production has one, for a generation
  reconciliation already completed and charged).
- Polling a generation right away when its event cannot be parsed; reconciliation keeps covering it.
- Any change to signature checks, deduplication, status rules or billing.

## Acceptance

1. A `generation.succeeded` event with integer token counts moves the generation to `succeeded`,
   creates exactly one ledger entry with the correct charge, and schedules the image copy.
2. The same event delivered twice is still recorded once and charged once.
3. Rates sent as decimal strings and token counts sent as numbers give the same charge as the
   polling path for the same generation.
4. In production, the next test generation reaches `succeeded` through its webhook (the event's
   outcome is `applied`, not `invalid_payload`) within seconds of Pixelforge finishing.
5. An unparseable signed generation event produces an error-level log line with the event id.

## Open questions

None.
