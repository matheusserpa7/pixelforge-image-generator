# Roadmap

Specs in delivery order. Each one gets `prd.md`, `tech-spec.md` and `tasks.md` before any code.

| #   | Spec                              | Goal                                                                                      | Status  |
| --- | --------------------------------- | ----------------------------------------------------------------------------------------- | ------- |
| 000 | `000-foundation`                  | Running, documented skeleton with money primitives, security, observability and CI.       | Done        |
| 001 | `001-pixelforge-client-and-pricing` | HTTP client for Pixelforge plus cached pricing, cost estimate and actual-cost calculation. | Done    |
| 002 | `002-generation-lifecycle`        | Create generations idempotently through a rate-limited job and charge successes exactly once. | Done    |
| 003 | `003-webhooks-and-reconciliation` | Verify, deduplicate and process webhooks in any order, with polling as a safety net.      | Done    |
| 008 | `008-safe-submit-failures`        | Never fail locally a generation the provider may have; bursts wait for a concurrency slot. | Done    |
| 004 | `004-generate-and-history-ui`     | Generate page with up-front estimate and history with live status, costs and images.     | Done    |
| 005 | `005-deployment`                  | Deploy the production image to a public VPS with HTTPS and its own Pixelforge key.         | Done    |
| 009 | `009-webhook-numeric-payload`     | Apply real `succeeded` webhooks (numeric token counts) instead of waiting for polling.    | Done    |
| 006 | `006-admin-report` (stretch)      | Daily and per-user charged, provider cost and profit, reconciled with `GET /v1/usage`.     | Done    |
| 007 | `007-credit-balances` (stretch)   | Credit balances, fake "Buy credits" and blocking generations the user cannot afford.      | Next    |
