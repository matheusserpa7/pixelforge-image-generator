# 007 Credit balances: tasks

Status: **implemented** · 2026-11-17 · Tech spec: [tech-spec.md](tech-spec.md)

- [x] **T1. Balance and blocking.** `credit_purchases` migration with the opening-balance backfill
      (`OpeningBalances::backfill()`), `CreditPurchase` model and `CreditPurchaseKind` enum,
      `CreditBalance` DTO, `GetCreditBalance`, `InsufficientCredits`, the user-row lock and check in
      `CreateGeneration`, `store` error and `estimate` fields (`available`, `affordable`),
      `UserFactory::withCredit()`, seeder credit, existing generation tests funded, and the unit and
      feature tests for all of it. Tech spec: Model and contracts; Behaviour in code → Blocking,
      HTTP (store, estimate); Seed and factories; Tests. Reviewer: Opus.
- [x] **T2. Buy credits back end.** `credit_packs_usd` config, `PurchaseCredits` with the idempotency
      key, `PurchaseCreditsRequest`, `CreditController` (`index`, `purchase`), routes and the
      `credit-purchases` limiter, the lazy `credits` shared prop, and their feature tests. Tech spec:
      Model and contracts → Config, actions; Behaviour in code → HTTP; Tests. Reviewer: Opus.
- [x] **T3. Credits UI.** `credits/Index.vue`, the sidebar Credits item with the available amount, and
      the available credit, disabled submit and "Buy credits" link in `GenerateForm.vue`. Tech spec:
      UI. Reviewer: Sonnet.
- [x] **T4. Close the docs.** ADR 0024 (derived balances with holds at the maximum charge) plus
      README index, product overview stretch goal and glossary (balance, held, available), roadmap
      (007 `Done`), README status, `CLAUDE.md` fixed decision 16. Reviewer: none.
