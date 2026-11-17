# 010 Review hardening: tasks

Status: **approved** · 2026-11-17 · Tech spec: [tech-spec.md](tech-spec.md)

Precondition: specs 000 to 009 are `implemented`. The tasks do not edit `docs/`, `README.md` or
`CLAUDE.md`. The main session closes the documents after T5 ("Documents at close").

- [x] **T1. Frozen markup and honest holds.** Migration A with its backfill, model fillable, casts and
      properties, factory defaults, `CostEstimate::maximumTokens`, `CreateGeneration` storing the markup
      and maximum tokens, `ApplyGenerationSnapshot` charging at the generation's markup and `raiseHold`
      on the first rates, pricing cache default 300 (config and `.env.example`), and the T1 tests plus
      the existing markup tests moved to the generation's markup. Tech spec: Model and contracts
      (Migration A, `Generation` model, `CostEstimate`, Config), Behaviour in code (T1), Tests (T1).
      Reviewer: Opus.
- [x] **T2. Refuse unpriced usage, report stuck generations, sweep webhooks.** The `total_tokens`
      check in `parseUsage`, Migration B, the `stuck_after_seconds` config and `.env.example` line,
      the two new reconciliation steps, the `AdminReport` stuck list with its DTO, controller prop and
      "Needs attention" section, and the T2 tests. Tech spec: Model and contracts (Migration B, Config,
      `AdminReport`), Behaviour in code (T2), Tests (T2). Reviewer: Opus.
- [x] **T3. Per-minute waits without attempts, atomic budget, no duplicate submit jobs.** The limiter
      hits before it checks, `SubmitGeneration` treats `429 rate_limit_exceeded` like concurrency, every
      wait path touches `updated_at`, and the T3 tests. Tech spec: Behaviour in code (T3), Tests (T3).
      Reviewer: Opus.
- [x] **T4. Append-only in the database.** The `--log-bin-trust-function-creators=1` flag on the
      MySQL service in both compose files (privilege check already failed, maintainer chose the flag),
      the dev MySQL container recreated, Migration C and the T4 tests. Tech spec: Model and contracts
      (Migration C), Behaviour in code (T4), Tests (T4), Risks. Reviewer: Opus.
- [ ] **T5. Clearer states and errors.** `awaiting_confirmation` in the resource and the TS type, the
      confirming copy in `GenerationCard.vue`, "Try again." on transient failure messages, the health
      body without details, and the T5 tests. Tech spec: Model and contracts (`GenerationResource`),
      Behaviour in code (T5), Tests (T5). Reviewer: Sonnet.
