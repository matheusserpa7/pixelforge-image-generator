# 008 Safe submit failures: PRD

Status: **implemented** · 2026-11-16

Runs after spec 003 is `implemented`. It relies on webhook processing and on reconciliation (polling
and resubmission of lost submit jobs) from that spec.

## Problem

Spec 002 marks a generation `failed` locally, which is final, in cases where Pixelforge may have created
it anyway:

- the connection dropped or timed out after the request was sent, and every retry also failed;
- the provider answered `2xx` with a body we could not read;
- the provider answered `succeeded` without token usage, repeatedly;
- the provider answered a server error other than `503 overloaded` (for example `500` or `502`), which
  is failed on the first try even though a retry with the same idempotency key is safe;
- the worker died or timed out in the middle of the call.

If the provider did create it, the generation runs, the provider bills us, and the webhook that later
reports `succeeded` is ignored because the local status is already final. The user is never charged
and our ledger no longer matches `GET /v1/usage`. That breaks the first evaluation priority. Spec 003
recorded it as a known gap.

Separately, spec 002 limits concurrency only for the few hundred milliseconds of the HTTP call, while
the provider's limit is 3 generations **queued or processing**, which last 5 to 90 seconds. A burst of
more than three submissions gets `429 concurrency_limit_exceeded`, each one burns a submit attempt, and
after about half a minute the extra generations fail. ADR 0012 promises the opposite: bursts wait in the
queue instead of failing.

## Users

A signed-in user who submits generations, alone or in a burst. They still have no page to watch them
(spec 004). Operators, indirectly: the provider's usage report keeps matching our ledger.

## In scope

- A generation is failed locally only when the provider cannot have it: every attempt was answered
  with an error that means nothing was created, or the provider gave an answer that proves it never
  accepted this generation's idempotency key.
- When the provider may have it and we could not confirm, the generation stays `queued` instead of
  failing. A webhook, or the resubmission from spec 003 (which replays the same idempotency key), brings
  it to its real state and charges it once if it succeeded.
- A generation that stays unconfirmed for a long time (default 24 hours) is finally failed at no cost,
  so nothing waits forever.
- A `succeeded` answer without usage keeps the provider id, so polling from spec 003 fetches the full
  result instead of the submit job failing it.
- Provider server errors (`5xx`) are retried with backoff like an overload.
- Submission does not send a new generation while three of ours are already queued or processing at the
  provider. It waits instead, without using up attempts.
- A `429 concurrency_limit_exceeded` from the provider (for example, another environment on the same
  account) makes the job wait and try again, without using up attempts.

## Out of scope

- Repairing generations already failed locally before this spec ships.
- Changing how webhooks are verified, recorded or processed, or how reconciliation selects rows (spec
  003), beyond what an unconfirmed `queued` row naturally gets from it.
- The generate and history pages (spec 004), including how an unconfirmed generation is shown.
- Changing the money rules, the estimate, the ledger or the markup.

## Acceptance

1. A submission whose every attempt drops the connection stays `queued` with no provider id and no
   charge after attempts run out. A later `succeeded` webhook for it (found through the local id in
   metadata) moves it to `succeeded` and charges it once.
2. The same holds for a `2xx` with an unreadable body, a server error other than `503 overloaded`, and a
   worker that died during the call.
3. A submission whose every attempt is answered `429 rate_limit_exceeded` or `503 overloaded`, with no
   earlier uncertain attempt, is failed after the last attempt at no cost, as today.
4. A rejected prompt, a validation error, or a daily limit fails the generation at once, even after an
   earlier uncertain attempt, because those answers prove the provider never accepted it.
5. An uncertain generation older than the give-up window is failed at no cost the next time its submit
   job runs.
6. A `succeeded` answer without usage stores the provider id, leaves the status unchanged, writes no
   charge and ends the job without failing it.
7. A `500` or `502` on submit is retried with backoff.
8. With three of our generations queued or processing at the provider, a fourth submission waits, does
   not call the provider and does not use an attempt. Once one of the three finishes, it is sent.
9. A `429 concurrency_limit_exceeded` does not use an attempt and is retried after the provider's delay,
   until the job's overall time limit.

## Open questions

None.
