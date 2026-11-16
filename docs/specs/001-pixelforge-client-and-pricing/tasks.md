# 001 Pixelforge client and pricing: tasks

Status: **approved** · 2026-11-16 · Tech spec: [tech-spec.md](tech-spec.md)

- [x] **T1. Pixelforge client.** `DecimalJson`, `PixelforgeException`, the DTOs the client returns and
      accepts (`Pricing`, `GenerationRates`, `TokenUsage`, `CreateGenerationRequest`,
      `PixelforgeGeneration`, `CreatedGeneration`, `PixelforgeGenerationError`), `SamplePricing`, the
      three client methods on the HTTP client and the fake (including idempotency), the timeout config,
      and the singleton binding. Tech spec: Model and contracts, Behaviour in code (HTTP client, Fake
      client). Reviewer: Opus.
- [x] **T2. Estimate and actual cost.** `CostEstimator`, `ActualCost`, `CostEstimate`, `GenerationCost`,
      and the float-token architecture check on Billing and Pixelforge. Tech spec: Behaviour in code
      (Estimate, Actual cost). Reviewer: Opus.
- [ ] **T3. Cached pricing.** `GetCachedPricing`, `PricingUnavailable`, `billing.pricing_cache_seconds`.
      Tech spec: Behaviour in code (Cached pricing). Reviewer: Opus.
