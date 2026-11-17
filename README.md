# Pixelforge Studio

Signed-in users generate images through [Pixelforge](https://pixelforge.promptedit.dev/docs), a
simulated image-generation API, and pay exactly what each generation cost Pixelforge plus a 25%
markup. Failed generations are free, and no generation is ever charged twice, even when webhooks
arrive twice, out of order, or not at all.

This is a take-home assessment for PromptEdit. The brief is summarised in
[docs/product/overview.md](docs/product/overview.md) and the API in
[docs/product/pixelforge-api.md](docs/product/pixelforge-api.md).

> **Status:** all specs (000 to 009) done, deployed at
> **https://pixelforge.matheusserpa.online**. A signed-in user sees the estimate and maximum before
> submitting, and follows each generation live in their history with its actual charge and image. A
> job sends it to Pixelforge under the provider's limits, and a submission the provider may have
> accepted is never failed locally. Signed webhooks, deduplicated and applied in any order, plus a
> polling safety net, bring it to its final state, a success is charged once, and its image is copied
> to our storage. Admins get a report of charged, provider cost and profit per day and per user,
> reconciled to the nano with `GET /v1/usage`. Each user has a credit balance topped up by a fake
> "Buy credits" button, and a generation is accepted only when its maximum charge fits the available
> credit. See the [roadmap](docs/roadmap.md).

## Run it

Requirements: Docker (with Compose) and `make`.

```bash
docker compose up
```

The first start installs Composer and npm dependencies, creates `.env` with an app key and runs
the migrations. Then open http://localhost:8080 and register an account.

| URL                            | What                                 |
| ------------------------------ | ------------------------------------ |
| http://localhost:8080          | The app                              |
| http://localhost:8080/health   | MySQL and Redis check (JSON)         |
| http://localhost:8080/horizon  | Queues (admins only)                 |
| http://localhost:8080/pulse    | Metrics (admins only)                |
| http://localhost:8080/admin/report | Money report (admins only)       |

Port 8080 taken? Set `APP_PORT` in `.env`. `make fresh` seeds `test@example.com` and an admin,
`admin@example.com`, both with the password `password`.

To receive Pixelforge webhooks locally, add your API key and signing secret to `.env` and start
the tunnel: `docker compose --profile tunnel up`. The public URL is in `docker compose logs tunnel`.

| Command        | What it does                                           |
| -------------- | ------------------------------------------------------ |
| `make up`      | Build and start the stack, wait until healthy          |
| `make down`    | Stop it                                                |
| `make test`    | Pest (unit, architecture and feature tests on MySQL)   |
| `make lint`    | Pint, ESLint, Prettier and `vue-tsc`                   |
| `make analyse` | Larastan at level max                                  |
| `make fix`     | Fix style issues                                       |
| `make shell`   | Shell in the app container                             |
| `make fresh`   | Recreate the database with seed data                   |
| `make deploy`  | Ship `main` to the production VPS (`DEPLOY_HOST=...`)  |

Production runs the same image on a VPS, behind its shared edge proxy, at
https://pixelforge.matheusserpa.online. Ship a version with `make deploy DEPLOY_HOST=<ssh destination>`;
first install, edge proxy and operations are in [docs/deployment.md](docs/deployment.md).

## Architecture

A conventional Laravel 13 app with an Inertia + Vue 3 front end, served by FrankenPHP. Controllers
stay thin and call one `Action` per use case. Pixelforge sits behind a `PixelforgeClient`
interface, with a fake for tests. All money goes through the `Money` value object, an integer
number of nano-dollars. Provider calls and webhook processing run as jobs on Redis, managed by
Horizon. MySQL holds the state, with constraints that make double charging impossible. Logs are
JSON lines on stdout, correlated by `request_id`.

```
app/Actions            one use case per class (handle method)
app/Data               immutable DTOs
app/Enums              GenerationStatus, ImageSize, ImageQuality
app/Services/Pixelforge PixelforgeClient + HTTP and fake implementations
app/Support/Money      Money value object (nano-USD, brick/math)
app/Jobs, app/Policies, app/Http/Controllers
```

## Key decisions

| Decision                                                                                       | Why                                                              |
| ---------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| [Money is integer nano-USD](docs/adr/0003-money-in-nano-usd.md)                                | Exact match with Pixelforge's 9-decimal costs, no float drift.   |
| [Markup rounds half-up to the nano, once](docs/adr/0004-markup-and-rounding.md)                | One rule, one place, no accumulated rounding.                    |
| [Actual cost uses the generation's own rates](docs/adr/0005-actual-cost-uses-generation-rates.md) | Matches what Pixelforge bills even if prices change.          |
| [Estimate shows expected and maximum](docs/adr/0006-cost-estimation.md)                        | Users see the worst case before they pay.                        |
| [Local ULID as idempotency key](docs/adr/0007-idempotent-creation.md)                          | Safe retries; webhooks that beat the POST response still match.  |
| [Verified, deduplicated webhooks](docs/adr/0008-webhook-verification-and-dedup.md)             | A unique event id in the database drops duplicates.              |
| [Status only moves forward](docs/adr/0009-monotonic-status.md)                                 | Out-of-order events cannot undo a final state.                   |
| [Append-only ledger, unique per generation](docs/adr/0010-exactly-once-billing-ledger.md)      | Charged exactly once, enforced by the database.                  |
| [Polling reconciliation](docs/adr/0011-reconciliation-polling.md)                              | Lost webhooks only delay the result.                             |
| [Redis limiter and Retry-After backoff](docs/adr/0012-provider-limits.md)                      | Respects 3 concurrent and 60 requests per minute.                |
| [Inertia polling, no WebSockets](docs/adr/0015-live-status-via-inertia-polling.md)             | Live status without extra infrastructure.                        |
| [FrankenPHP image for dev and prod](docs/adr/0016-docker-frankenphp.md)                        | One command locally, the same image on the server.               |
| [Single VPS behind the edge proxy](docs/adr/0022-single-vps-deployment-behind-edge-proxy.md)    | One-command deploys next to other sites, TLS by the shared proxy. |
| [Report by provider completion day](docs/adr/0023-admin-report-by-provider-completion-day.md) | Daily provider cost matches `GET /v1/usage` to the nano.         |
| [Derived balances, holds at the maximum](docs/adr/0024-derived-credit-balances-with-holds.md) | Users never spend credit they do not have; no counter to drift.  |

All decisions: [docs/adr](docs/adr/README.md).

## Built with AI, spec-first

This project is built with an AI coding assistant (Claude Code), so intent is written down before
code exists:

1. **PRD**: what and why (`docs/specs/NNN-name/prd.md`).
2. **Tech spec**: how, with files, schema and trade-offs (`tech-spec.md`).
3. **Tasks**: a checklist where each item is one small Conventional Commit (`tasks.md`).
4. **Implementation**: tests first for money rules.
5. **Verification**: `make lint analyse test` and a real `docker compose up` before a task is
   ticked.

The workflow itself is versioned in [.claude/](.claude/rules/sdd.md), so every session runs it
the same way:

| Role             | Model                         | What it does                                              |
| ---------------- | ----------------------------- | --------------------------------------------------------- |
| Spec session     | Opus                          | Writes the PRD, tech spec and tasks, then stops for human approval. After approval it only orchestrates. |
| `implementador`  | Sonnet                        | Implements one task with its test and returns with `make lint analyse test` green. |
| `revisor`        | Opus on risk areas, else Sonnet | Reviews the task against the spec; fixes what is small, returns what is big. |

The expensive model thinks once (the spec, and the review of what is risky), the cheaper one
writes the code, and no test runs twice unless something changed in between. Risk areas, which
always get the Opus reviewer and can never skip the spec: money and the billing ledger, webhooks
and generation status, provider calls, authorization and database schema.

Decisions that outlive a spec become ADRs. The rules for humans and agents are in
[CLAUDE.md](CLAUDE.md) and [.claude/rules/sdd.md](.claude/rules/sdd.md), and [docs/roadmap.md](docs/roadmap.md) lists what comes next. Every line is
reviewed and explainable; architecture tests guard the rules that matter most (strict types, no
floats in money code, no HTTP calls from controllers).
