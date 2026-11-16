# 0001. Spec-first workflow

Status: accepted (2026-11-16)

## Context

The app is built with an AI coding assistant and will be walked through and extended live. Code
written without a written intent is hard to review and hard to explain.

## Decision

Every change follows PRD → tech spec → tasks → implementation → verification. Each delivery lives in
`docs/specs/NNN-name/` with `prd.md`, `tech-spec.md` and `tasks.md`. `docs/roadmap.md` orders the
specs. Relevant decisions get a short ADR in `docs/adr/`. One Conventional Commit per completed task.

## Consequences

- Reviewers can read intent before code, and every file traces back to a spec.
- Slightly more writing up front; specs stay short to keep that cost low.
