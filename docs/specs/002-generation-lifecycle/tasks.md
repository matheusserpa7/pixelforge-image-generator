# 002 Generation lifecycle: tasks

Status: **implemented** · 2026-11-16 · Tech spec: [tech-spec.md](tech-spec.md)

- [x] **T1. Generation record and submit.** `generations` migration, `Generation` model, factory,
      `User::generations`, `scopeForUser`, `GenerationPolicy`, `StoreGenerationRequest`,
      `GenerationController`, `POST /generations`, and `CreateGeneration` through the insert. Store the
      estimate. Do not dispatch a job and do not call `PixelforgeClient::create`. Tech spec: Tables
      (`generations`), `GenerationPolicy`, Behaviour in code (Create, task T1). Reviewer: Opus.
- [x] **T2. Status and the ledger.** `GenerationStatus::rank`, `ledger_entries` migration, `LedgerEntry`
      (append-only), `Generation::ledgerEntry`, `toStoredArray` / `fromStoredArray` on `GenerationRates`
      and `TokenUsage`, `SnapshotOutcome`, `GenerationFailureMessage`, `ApplyGenerationSnapshot`,
      `FailGeneration`. Tech spec: Tables (`ledger_entries`), `GenerationStatus::rank`, Stored rates and
      usage, Ledger model, `SnapshotOutcome`, Failure messages, Behaviour in code (Snapshot and charge).
      Reviewer: Opus.
- [x] **T3. Rate-limited submit.** `PixelforgeRequestLimiter`, `PixelforgeBusy`, `SubmitBackoff`, the
      config keys and `.env.example` lines, the fake-client hooks, `SubmitGeneration`, and the
      `afterCommit` dispatch inside `CreateGeneration`'s transaction. Tech spec: Limiter and backoff,
      Fake client hooks, Job, Behaviour in code (Submit job, and the dispatch sentence in Create).
      Reviewer: Opus.
