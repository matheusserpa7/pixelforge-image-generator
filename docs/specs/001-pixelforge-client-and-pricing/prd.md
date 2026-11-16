# 001 Pixelforge client and pricing: PRD

Status: **approved** · 2026-11-16

## Problem

The app can represent money exactly, and it has an empty Pixelforge client, but it still cannot talk to
Pixelforge or turn token counts into a price. Later work (creating a generation, showing a price before
submit, charging the real cost) needs three things it can trust: a client that speaks the API, a cached
copy of the public price table, and two pure calculations — the up-front estimate and the actual cost.

## Users

No screen in this spec. Signed-in users are affected only later, when the generate page shows the
estimate and the history shows the actual charge. The direct callers are the generation flow (spec 002)
and that page (spec 004).

## In scope

- Read the public price table, submit a generation, and fetch one generation by its Pixelforge id.
- Send the API key, and send an idempotency key on submit.
- Map provider errors, including which ones are safe to retry and any `Retry-After` delay. Do not retry
  inside this spec.
- Keep a cached copy of the price table. Serve it while it is fresh. If the provider is down and the
  cache is empty, fail with a clear error instead of inventing a price. If a table is already cached,
  keep serving it during an outage.
- Treat every number that feeds a price or a token count as an exact decimal. Never as a floating-point
  value.
- Estimate the user charge and the maximum possible user charge from the cached table, the prompt, the
  size, the quality and the reference images, markup included.
- Compute the actual provider cost and the user charge from the usage and the rates on that generation,
  markup included once on the total.

## Out of scope

- The local generation record, the queue, the provider rate limiter, retries, and the charge itself
  (spec 002). This spec only reports that an error is retryable.
- Webhooks, signature checks, and polling stuck generations (spec 003).
- The generate page and the history page (spec 004).
- The usage report and the paginated generation list (spec 006).
- Downloading the image (ADR 0013, when a generation succeeds).

## Acceptance

1. Tests can price, submit and fetch a generation without ever calling the real Pixelforge host. Submit
   sends an idempotency key. The same key with the same parameters returns the same generation. The same
   key with different parameters is an error and is not retryable.
2. Reading the price table twice within its lifetime calls Pixelforge once. When the provider is down
   and nothing is cached, the read fails and no price is invented. When a table is cached, a later
   outage still returns that table.
3. The published example (high quality, 1536×1024, a 400-character prompt, two reference images, the
   rates published on 2026-11-16) estimates a provider cost of `0.330050000` USD and a maximum provider
   cost of `0.373875000` USD. At a 25% markup the user charges are `0.412562500` and `0.467343750`.
4. Text tokens follow the table: characters per token, then the variance for the maximum. A character
   is a Unicode code point. Image tokens are the table's per-image count times the number of reference
   images. Output tokens come from the table. Reasoning uses the middle of the range for the estimate
   (half-up when the middle is not a whole token) and the top of the range for the maximum.
5. Markup is applied once, to the summed provider cost, half-up to the nano, at the percent the caller
   passes in. Applying it per token type is wrong when the two answers differ.
6. Actual provider cost is that generation's usage times that generation's rates. A different cached
   table does not change it. The user charge is that one total plus markup.
7. Rate limit, concurrency limit, overload, and a dropped connection are reported as retryable, with
   the provider's delay when it sent one. The daily limit is not retryable. Nothing in this spec retries.

## Open questions

None.
