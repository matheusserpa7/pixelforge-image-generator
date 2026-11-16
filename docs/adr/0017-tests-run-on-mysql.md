# 0017. Feature tests run on MySQL

Status: accepted (2026-11-16)

## Context

Correctness depends on MySQL behaviour: `BIGINT`, unique constraints, row locks. SQLite behaves
differently.

## Decision

Feature tests use a dedicated `testing` database on the same MySQL 8.4 server (created by an init
script) with `RefreshDatabase`. CI runs MySQL and Redis as services. Pure unit tests (money, enums)
need no database.

## Consequences

- Tests exercise the real constraints that protect money.
- Running tests needs the Docker stack (or the CI services).
