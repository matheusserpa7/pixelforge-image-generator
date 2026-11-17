# 006 Admin report: PRD

Status: **implemented** · 2026-11-17

## Problem

Operators cannot see how much money the app moves. The brief's first stretch goal asks for a report
of credit spend (what users were charged), provider cost (what Pixelforge charged us) and profit, per
day and per user, where the provider cost matches `GET /v1/usage` for the deployed key. Today the
only way to answer "did we bill correctly?" is to query the database by hand.

## Users

Operators: users with `is_admin` (ADR 0014). Regular users never see the report or a link to it.

## In scope

- An admin-only **Report** page, linked from the sidebar for admins only.
- A date range in UTC days, default the last 30 days including today, at most 92 days (the
  `GET /v1/usage` limit). Invalid or too long ranges show a validation error, not a broken page.
- **Totals** for the range: generations charged, charged to users, provider cost, profit
  (charged − provider cost), and Pixelforge's own total cost for the same range.
- **Per day** (every UTC day in the range, days without charges shown as zeros): generations,
  charged, provider cost, profit, Pixelforge's cost for that day, and whether the two provider costs
  match exactly. A day is attributed by when the generation **completed** at Pixelforge (UTC), the
  same rule Pixelforge uses, so a matching day matches to the nano.
- **Per user** for the range: name and email, generations, charged, provider cost, profit, sorted by
  charged, highest first. Charges of deleted users stay in the totals under "Deleted user".
- Reconciliation status for the range: "Matches Pixelforge" when every day matches, otherwise the
  mismatching days are highlighted with the difference, and a short explanation of the usual cause
  (a generation succeeded at Pixelforge but our record has not caught up yet; reconciliation will
  apply it).
- If Pixelforge's usage cannot be read (network, limits, key), the page still shows our own figures
  and says that the Pixelforge comparison is unavailable right now.
- Amounts are shown in USD with enough decimals to see sub-cent differences; the exact nine-decimal
  value is available on hover.

## Out of scope

- Credit balances and purchases (spec 007).
- CSV export, charts, per-user drill-down into individual generations.
- Admin management UI (admins stay set in the database, ADR 0014).
- Correcting mismatches from the page; the reconciliation job already does it.
- Comparing against Pixelforge for other keys or environments (one key per environment).

## Acceptance

1. A non-admin gets 403 on the report URL and sees no Report link; a guest is sent to login.
2. With default range, an admin sees 30 rows of days ending today (UTC), totals, per-user rows and
   the Pixelforge comparison.
3. Charged, provider cost and profit equal the sums of the billing ledger for generations completed
   in the range; failed generations contribute nothing; no float is involved anywhere.
4. A generation completed at 23:59:59 UTC counts on that day, one completed at 00:00:00 the next.
5. When local provider cost for a day equals Pixelforge's `cost_usd` for that day, the day shows as
   matching; when it differs, it is highlighted with the signed difference.
6. When `GET /v1/usage` fails, the page renders with local figures and an "unavailable" notice.
7. A range over 92 days or with start after end is rejected with a clear message.
8. Opening the page repeatedly does not spend more than one Pixelforge request per range per minute.

## Open questions

- None.
