# 010 Review hardening: PRD

Status: **approved** · 2026-11-17

Fixes the findings of an adversarial review of the whole app (money, robustness, UX). Every finding was
read in the code and the money ones were reproduced in a throwaway test. Each one names a concrete
input that gives a wrong result today.

## Problem

1. **The quoted markup is not the charged markup.** The charge reads the markup from configuration
   when the generation completes, not when it was created. A deploy that changes the markup while a
   generation is in flight charges that generation at the new markup, above the maximum the user saw.
   Reproduced: created at 25%, markup changed to 40% → charged 0.502005000 instead of 0.448218750.
2. **Holds can be far below the real maximum.** A generation holds its maximum charge computed from
   pricing that may be an hour old. Pixelforge fixes the real rates when it accepts the generation. If
   rates went up, the hold stays at the old maximum while the generation runs, so the user can keep
   submitting against credit that is already spent, and the balance goes negative by more than the
   unavoidable overrun of that one generation. Reproduced: $0.40 bought, $0.39 held, rates doubled →
   charged $0.896, balance −$0.496.
3. **A token type we do not price is dropped silently.** Usage is read as four fixed token types. If
   Pixelforge reports another one, we charge only the four we know, the user is undercharged and the
   provider still bills us for the rest. Reproduced with an extra `audio_tokens` count.
4. **Generations can stay in flight forever with nobody told.** A snapshot we cannot read (a new
   status, a new size, a usage that does not add up) is dropped by the webhook path and fails every
   poll. The generation stays `queued` or `processing`, keeps its hold forever, and only warning logs
   show it.
5. **A webhook can be stored and never processed.** If the queue is unreachable right after the event
   is stored, the job is never dispatched, and every resend is answered as a duplicate.
6. **The provider's per-minute limit fails generations.** Its limits are per account, across keys and
   environments. Five `429 rate_limit_exceeded` answers in a row fail the user's generation, while the
   same situation for the concurrency limit simply waits.
7. **The local per-minute limiter is not atomic.** It checks and then counts in two steps, so parallel
   callers can go past the budget.
8. **Waiting submit jobs look lost.** A generation waiting for a concurrency slot for 10 minutes looks
   like a lost submit job to reconciliation, which dispatches a duplicate job every 10 minutes.
9. **The ledger and purchases are append-only only by convention.** Any raw query can update or delete
   them.
10. **Some states and errors leave the user guessing.** A generation whose submission is unconfirmed
    shows "Waiting for the image service" for up to a day without saying that nothing is charged yet.
    Transient failures do not say that trying again usually works. The public health endpoint tells
    anyone which internal service is down.

## Users

- Signed-in users who generate images and hold credit.
- Operators who read the admin report and the error tracker.

## In scope

- The markup is fixed on each generation when it is created. Its charge always uses that markup.
  Changing the configuration only affects new generations.
- As soon as Pixelforge's real rates for a generation are known, its hold rises to the maximum charge at
  those rates when that is higher than the quoted maximum. It never goes down. The "up to" amount shown
  in the history follows the hold.
- Cached pricing is kept for 5 minutes by default instead of an hour.
- A usage whose total does not equal the sum of the token types we price is refused, never charged in
  part.
- A generation in flight for longer than a threshold (1 hour by default) is reported once to operators
  at error level and listed in the admin report. It is not failed locally, because the provider may
  have it.
- Stored webhook events that were never processed are dispatched again by reconciliation.
- A provider `429 rate_limit_exceeded` waits like a concurrency wait: it never uses a submit attempt.
- The local per-minute budget is counted atomically.
- A submit job that is waiting keeps the generation marked as recently checked, so reconciliation does
  not dispatch duplicates.
- The database itself refuses updates and deletes of ledger entries and credit purchases.
- An unconfirmed submission says that it is still being confirmed and that nothing is charged unless it
  completes. Transient failure messages end with "Try again." The public health endpoint returns only
  its overall status, and the details go to the logs.

## Out of scope

- Blocking or capping a charge for work Pixelforge did. ADR 0024 stands: the balance can still go below
  zero by the overrun of the generation whose rates changed.
- Lowering the hold when rates go down.
- Pricing token types we do not know. A new token type stops charging until the code learns it.
- Failing stuck generations automatically, or any operator action on them beyond the report.
- A sliding-window limiter. The fixed window stays. With item 6, a provider `429` caused by a burst only
  waits.
- Rates with more than six decimals per million tokens. Today's rates have two.
- A cancel action, refunds, or new admin pages.

## Acceptance

- A generation created at 25% is charged at 25% even if the markup is 40% when it completes. Its ledger
  row records 25.
- A generation whose real rates are double the cached ones holds the maximum at the real rates from its
  first snapshot onwards. A second submit that only fitted the old hold is refused.
- A generation whose real rates are lower keeps its quoted hold.
- Pricing is cached for 300 seconds unless `BILLING_PRICING_CACHE_SECONDS` says otherwise.
- A `succeeded` snapshot whose `total_tokens` differs from the sum of the four priced types writes no
  ledger row and does not change the status. A `succeeded` snapshot without `total_tokens` still works.
- A generation with a provider id that is still in flight one hour after creation produces exactly one
  error log and appears in the admin report until it becomes final.
- A webhook event stored more than 5 minutes ago and never processed is processed by the next
  reconciliation run. Events older than a day are left alone.
- Ten `429 rate_limit_exceeded` answers in a row leave the generation `queued` with the same attempt
  count. It is only failed when the 6-hour job window ends and the provider cannot have it.
- Two parallel callers with one request left in the minute budget: exactly one reaches Pixelforge.
- A generation waiting for a concurrency slot for 15 minutes gets no extra submit job from
  reconciliation.
- `UPDATE` or `DELETE` on `ledger_entries` or `credit_purchases` fails at the database. Deleting a user
  still nulls the user id on both.
- An unconfirmed queued generation shows the confirming message. A failure with a transient code ends
  with "Try again." `GET /health` answers `{"status":"ok"}` or `{"status":"fail"}` with the same
  status codes as today.
- `make lint analyse test` is green.

## Open questions

None.
