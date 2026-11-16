# 000 Foundation: PRD

## Problem

Before any business rule (pricing, generations, webhooks) can be built and trusted, the project needs
a foundation where money code is safe by construction, the stack starts with one command, and quality
checks run the same way locally and in CI.

## Goal

A running, documented skeleton of `pixelforge-studio`:

- `docker compose up` starts the app, workers, scheduler, MySQL, Redis and Vite.
- Users can register, log in and log out.
- The money value object exists, is tested, and is the only way to do money arithmetic.
- The domain contracts (enums, `PixelforgeClient`) exist so the next specs only add behaviour.
- Security, observability and quality baselines are in place from day one.

## Users

- **End user:** registers and logs in (nothing else to do yet).
- **Operator (admin):** opens Horizon and Pulse.
- **Developer / reviewer:** reads the docs, runs the stack, runs the checks.

## Requirements

1. Laravel 13 with the official Vue starter kit, trimmed to login, registration, password reset and
   profile settings (ADR 0020).
2. Docker: production-ready multi-stage image (FrankenPHP), dev and prod compose files, entrypoint,
   Makefile, complete `.env.example` with no secrets.
3. Architecture folders and contracts: `Actions`, `Data`, `Enums` (`GenerationStatus`, `ImageSize`,
   `ImageQuality`), `Services/Pixelforge` (`PixelforgeClient` interface, empty HTTP and fake
   implementations, container binding), `Support/Money`, `Jobs`, `Policies`.
4. `Money` value object in nano-USD with unit tests: addition, multiplication by tokens, markup,
   rounding and formatting.
5. Security baseline: security headers, secure cookies, trusted proxies, login and generation rate
   limits, a stateless webhook route group with its own rate limit, `is_admin` and an `admin` Gate.
6. Observability baseline: JSON logs with `request_id` and `user_id` propagated to jobs, `/health`
   checking MySQL and Redis, Horizon and Pulse for admins, optional Sentry, failed jobs logged.
7. Quality: Pest (with architecture and smoke tests), Larastan at the highest workable level, Pint,
   ESLint, Prettier, `vue-tsc`, GitHub Actions workflow including `composer audit` and `npm audit`.
8. Documentation: `CLAUDE.md`, product docs, ADRs, this spec, roadmap, README.

## Out of scope

Pixelforge HTTP calls, pricing and estimates, generation lifecycle, webhooks processing, generate and
history pages, deployment, admin report, credit balances. They have their own specs (see roadmap).

## Acceptance criteria

- `docker compose up` brings every service to healthy; `/`, `/login`, `/register` and `/health`
  answer; a user can register and reach the dashboard.
- `make lint analyse test` passes.
- A non-admin gets `403` on `/horizon` and `/pulse`; an admin gets `200`.
- `/health` returns `200` with `{"status":"ok"}` when MySQL and Redis are up.
- Every PHP file declares strict types; controllers do not use the HTTP client; `Support/Money` uses
  no floats (architecture tests).
