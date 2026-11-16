# 0002. Stack

Status: accepted (2026-11-16)

## Context

The brief requires Laravel, Vue and Inertia. We also need a queue for asynchronous provider calls,
locks for exactly-once billing, and a relational database with row locks and unique constraints.

## Decision

- PHP 8.4, Laravel 13, the official Vue starter kit (Inertia 3, Vue 3, TypeScript, Tailwind 4,
  Fortify authentication).
- MySQL 8.4 (InnoDB row locks, unique constraints, `BIGINT`).
- Redis 8 for queues, cache, sessions and locks. Laravel Horizon runs the workers.
- Pest for tests; Larastan, Pint, ESLint, Prettier and `vue-tsc` for quality.
- `brick/math` for every monetary calculation.

## Consequences

- One language on the backend and one framework idiom: conventional Laravel folders, no extra layers.
- Redis becomes a hard dependency (queue, locks, sessions); the health check covers it.
