# 0018. Front-end lint and format tooling

Status: accepted (2026-11-16)

## Context

The current Vue starter kit ships with Vite+ (`vp check`, which runs Oxlint and Oxfmt). The project
standard is ESLint, Prettier and `vue-tsc`.

## Decision

Keep Vite+ only as the build tool (`vp build`, `vp dev`). Lint with ESLint (flat config,
`typescript-eslint`, `eslint-plugin-vue`) and format with Prettier using the kit's style (4 spaces,
single quotes, 80 columns, Tailwind class sorting). Type-check with `vue-tsc`.

## Consequences

- One well-known lint and format stack for reviewers.
- The kit's `lint` and `fmt` sections in `vite.config.ts` are removed, so there is one source of
  truth.
