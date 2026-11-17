# 009 Webhook numeric payload: tasks

Status: **implemented** · 2026-11-17 · Tech spec: [tech-spec.md](tech-spec.md)

- [x] **T1. Decode webhook payloads with `DecimalJson`.** The failing production-payload test first,
      then `ProcessWebhookEvent::parseSnapshot()` with `DecimalJson`, the error-level log, `usageObject()`
      and other webhook fixtures switched to numeric token counts, and the duplicate and invalid-payload
      tests. Tech spec: Model and contracts, Tests. Reviewer: Opus.
- [x] **T2. Deploy and verify live.** Main session: `make deploy DEPLOY_HOST=aiflow`, one test
      generation in production, and confirm its `generation.succeeded` event has outcome `applied`
      and the generation turned `succeeded` within seconds; record it here. Then close the docs (ADR
      0008 consequence line, roadmap, README). Reviewer: none.

T2 result (2026-11-17): deployed `e73f9f0`; a production generation created 13:00:36 UTC got
`generation.processing` (applied 13:00:40) and `generation.succeeded` (received 13:01:04, applied
13:01:05), one ledger entry of 58,993,750 nanos for a provider cost of 47,195,000.
