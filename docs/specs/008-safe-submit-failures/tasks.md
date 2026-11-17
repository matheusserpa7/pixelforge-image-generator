# 008 Safe submit failures: tasks

Status: **approved** · 2026-11-16 · Tech spec: [tech-spec.md](tech-spec.md)

Precondition: spec 003 is `implemented`.

- [x] **T1. Error classification and incomplete snapshots.** `PixelforgeException`: every `5xx`
      retryable, `provesKeyUnknown()`, `createdNothing()`. `ApplyGenerationSnapshot`: the incomplete
      branch fills nulls and still returns `incomplete`. Their tests, including the replaced
      incomplete case. Tech spec: Model and contracts (`PixelforgeException`, `ApplyGenerationSnapshot`),
      Tests (T1 rows). Reviewer: Opus.
- [x] **T2. Unconfirmed submissions and the concurrency gate.** Migration (`submit_unconfirmed`, wider
      `submit_attempts`), model cast, `submit_give_up_seconds` config and `.env.example` line,
      `SubmitGeneration` (give-up, in-flight gate, unconfirmed flag, concurrency `429` without an
      attempt, incomplete ends the job, `failed()`), and the job and webhook tests. Tech spec:
      Migration, Config, Behaviour in code (Submit job), Tests (T2 rows). Reviewer: Opus.
