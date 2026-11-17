# 009 Webhook numeric payload: tasks

Status: **approved** · 2026-11-17 · Tech spec: [tech-spec.md](tech-spec.md)

- [x] **T1. Decode webhook payloads with `DecimalJson`.** The failing production-payload test first,
      then `ProcessWebhookEvent::parseSnapshot()` with `DecimalJson`, the error-level log, `usageObject()`
      and other webhook fixtures switched to numeric token counts, and the duplicate and invalid-payload
      tests. Tech spec: Model and contracts, Tests. Reviewer: Opus.
- [ ] **T2. Deploy and verify live.** Main session: `make deploy DEPLOY_HOST=aiflow`, one test
      generation in production, and confirm its `generation.succeeded` event has outcome `applied`
      and the generation turned `succeeded` within seconds; record it here. Then close the docs (ADR
      0008 consequence line, roadmap, README). Reviewer: none.
