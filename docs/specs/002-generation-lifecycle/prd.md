# 002 Generation lifecycle: PRD

Status: **implemented** · 2026-11-16

## Problem

The app can price a request, but it cannot yet create a generation, get that request to Pixelforge
without tripping the provider's limits, or charge a finished generation. A success has to be billed
exactly once. A failure has to cost nothing. Retrying a submit that timed out must not create a second
generation at the provider, and seeing the same success twice must not bill it twice.

## Users

A signed-in user submits a generation. They do not get the generate page or the history page in this
spec; those come later. Until then, the only proof that a submission was accepted is that it is stored
and queued. Operators are not a user of this spec.

## In scope

- A signed-in user submits a prompt, an optional size, an optional quality and up to four reference
  image URLs. Size defaults to square and quality defaults to medium.
- The up-front estimate and the maximum possible charge are stored on that generation when it is
  created. Both include markup. If the price table cannot be read and nothing is cached, the
  submission is refused with a clear message and nothing is stored.
- Each submission is a new generation with its own id. Two submissions are two generations. Retries of
  the same submission reuse that generation's id so the provider does not create a duplicate.
- A background job sends the generation to Pixelforge. It waits for a free concurrency slot and for
  room in the per-minute budget instead of calling immediately. Those limits are the account's (3
  concurrent calls, 60 requests per minute), shared by every user.
- If the provider asks us to retry (rate limit, concurrency limit, overload, or a dropped connection),
  the job tries again later, with a longer wait each time, up to a fixed number of attempts. After
  that the generation is failed locally and costs nothing.
- A daily limit, a rejected prompt, a validation error, a reused idempotency key with different
  parameters, and any other non-retryable error fail the generation immediately. They cost nothing.
- When a generation is observed as succeeded, with token usage, it is charged once from that
  generation's own usage and rates. Markup is applied once to the total. A later or duplicate success
  does not charge again.
- When a generation is observed as failed, it costs nothing. Status never moves backwards.
  `succeeded` and `failed` are final, including against each other.
- A user can only view their own generation. Another user's generation is refused.

## Out of scope

- The generate page, the history page, live status on screen, and downloading the image to our storage.
- Receiving, verifying, or polling webhooks. This spec is the code those will call; it does not listen
  for them.
- The per-user limit on how often someone may hit the submit route. That limiter already exists and is
  for the generate page.
- The usage report, credit balances, and blocking a user who cannot afford the estimate.
- Changing how money, markup, estimates, or the provider client classify errors.

## Acceptance

1. A guest who submits is sent to login. Nothing is stored. A signed-in user who omits the prompt, sends
   more than 4,000 characters, sends an unknown size or quality, sends more than four reference images,
   or sends a reference URL that is not http or https, is refused. Nothing is stored.
2. A valid submission stores one generation in `queued`, owned by that user, with the estimate and the
   maximum charge. The published example (high quality, 1536×1024, a 400-character prompt, two reference
   images, markup 25%) stores an estimated user charge of `0.412562500` USD and a maximum of
   `0.467343750` USD. Omitting size and quality stores square and medium. Two submissions create two
   generations. Another user cannot view the generation.
3. If the price table is down and the cache is empty, the submission is refused with a clear message.
   Nothing is stored and nothing is sent to the provider's create endpoint.
4. The job sends the generation's id as the idempotency key and as the only metadata field
   (`generation_id`). A second run of the same generation, after the provider already accepted it, does
   not create a second provider generation. The provider id and the rates from the response are stored.
   The status stays `queued` when the provider says `queued`. No charge is written.
5. While three calls are already in flight, or the per-minute budget is used up, the job waits and does
   not count that wait as a failed attempt. A retryable provider error waits for the provider's delay
   plus a backoff that doubles each attempt (1s, 2s, 4s, …), then tries again. The daily limit is not
   retried. After the last allowed attempt, or on any non-retryable error, the generation is `failed`,
   with a clear reason, and has no charge.
6. Observing `succeeded` with usage writes exactly one charge: the provider cost from those rates and
   that usage, then markup once on the total, half-up to the nano. The rates are the ones on that
   generation, not the cached price table. Observing the same success again does not add a charge and
   does not change the amount. A success with no usage is not charged and is not marked succeeded.
7. Observing `failed` writes no charge. A generation that is already `succeeded` or `failed` stays
   there, including when a later observation says the other final status or an earlier one. A `queued`
   observation does not move a generation that is already `processing`.
8. Deleting the user does not delete the generation or its charge. The charge row cannot be updated or
   deleted.

## Open questions

None.
