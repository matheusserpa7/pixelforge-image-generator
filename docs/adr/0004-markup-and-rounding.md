# 0004. Markup and rounding

Status: accepted (2026-11-16)

## Context

Users pay Pixelforge's cost plus 25%. Provider cost is an integer number of nanos; multiplying by
1.25 can leave a fraction of a nano (.25, .5, .75).

## Decision

User charge = provider cost × (1 + markup / 100), rounded **half-up** to the nano. The markup percent
comes from `config('billing.markup_percent')` (`BILLING_MARKUP_PERCENT`, default 25). The rounding rule
lives in exactly one place (`Money::withMarkup()`) and is applied to the **total** provider cost of a
generation, never per token type, so roundings never accumulate.

## Consequences

- At most half a nano (0.0000000005 USD) of difference per generation, always in a known direction.
- Changing the markup is a config change; past charges are stored, so they do not change.
- The markup is copied onto the generation (`generations.markup_percent`) when it is created, and
  its charge uses that copy. A config change only affects new generations, so a user is never charged
  at a markup they were not quoted. Spec: [010-review-hardening](../specs/010-review-hardening/).
