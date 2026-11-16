# pixelforge-studio: spec-driven workflow

Product: [docs/product/overview.md](../../docs/product/overview.md). Provider API: [docs/product/pixelforge-api.md](../../docs/product/pixelforge-api.md). Durable decisions: [docs/adr/](../../docs/adr/README.md). Specs: [docs/specs/](../../docs/specs/), ordered in [docs/roadmap.md](../../docs/roadmap.md). Fixed decisions, conventions and commands: [CLAUDE.md](../../CLAUDE.md).

## Spec before code

Read the product overview and the fixed decisions in `CLAUDE.md`. If the change decides anything, follow the `sdd` skill.

Write `docs/specs/NNN-name/prd.md`, `tech-spec.md` and `tasks.md` with status `draft` and stop. No code, no migration, no edits to `docs/product/` or `docs/adr/`. Approval is about that text. "Approve and execute" in the initial request does not count.

A folder with status `implemented` (or `Done` in the roadmap) is not a queue.

## Skipping the spec

Only when all three hold:

1. The right behaviour is already written in `docs/product/`, an ADR, a spec or a test, or the change has no behaviour (alignment, spacing, colour, copy that changes no rule).
2. No new term, column, permission, contract, flow or decision worth recording is born.
3. The fix fits in one area and does not touch a risk area: money and the billing ledger; webhooks and generation status; provider calls (idempotency, rate limiter, retries); authorization and database schema.

## Execution

The spec session runs on Opus, medium effort. After approval it does not implement: it hands each task to the `implementador` agent (Sonnet) and then to the `revisor` agent (Opus, Sonnet or none, as the task says), both in `.claude/agents/`.

One task at a time. If the code diverges, the tech spec is updated in the same change. The test is born in the same task.

A task closes with the bar green, run once: `make lint analyse test` (the stack must be up: `make up`).

Tests run only inside the `app` container, through `make` or `docker compose exec -T app ...`: the host has no PHP and cannot reach MySQL. Never run `make fresh` or `php artisan migrate:fresh` to prepare tests and never override `DB_DATABASE`: `phpunit.xml` already points Pest at the separate `testing` database, and anything else wipes the dev database.

Tests never call the real Pixelforge API: use the fake `PixelforgeClient` or `Http::fake()`. No floats in money code.

Agents never commit. After the reviewer approves a task, the main session makes one Conventional Commit for it (`feat:`, `fix:`, `docs:`, `chore:`, `test:`, `ci:`, `refactor:`), with no `Co-Authored-By` or other AI attribution trailer. No push or PR unless explicitly asked.

In the last task, the delta goes into `docs/adr/` (plus its README index), `docs/product/`, `docs/roadmap.md`, `README.md` and the fixed decisions in `CLAUDE.md`, only where the content changed. The folder becomes `implemented`.

Procedure: skill `sdd` (`.claude/skills/sdd/SKILL.md`).
