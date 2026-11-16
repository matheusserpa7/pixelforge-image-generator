# CLAUDE.md

Rules for anyone (human or AI) changing this repository. Read this file first.

## What this is

`pixelforge-studio`: a Laravel + Inertia + Vue app where authenticated users generate images through
Pixelforge (a simulated image-generation API) and are charged exactly what each generation cost plus
a 25% markup. Product summary: `docs/product/overview.md`. API summary: `docs/product/pixelforge-api.md`.

Evaluation priorities, in order: (1) correct money, (2) robustness against limits, overloads, failures
and duplicate or out-of-order webhooks, (3) clear UX for cost, status and errors, (4) readable code
that is easy to change. A small correct app beats a big wrong one. No DDD, hexagonal, microservices
or abstractions without an immediate use.

## Language

- Everything committed is in English: code, names, comments, commit messages, docs, specs, ADRs,
  README, UI copy, error and log messages.
- Conversation with the maintainer happens in Portuguese (questions, summaries, progress reports).

## Spec-first workflow (mandatory)

Every change follows: **PRD → tech spec → tasks → implementation → verification.**
The process (when a spec is needed, approval, the implementer and reviewer agents, the test bar,
commits, closing the docs) lives in one place: [.claude/rules/sdd.md](.claude/rules/sdd.md).
The step-by-step procedure is the `sdd` skill (`.claude/skills/sdd/SKILL.md`).

## Fixed decisions (see ADRs; specs must follow them)

1. Money is an integer number of **nano-USD** (1 USD = 1,000,000,000) stored as `BIGINT`. Floats are
   forbidden everywhere. Arithmetic goes through `App\Support\Money\Money` (backed by `brick/math`).
   ADR 0003.
2. User charge = provider cost × (1 + markup), rounded half-up to the nano, markup percent from
   `config('billing.markup_percent')`, applied in exactly one place. ADR 0004.
3. Actual cost uses the `rates` stored on the generation object and its `usage`, never cached rates.
   ADR 0005.
4. Estimates come from cached `GET /v1/pricing`: text tokens `ceil(chars / 4)`, 765 per reference
   image, exact output tokens, reasoning as a range. UI shows the estimate and the maximum. ADR 0006.
5. Each local generation has a ULID sent as `Idempotency-Key` and in `metadata`. ADR 0007.
6. Webhooks: HMAC-SHA256 of `<timestamp>.<raw body>`, constant-time compare, reject timestamps older
   than 300 s, store in `webhook_events` with a unique event id, answer 2xx fast, process in a job.
   ADR 0008.
7. Status only moves forward; `succeeded` and `failed` are final. ADR 0009.
8. Charge exactly once: append-only ledger, unique per generation, inside a transaction with a row
   lock. Failure costs zero. ADR 0010.
9. A scheduled job polls generations stuck for too long (safety net for lost webhooks). ADR 0011.
10. Provider limits (3 concurrent, 60 req/min): Redis limiter in the job; on `429`/`503` release
    respecting `Retry-After`, with backoff and a max attempts cap. ADR 0012.
11. Images are downloaded to a Laravel disk (local now, S3-compatible). ADR 0013.
12. Users only see their own generations, enforced by a Policy. ADR 0014.
13. Live status through Inertia polling, no WebSockets. ADR 0015.

## Code conventions

- `declare(strict_types=1);` in every PHP file (Pint enforces it). Classes are `final` by default.
  Type everything.
- `app/Actions`: one use case per class with a single `handle` method.
- `app/Data`: immutable `readonly` DTOs.
- `app/Enums`: backed enums (`GenerationStatus`, `ImageSize`, `ImageQuality`).
- `app/Services/Pixelforge`: `PixelforgeClient` interface, HTTP implementation, fake for tests.
- `app/Support/Money`: the money value object. No floats.
- `app/Http/Controllers`: thin, only orchestrate (validate → action → response). Controllers never
  touch the HTTP client directly (enforced by an architecture test).
- `app/Jobs`, `app/Policies`: as usual in Laravel.
- Tests with Pest. Every money rule gets a unit test. Feature tests run against MySQL.

## Commands

Everything runs inside Docker; the host only needs Docker and `make`.

| Command        | What it does                                              |
| -------------- | --------------------------------------------------------- |
| `make up`      | Build and start the dev stack (`docker compose up -d`)    |
| `make down`    | Stop the stack                                            |
| `make test`    | Run Pest                                                  |
| `make lint`    | Pint (check), ESLint, Prettier (check), `vue-tsc`          |
| `make analyse` | Larastan                                                  |
| `make fix`     | Pint, ESLint `--fix`, Prettier `--write`                  |
| `make shell`   | Shell inside the `app` container                          |
| `make fresh`   | `migrate:fresh --seed`                                    |

Before declaring any task done, run `make lint analyse test` and show the real output.
