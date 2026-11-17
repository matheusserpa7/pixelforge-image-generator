# 005 Deployment: tasks

Status: **approved** · 2026-11-17 · Tech spec: [tech-spec.md](tech-spec.md)

- [x] **T1. Production stack with HTTPS and trusted proxies.** `config/trustedproxy.php` and the
      `bootstrap/app.php` change, the `.env.example` lines, the `Dockerfile` `setcap`, the
      `compose.prod.yaml` changes (domain, ports, Caddy volumes, start order, log rotation,
      `TRUSTED_PROXIES: ''`), the trusted-proxy feature test and the smoke checks. Tech spec: Decisions
      1–4, 6, 7; `config/trustedproxy.php`; `.env.example` additions; `compose.prod.yaml`;
      `Dockerfile`; Tests (T1 rows). Reviewer: Opus.
- [ ] **T2. Operator tools and runbook.** `users:make-admin`, the `DatabaseSeeder` production guard,
      their tests, `scripts/deploy.sh`, the `make deploy` target, `.dockerignore`, and
      `docs/deployment.md`. Tech spec: Decisions 5, 8; `users:make-admin` command; `DatabaseSeeder`
      guard; `scripts/deploy.sh` and `make deploy`; `docs/deployment.md`; Tests (T2 rows).
      Reviewer: Opus.
- [ ] **T3. First deployment, verified live.** Done by the main session with the maintainer, no
      implementer: the maintainer checks ports, memory and DNS on the VPS, creates the production
      Pixelforge key and `.env` on the server (secrets never pass through the session), runs the first
      `make deploy`, registers and is promoted to admin. The main session then walks the PRD
      acceptance list in the browser and records the result here. Anything that fails goes back to
      T1/T2 as a fix. Tech spec: `docs/deployment.md`, Risks. Reviewer: none.
