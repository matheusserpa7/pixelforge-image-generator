# 0015. Live status through Inertia polling

Status: accepted (2026-11-16)

## Context

The history must show live status. Generations take from a few seconds to about a minute and a half.

## Decision

Use Inertia polling (`usePoll`) to reload only the generations prop every few seconds while at least
one generation is not final. No WebSockets.

## Consequences

- No extra infrastructure (no socket server).
- A few seconds of latency and some extra requests. WebSockets (Laravel Reverb) are a possible later
  evolution if latency or load become a concern.
