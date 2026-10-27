# ADR 0083: Payment provider fees as information

Date: 2026-10-27

Status: accepted (owner's decisions 2026-10-27; implemented in Step 279)

## Context

Spec §12: fees reduce the payout, not the price the customer paid; they are not part of the net comparison of an order. ADR 0020 and ADR 0022 left fees as an informational signal for later. Merchants still want to see what a payment cost them.

## Decisions

1. **Stored as their own operations, never as money of the order.** A fee is a `financial_transactions` row with kind `fee`, status `succeeded`, from the independent provider. Its id is `fee:<capture id>`, so it can never be found where a capture or refund is looked up by its id. The `ExactReferenceMatcher` only considers captures and refunds. A fee is never allocated to an order, and every reconciliation and unmatched-payment query already filters by kind.
2. **PayPal:** taken from the capture the adapter already reads, `seller_receivable_breakdown.paypal_fee`. No extra call and no extra permission.
3. **Stripe** (owner's decision: the permission is optional):
   - for each succeeded capture of the audit window without a fee, `GET /v1/balance_transactions?source=<charge id>` reads the `charge` / `payment` balance transaction and its `fee`;
   - at most `max_fees_per_run` (100) per run; a charge without a balance transaction yet is retried after `fee_retry_minutes` (6 h);
   - the restricted key needs `Balance: Read`. Without it Stripe answers 403, the health shows `sync.fees.state = permission_missing`, the Stripe panel shows a hint, and nothing else changes;
   - the fee is in the balance (settlement) currency, which may differ from the charge currency.
4. **Where it is shown** (owner's decision):
   - the order card: «Payment provider fee» per currency and «Net after fee» when the fee currency is the order's currency and the captured amount is known;
   - the overview: «Fees, last 30 days» per mode and currency, with the share of the captures in the same currency;
   - the CSV export of transactions (kind `fee`, already supported).

   The overview block is hidden without `reconciliation.read`; amounts are null without `money.view`. The order card follows the existing rule for capture amounts.
5. **Not now:** refunded fees (Stripe returns part of the fee on a refund; PayPal on some refunds), payouts and fee-rate incidents. Only the fee charged on the capture is shown.

## Consequences

- The fee of a capture is visible about one sync after the capture (15 minutes for Stripe). Captures older than the audit window get no fee.
- Shares are computed per currency only; currencies are never added up.
