# ADR 0071: Amounts and provider transaction ids for «capture not recorded by store»

Status: accepted by the owner on 2026-10-20, implemented in Step 208.

## Context

The live Shopware run (Step 206) produced real MONEY_CAPTURE_NOT_RECORDED incidents: PayPal had captured the money, SwagPayPal left the orders `in_progress`. The incident and its findings showed «unknown» in every amount column: the rule compares a fact (a provider capture vs. the store's paid marker), so it filled neither `expected_minor` / `actual_minor` / `difference_minor` nor the incident amount. «Unknown» there read as missing data, and the merchant could not see which PayPal transaction to look for. The incident also did not count towards the money at risk in the overview and the daily digest.

## Decision

1. **Findings of MONEY_CAPTURE_NOT_RECORDED carry amounts:** «expected» is the order total, «found» is the amount captured at the provider; «difference» stays empty and the cabinet shows the store state in words (not marked as paid / waiting for the store / marked as paid). MONEY_CANCELLED_PAID gets the same two amounts.
2. **The incident amount of MONEY_CAPTURE_NOT_RECORDED is the captured amount**: the buyer's money is with the merchant while the order may not be processed, so it counts in the money at risk. If the same order also has a capture amount mismatch, that difference is a separate incident (another component) and both amounts are shown.
3. **Provider transaction ids:** findings about the order's captures (capture amount, capture not recorded, multiple captures, cancelled but paid, refund missing / extra) keep `evidence.capture_operation_ids` — the provider's own capture ids (PayPal capture id, Stripe charge) as seen in the provider dashboard. The cabinet shows them under the check name.
4. **«—» instead of «unknown»** in rules without an amount by meaning (payment not matched, multiple captures, currency mismatch, cancelled but paid, the difference of capture not recorded). «Unknown» stays for unknown/unsupported results and for missing data.
5. The incident card shows the status of each finding, so a mismatch and the later «ok» of the same check are distinguishable.

## Consequences

- Existing findings change once after the release (new amounts and evidence change the result hash); open incidents of this rule get their amount on the next run.
- Store-reported paid markers are still not capture evidence; the amounts come from provider captures only.
