# 007 Credit balances: PRD

Status: **implemented** · 2026-11-17

## Problem

Today any signed-in user can generate as many images as they like: charges are recorded in the
billing ledger, but nothing limits spending. The brief's second stretch goal asks for a per-user
credit balance, a fake "Buy credits" button, and blocking generation when the user cannot afford it.

Two things make "can afford" non-trivial:

- The real charge is only known when a generation succeeds. Up front we only know the estimate and
  the **maximum** (ADR 0006).
- A user can have several generations queued or processing at once. Each of them will be charged
  later, so checking the balance alone would let a fast user queue far more than they can pay for.

## Users

- **Signed-in users** who generate images and need to know how much credit they have, what is
  reserved by work in progress, and how to top up.
- **The maintainer and reviewers**, who need a demo that shows blocking and topping up without any
  real payment.

## In scope

1. **Balance.** Each user has a credit balance in US dollars: everything bought minus everything
   charged. Failed generations cost nothing, so they never touch it.
2. **Held credit.** A generation that is queued or processing reserves its maximum possible charge.
   The reservation ends when the generation finishes: on success the real charge replaces it, on
   failure it simply disappears.
3. **Available credit** = balance − held. This is the number shown to the user and the number
   checked before a new generation.
4. **Blocking.** A generation is accepted only when its maximum charge is at most the available
   credit. Otherwise nothing is created, nothing is sent to Pixelforge, and the user sees how much
   the generation can cost, how much they have, and a way to buy credits. Two submits at the same
   moment can never both spend the same credit.
5. **Generate form.** Next to the estimate and maximum, the form shows the available credit. When the
   maximum is more than the available credit, the submit button is disabled and the form explains why
   and links to buying credits. The server still checks on submit.
6. **Fake "Buy credits".** A Credits page shows available, balance and held credit, offers fixed
   packs (5, 10 and 25 USD), and lists recent purchases. Buying a pack adds it instantly; no payment
   is taken and the page says so. A double click or a resent form never adds a pack twice.
7. **Credit in the navigation.** The available credit is visible on every page of the app and links
   to the Credits page.
8. **Existing users.** Users who already have charges when this ships start at an available credit
   of zero (not negative). New users start at zero too.

## Out of scope

- Real payments, refunds, invoices, coupons, or a signup bonus.
- Admin tools to grant or remove credit. Changing a balance by hand is a new ledger-style row, never
  an edit, and is left for later.
- Changes to the admin report: purchases are fake and are not revenue.
- Stopping or cancelling a generation that is already accepted. A charge for work Pixelforge has done
  is always recorded, even if it takes the balance below zero (possible only when Pixelforge changes
  its rates between the estimate and the submission, ADR 0005). A negative balance just blocks new
  generations until the user buys credits.

## Acceptance

1. A user with 0 available credit cannot create a generation; the response explains the maximum
   charge and the available credit, and no generation row or provider call exists afterwards.
2. After buying a 5 USD pack, available credit is exactly 5.000000000 USD and a generation whose
   maximum is at most that is accepted.
3. While that generation is queued or processing, available credit is reduced by its maximum charge.
4. When it succeeds, available credit = purchases − actual charge (held amount released). When it
   fails, available credit returns to its previous value.
5. Two concurrent submits whose maxima together exceed the available credit: exactly one is
   accepted.
6. Sending the same purchase form twice adds the pack once.
7. A user who had charges before this release sees 0 available credit (with no work in progress).
8. The generate form shows available credit and disables submit with a "Buy credits" link when the
   maximum exceeds it.
9. All amounts are exact nano-USD integers; no float is involved anywhere.

## Open questions

- None blocking. Pack sizes (5, 10, 25 USD) live in config and can change without a spec.
