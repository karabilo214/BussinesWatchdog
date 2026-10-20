# ADR 0068: Closing the deviations found by the FIN-01…24 registry

Status: accepted by the owner on 2026-10-20 — (b) for FIN-15 and (b) for FIN-19; implemented in Step 201.

## Context

Step 200 runs every control example of TZ §35.1 through the real path (store snapshots → StripeSync → matcher → reconciliation → incidents), see `docs/reconciliation-registry.md`. 18 of 24 match the TZ. Six differ; all six either publish an amount the TZ forbids, or stay silent where the TZ wants a visible result. The existing unit tests did not show this, because they build allocations by hand instead of going through the matcher.

## Proposal

1. **FIN-08 — provider refund without a store refund.** A succeeded provider refund on a payment allocated to exactly this one order, and linked to no store refund, counts in RP. After the 60-minute refund grace this gives MONEY_REFUND_EXTRA with the amount, worded as a disagreement (a legitimate refund made directly in Stripe is possible, TZ §14). A payment allocated to several orders stays unlinked for a person to review.
2. **FIN-15 — paid order whose reference matches no provider payment.** If the order is paid, C = 0, and no provider payment matches the order's reference or verified metadata, the result is `unknown` with reason `payment_not_matched`. There is no amount and no money discrepancy. The case where the payment *is* found but has no capture stays MONEY_CAPTURE_MISSING with the amount (FIN-02). **Choice for the owner:**
   - (a) no incident: the order only appears in the reconciliation page's «не сопоставлено» list;
   - (b) an informational incident «Платёж не сопоставлен» without an amount (TZ §23 wording), with a notification. Recommended: (b). A paid marker with no payment anywhere is exactly what a shop owner wants to hear about, but it is not proven lost money. **Chosen: (b)** — rule `MONEY_PAYMENT_NOT_MATCHED`, component `capture` (it turns into MONEY_CAPTURE_AMOUNT or closes when the payment is linked later), severity **warning** so that channels with the default threshold are notified.
3. **FIN-17 — store refund with unknown `external_required`.** The result is `unknown`, reason `refund_external_unknown`. There is no amount and no incident, and the reconciliation page shows it as «возврат без подтверждения отправки».
4. **FIN-19 — cancelled paid order without a refund.** After the refund grace, an order with a cancelled store status, a store-reported paid marker, C > 0 and no refund gets a review result: reason `cancelled_paid_review`, without a money discrepancy. **Choice for the owner:**
   - (a) only a finding;
   - (b) an informational incident «Заказ отменён, но деньги не возвращены — проверьте». Recommended: (b), info severity, no amount. **Chosen: (b)** — rule `MONEY_CANCELLED_PAID`, component `cancelled_paid`, severity **warning** (owner's decision 2026-10-20, raised from the recommended info so that channels with the default threshold are notified). It closes when a store or provider refund appears or the order is no longer cancelled.
5. **FIN-20 — total changed after capture.** While MONEY_ORDER_CHANGED applies, MONEY_CAPTURE_AMOUNT is `unknown` (reason `order_changed_after_capture`) instead of a mismatch. The MONEY_ORDER_CHANGED incident shows the old and the new total as facts, but carries no verified discrepancy amount, so it is not counted in the overview totals or the digest.
6. **FIN-21 — currency mismatch.** When the referenced payment has another currency, MONEY_CAPTURE_MISSING is not raised (`unknown`, reason `currency_mismatch`). MONEY_CURRENCY_MISMATCH stays, without arithmetic.

All six: no migration (rule and reason codes only), a new rule version (`ALGORITHM_VERSION` v3), so findings recorded before keep their version. An incident whose amount an earlier version published loses the amount when the new rules give `unknown` for it (`order_changed_after_capture`, `currency_mismatch`); it stays open for review and is not re-notified. After the release `php artisan reconciliation:recheck-open-incidents` queues every order behind an open money incident once (`docs/ops/maintenance.md`).

## Consequences

- After the fix the registry test expects all 24 examples to match; `DEVIATIONS` becomes empty.
- Points 2 and 4 add new incident texts in ru/en/de.
