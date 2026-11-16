# 0020. Authentication scope

Status: accepted (2026-11-16)

## Context

The starter kit can enable email verification, registration, two-factor authentication, passkeys and
password confirmation. The brief only needs signed-in users.

## Decision

Keep login, registration, password reset and profile settings. Remove email verification, two-factor
authentication, passkeys and password confirmation with the kit's own `install:features` command.
Fortify rate limits login (5 attempts per minute per email and IP).

## Consequences

- Less code to explain and maintain; registration works without a mail server.
- Removed features can be brought back from the starter kit later.
