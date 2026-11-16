# 0019. Observability baseline

Status: accepted (2026-11-16)

## Context

Asynchronous generations and webhooks are hard to debug without correlated logs.

## Decision

- Logs are JSON lines on stdout (Monolog `JsonFormatter`), collected by Docker.
- A middleware puts `request_id` (from `X-Request-Id` or a new UUID) and `user_id` in Laravel's
  `Context`. Context is appended to every log line and travels with queued jobs. Later specs add
  `generation_id` and `event_id`.
- `GET /health` checks MySQL and Redis and answers JSON (200 or 503). It runs without session.
- Horizon (queues) and Pulse (metrics) sit behind the `admin` Gate.
- Sentry is installed but only active when `SENTRY_LARAVEL_DSN` is set.
- Failed jobs land in `failed_jobs` and are logged at error level with their context.

## Consequences

- One `request_id` follows a request into the jobs it dispatched.
- No log shipping infrastructure in scope; `docker compose logs` is enough for now.
