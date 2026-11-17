# 004 Generate and history UI: tasks

Status: **implemented** · 2026-11-16 · Tech spec: [tech-spec.md](tech-spec.md)

- [x] **T1. Image copy and image route.** `image_path` migration, `generation_images_disk` config and
      `.env.example` line, `DownloadGenerationImage`, the `afterCommit` dispatch in
      `ApplyGenerationSnapshot::applySucceeded`, `GenerationImageController` and the
      `generations.image` route, with their tests. Tech spec: Migration, Config,
      `DownloadGenerationImage` job, Dispatch, Image route, Tests (T1 rows). Reviewer: Opus.
- [x] **T2. Page data, estimate endpoint and submit flow.** `MoneyView`, `GenerationResource`,
      `EstimateGeneration`, `EstimateGenerationRequest`, `GenerationController@index` and `@estimate`,
      the routes table (throttles, `dashboard` redirect), the `generation-estimates` limiter, Fortify
      `home`, the store toast and redirect, the throttled-submit rendering, a minimal
      `pages/generations/Index.vue` that renders the props so the Inertia assertion finds the
      component, regenerated Wayfinder helpers, and the tests. Tech spec: `MoneyView` and
      `GenerationResource`, Routes and controller, Throttled submit, Tests (T2 rows). Reviewer: Opus.
- [x] **T3. Generate form, history and polling.** `types/generations.ts`, `GenerateForm.vue` with the
      live estimate, `GenerationCard.vue`, the full `Index.vue` with pagination and polling, the
      sidebar and logo changes, removal of the placeholder dashboard. Tech spec: Behaviour in code
      (Page). Reviewer: Sonnet. Then the main session checks the page in the browser.
