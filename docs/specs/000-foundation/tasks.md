# 000 Foundation: tasks

Each task is one Conventional Commit. Tick only after it is verified.

- [x] **T1. Documentation.** `CLAUDE.md`, `docs/product/*`, ADRs 0001–0020, this spec, roadmap.
- [x] **T2. Scaffold.** Vue starter kit, renamed, trimmed with `install:features` (registration only).
- [x] **T3. Docker.** `Dockerfile`, `compose.yaml`, `compose.prod.yaml`, entrypoint, Caddyfile, PHP
      config, MySQL init script, `Makefile`, `.env.example`, `.dockerignore`.
- [x] **T4. Quality tooling.** Pest, Larastan (max), Pint (strict types), ESLint, Prettier,
      `vue-tsc`; codebase passes all of them.
- [x] **T5. Domain skeleton.** Enums, `Money` with unit tests, `PixelforgeClient` interface, HTTP and
      fake implementations, binding, `services.pixelforge` and `billing` config.
- [x] **T6. Security baseline.** Security headers, cookies, trusted proxies, rate limiters, stateless
      webhook route group, `is_admin`, `admin` Gate.
- [x] **T7. Observability.** JSON logs, request context, `/health`, Horizon, Pulse, Sentry,
      failed-job logging.
- [ ] **T8. Architecture and smoke tests.**
- [ ] **T9. CI.** GitHub Actions workflow.
- [ ] **T10. README.**
- [ ] **T11. Verification.** `docker compose up` healthy, registration works in the browser,
      `make lint analyse test` green; output recorded in the final report.
