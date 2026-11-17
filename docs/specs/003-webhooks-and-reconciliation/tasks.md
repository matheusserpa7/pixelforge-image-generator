# 003 Webhooks and reconciliation: tasks

Status: **implemented** · 2026-11-16 · Tech spec: [tech-spec.md](tech-spec.md)

- [x] **T1. Receive and record webhooks.** `webhook_events` migration, `WebhookEvent` model,
      `WebhookEventOutcome`, `WebhookReceipt`, `WebhookSignature`, `ReceivePixelforgeWebhook`,
      `PixelforgeWebhookController`, the `POST /webhooks/pixelforge` route, the test helpers in
      `tests/Support/Webhooks.php`, and an empty `ProcessWebhookEvent` job (constructor, `tries`,
      `backoff`, `handle` that returns) so the dispatch compiles. Tech spec: Table `webhook_events`,
      `WebhookEvent` model, `WebhookEventOutcome`, `WebhookReceipt`, `WebhookSignature`, Behaviour in
      code (Receive). Reviewer: Opus.
- [x] **T2. Process webhook events.** `ProcessWebhookEvent::handle` and
      `WebhookEventOutcome::fromSnapshot`. Tech spec: Behaviour in code (Process). Reviewer: Opus.
- [x] **T3. Reconciliation.** `reconcile_after_seconds` config and `.env.example` line,
      `ReconcileGenerations`, `ReconcileGenerationsCommand`, the schedule entry, and `PollGeneration`.
      Tech spec: Config, Behaviour in code (Reconcile). Reviewer: Opus.
