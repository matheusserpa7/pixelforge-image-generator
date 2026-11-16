# 0014. Authorization through a Policy and an admin Gate

Status: accepted (2026-11-16)

## Context

Users must only see their own generations. Operators need internal panels.

## Decision

- A `GenerationPolicy` restricts every read to the owner, and list queries are scoped to the current
  user.
- A boolean `users.is_admin` and a single `admin` Gate protect Horizon, Pulse and future operator
  pages.

## Consequences

- One place to check per resource, easy to test.
- Admins are set in the database or by the seeder; there is no admin management UI.
