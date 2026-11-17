# 005 Deployment: tasks

Status: **implemented** · 2026-11-17 (revised: shared edge proxy) · Tech spec: [tech-spec.md](tech-spec.md)

- [x] **T1. Production stack with HTTPS and trusted proxies.** `config/trustedproxy.php` and the
      `bootstrap/app.php` change, the `.env.example` lines, the `Dockerfile` `setcap`, the
      `compose.prod.yaml` changes (domain, ports, Caddy volumes, start order, log rotation,
      `TRUSTED_PROXIES: ''`), the trusted-proxy feature test and the smoke checks. Tech spec: Decisions
      1–4, 6, 7; `config/trustedproxy.php`; `.env.example` additions; `compose.prod.yaml`;
      `Dockerfile`; Tests (T1 rows). Reviewer: Opus.
- [x] **T2. Operator tools and runbook.** `users:make-admin`, the `DatabaseSeeder` production guard,
      their tests, `scripts/deploy.sh`, the `make deploy` target, `.dockerignore`, and
      `docs/deployment.md`. Tech spec: Decisions 5, 8; `users:make-admin` command; `DatabaseSeeder`
      guard; `scripts/deploy.sh` and `make deploy`; `docs/deployment.md`; Tests (T2 rows).
      Reviewer: Opus.
- [x] **T3. Move the production stack behind the edge proxy.** `compose.prod.yaml` (no published
      ports, no `SERVER_NAME` override, no Caddy volumes, no `TRUSTED_PROXIES: ''`; joins the external
      `edge` network as `pixelforge-app`), revert the `Dockerfile` `setcap`, `.env.example` (drop
      `APP_DOMAIN`, rewrite the `TRUSTED_PROXIES` comment), the edge-chain test case,
      `scripts/deploy.sh` default path, `docs/deployment.md` rewritten for the edge proxy (including
      the edge server block and the certificate command). Tech spec: Revision; Decisions 1–4;
      `compose.prod.yaml`; `Dockerfile`; `.env.example`; Edge proxy changes; `scripts/deploy.sh`;
      `docs/deployment.md`; Tests (T3 rows). Reviewer: Opus.
- [x] **T3 fix. Unique web service name on `edge` and runbook updates from the first deploy.**
      Rename the `compose.prod.yaml` web service to `pixelforge-app` (drop the alias), update
      `horizon` / `scheduler` `depends_on`, the header comment, every runbook command that targets
      the web container, the runbook's HTTPS block (proxy buffers), and keep the in-place-edit note
      already added to `docs/deployment.md` section 3. Smoke: `compose config` shows the service
      `pixelforge-app` on `default` and `edge` with no `app` service. Tech spec: Decision 2;
      `compose.prod.yaml`; Edge proxy changes. Reviewer: Opus.
- [x] **T4. First deployment, verified live.** Main session with the maintainer, no implementer:
      the maintainer creates the production Pixelforge key and fills `~/pixelforge-studio/.env` on the
      server (secrets never pass through the session); the session clones the repository, runs the
      first `make deploy DEPLOY_HOST=aiflow`, and applies the edge proxy changes (server block,
      certificate, reload) only after the maintainer approves them in chat; the maintainer registers
      and is promoted to admin. Then the session walks the PRD acceptance list and records the result
      here. Anything that fails goes back to T3 as a fix. Tech spec: Edge proxy changes,
      `docs/deployment.md`, Risks. Reviewer: none.

## T4 result (2026-11-17)

Deployed `1726cf1`, then `7c7cbfe` and `e73f9f0` (spec 009), at `https://pixelforge.matheusserpa.online`.

| # | Acceptance | Result |
| - | ---------- | ------ |
| 1 | Valid certificate, HTTP → HTTPS | Let's Encrypt `pixelforge.matheusserpa.online` (expires 2027-02-15, renewed by `renew.sh`); `http://` answers `301`. |
| 2 | `/health` 200 | `{"status":"ok","checks":{"database":"ok","redis":"ok"}}` through the public URL. |
| 3 | Register, estimate, generate, live status | Estimate $0.0723 (max $0.0864) shown before submit; generation reached `succeeded` with its image without a reload. |
| 4 | Webhooks applied in production | `ping` answered `200`. The first `generation.succeeded` was dropped as `invalid_payload` (numeric token counts); fixed by spec 009, after which `generation.processing` and `generation.succeeded` were `applied` within a second of arrival. |
| 5 | Admin opens Horizon and Pulse | Both open for the promoted account (0 failed jobs). The `403` for regular users is covered by existing feature tests, not re-checked live. |
| 6 | One-command deploy, data kept | `make deploy DEPLOY_HOST=aiflow` ran four times; database, images and certificate kept. |
| 7 | Other edge sites unaffected | Broken once: the web service was named `app` and hoop-ia's nginx resolved `app` to it (`502` for about five minutes). Restored by reconnecting with only the `pixelforge-app` alias, then fixed for good by renaming the service (T3 fix). A single-file bind mount also needed one edge restart (about a second). |
| 8 | Forged `X-Forwarded-For` | Covered by `TrustedProxiesTest` (edge chain); not re-checked live. |
| 9 | No default password | No seeded accounts; the only admin was promoted with `users:make-admin`. |
| 10 | Runbook | `docs/deployment.md`, updated with the edge-proxy lessons (service name, proxy buffers, in-place edits). |

Charges checked against the rates on each generation: 66,822,500 → 83,528,125 nanos and
47,195,000 → 58,993,750 nanos (× 1.25), one ledger entry each.
