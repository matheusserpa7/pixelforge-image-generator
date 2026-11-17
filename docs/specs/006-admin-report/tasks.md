# 006 Admin report: tasks

Status: **approved** · 2026-11-17 · Tech spec: [tech-spec.md](tech-spec.md)

- [ ] **T1. Provider usage in the client.** `PixelforgeClient::usage()`, `ProviderUsage` DTO, HTTP
      and fake implementations, `PixelforgeRequestLimiter::attempt()`, `Money::minus()`, with their
      unit and `Http::fake` tests. Tech spec: Model and contracts → Client; Report (limiter, `minus`).
      Reviewer: Opus.
- [ ] **T2. Report action and admin route.** `BuildAdminReport`, `AdminReport` DTOs, the cached and
      budgeted usage call, `AdminReportRequest`, `AdminReportController`, route behind `can:admin`,
      `auth.isAdmin` shared prop, and the action and HTTP feature tests. Tech spec: Report, HTTP,
      Tests. Reviewer: Opus.
- [ ] **T3. Report page.** `admin/Report.vue` (range form, reconciliation banner, totals, per-day and
      per-user tables) and the admin-only nav link. Tech spec: UI. Reviewer: Sonnet.
- [ ] **T4. Close the docs.** ADR 0023 plus README index, product overview stretch goal marked
      delivered if wording changes, roadmap (006 `Done`, 007 `Next`), README status, `CLAUDE.md`
      fixed decision 15. Reviewer: none.
