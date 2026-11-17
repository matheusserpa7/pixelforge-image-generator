# Product overview

Source: the PromptEdit take-home brief (https://pixelforge.promptedit.dev/assessment), summarised
here so the repository does not depend on the network.

## The assignment

Build a Laravel + Vue + Inertia app where signed-in users generate images. The backend forwards
requests to Pixelforge, a simulated image-generation API that behaves like a real provider:

- Generations are **asynchronous**: submit, then learn the result from a **webhook** or by polling.
- It is **priced per token**. Some token counts are only known after the generation finishes. The API
  never returns a price; we compute it from token usage and the published rates.
- It **fails sometimes**: rate limits, concurrency limits, overloads, generations that fail after being
  accepted, and webhooks delivered more than once or out of order.

Credits are plain US dollars.

## Required features

1. **Generating.** A page to write a prompt and choose size, quality and optional reference image URLs.
   Show the cost **before** submitting.
2. **Pricing.** Users pay Pixelforge's cost **plus a 25% markup**. Part of the cost is only known after
   completion, so the up-front price is an estimate. When a generation finishes, record what it
   actually cost the user. **Failed generations cost nothing.** Pixelforge costs come in fractions of a
   cent; how to handle that is our call (see ADR 0003).
3. **History.** Users see their generations with live status (queued, processing, succeeded, failed),
   the estimated and actual cost, and the image. Images may stop loading after Pixelforge URLs expire.
4. **Webhooks.** Receive and verify signed webhooks. Reach the right state even when events arrive
   twice or out of order. An event delivered twice must never be recorded twice.
5. **Deploy** somewhere public.

## Stretch goals

1. **Admin report.** For operators: credit spend (charged to users), provider cost (charged by
   Pixelforge) and profit, per day and per user. Provider cost must match `GET /v1/usage`.
2. **Balances.** Per-user credit balance, a fake "Buy credits" button, and blocking generation when the
   user cannot afford it.

## What is evaluated

1. **Correct money**: every generation recorded at exactly the right cost, never twice, failures free.
2. **Robustness**: limits, overloads, failures, duplicate and out-of-order webhooks.
3. **Product sense and UX**: clear cost, status and errors.
4. **Code quality**: easy to read and change.

There will be a live walkthrough of the code and a live extension exercise.

## Deliverables to the reviewers

A public GitHub repository, the deployed URL (with a few generations left on it) and the email of the
Pixelforge account. Use one Pixelforge API key per environment so `GET /v1/usage` for the deployed
key matches the deployed app exactly.

## Glossary

| Term                | Meaning                                                                                   |
| ------------------- | ----------------------------------------------------------------------------------------- |
| Generation          | One image request. Local record (ULID) linked to a Pixelforge `gen_…` id.                 |
| Status              | `queued` → `processing` → `succeeded` or `failed`. The last two are final.                |
| Usage               | Token counts reported by Pixelforge once a generation succeeds.                           |
| Rates               | USD per 1M tokens for each token type, fixed on the generation when it is created.        |
| Provider cost       | What Pixelforge charges us: `Σ tokens × rate`, exact, in nano-USD.                        |
| Markup              | 25% added on top of provider cost (configurable; fixed on each generation when it is created). |
| User charge         | Provider cost × 1.25, rounded half-up to the nano. What the user pays.                    |
| Estimate            | Up-front price from pricing cached for `billing.pricing_cache_seconds` (default 300): expected value and maximum. |
| Nano-USD (nanos)    | Integer money unit, 1 USD = 10⁹ nanos. Matches the 9 decimals of `GET /v1/usage`.         |
| Ledger entry        | Append-only record of a charge; at most one per generation.                               |
| Credit purchase     | Append-only record of credit added to a user (fake "Buy credits" pack or opening balance). |
| Balance             | Credit purchases minus ledger charges. Derived, never stored.                             |
| Held credit         | Sum of the maximum charge of the user's queued and processing generations, recomputed at each generation's own rates once known. |
| Available credit    | Balance minus held credit. A generation is accepted only if its maximum fits in it.       |
| Webhook event       | A signed POST from Pixelforge (`evt_…`), stored once and processed in a job.              |
| Reconciliation      | Scheduled polling of generations stuck too long, a safety net for lost webhooks.          |
| Idempotency key     | Our generation ULID, sent on `POST /v1/generations` so retries never create duplicates.   |
