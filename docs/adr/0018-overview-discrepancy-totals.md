# ADR 0018: Overview Summary and Discrepancy Totals

Date: 2026-10-10

Status: accepted with changes by the owner on 2026-10-16 (implemented in Step 63; original follow-ups in `docs/backlog-old.md`).

## Context

Spec §24: the overview shows active incidents, source coverage, the last successful check and currency-specific discrepancy totals; "no problems found" only when every required check and data source is fresh; potential revenue loss is never shown as a proven amount. Invariants: money is minor units without floats; charge/refund/fee/payout semantics are never mixed; unknown is not zero.

## Decisions

- **Source of the totals:** `verified_discrepancy_minor` of active money incidents (`open` or `acknowledged`; a snoozed incident is still active, since a snooze only mutes notifications). Resolved incidents are excluded. This is the absolute difference of the latest finding behind the incident — the same "verified discrepancy" an incident page shows.
- **Grouping:** the database sums per verified provider mode (`live`, `test`, or unverified), currency **and** component (`capture`, `refund`, `multiple_captures`, `payment_without_order`, …). Totals are never added across modes, currencies or components, so captures and refunds are never one number. The UI labels them "verified discrepancy", not loss. Mode separation was implemented in Step 162.
- **Unknown amounts:** active money incidents without a currency or a verified amount are counted (`unknown_amount_incidents`) and shown as "amount unknown", never as zero.
- **Exponent:** from the reconciliation findings of the same currency; if none is known the amount is shown without formatting it as money.
- **Overall state in the UI** (client-side, from store coverage and the counts): "problems found" when there are active incidents; "no problems found" only when every store has a fresh connector, money reconciling, payment attempts observed and passing checkout checks; otherwise "data incomplete". No store → onboarding prompt.
- `GET /api/v1/overview`, read for every tenant role; the latest 5 active incidents and the latest 5 finished checks.

## Known limits

- A source that did not report its mode remains in the unverified group; historical incidents with mixed-mode evidence are also unverified.
- A sum above the signed 64-bit range fails on SQLite (tests); PostgreSQL sums as `numeric`.


## Owner decision (2026-10-16)

- Totals logic (active incidents only, per currency and per component, labelled "verified discrepancy", unknown amounts counted separately) and the overall state rules are confirmed.
- Visibility of money amounts to non-owner roles becomes a company setting controlled by the tenant owner (implemented in Step 161).
- Test-mode and live incidents must not be combined in totals (implemented in Step 162).

Step 263 (ADR 0079): the organisation switch `show_money_amounts_to_team` is replaced by the per-member permission `money.view`; amounts stay masked exactly as before for members without it.
