# ADR 0072: Store payment state, refused payments with open orders, payments without order since connection

Status: accepted by the owner on 2026-10-20, implemented in Step 210.

## Context

The live Shopware run (Step 206) showed three gaps:

- Order 10005: PayPal refused the capture, the shop kept the order «open» with the payment «in progress». No money moved, so no money rule fired, and the order was invisible in the customer cabinet. The merchant can ship it unpaid, or it hangs.
- The owner panel showed only the order status (`open`) while Shopware showed the payment status «Paid» — the service did not keep the store's payment status at all, only the paid marker.
- «Payments without order» of the new Shopware-Test store listed old sandbox payments of the same PayPal account made before the store existed: the PayPal sync imports the account's history.

## Decision

1. **Store payment state.** `order.snapshot` gets an optional, platform-neutral `payment_state` (`open, pending, authorized, paid, partially_paid, refunded, partially_refunded, failed, cancelled, chargeback`). Shopware sends its order transaction state; WooCommerce has no separate payment state (its order status already says `pending/failed/cancelled`) and sends nothing. The service keeps it on the order (`metadata.payment_state`) and shows it; it is never capture evidence.
2. **New rule MONEY_PAYMENT_FAILED_ORDER_OPEN.** When every provider payment the order points to is `failed`/`cancelled`, nothing is captured, the store has not marked it paid, the order status is not closed (`cancelled, failed, refunded, trash`) and the store's payment state is not `failed`/`cancelled`: pending for **120 minutes** after the provider's refusal, then a mismatch. Incident «warning» (component `payment_failed_order_open`), the incident amount is the order total (money at risk if shipped). It is ok again when the store closes the order or marks the payment failed, or another payment is captured. The owner approved 60 minutes; 120 was chosen because WooCommerce cancels unpaid orders after its stock hold (60 minutes by default) on a cron of the same interval, so 60 minutes would flag every declined card.
3. **Payments without order only since the store was connected.** Provider captures older than the store's creation in the service are kept for matching but are no longer «payments without order» (scanner and the cabinet list).
4. The owner panel shows «Order status» and «Payment status» in words (platform status names translated, unknown ones as reported).

## Consequences

- Stores whose connector does not send `payment_state` still get the rule through the order status.
- An account shared by several stores still shows the other stores' new payments as «without order»; that decision stays open.
- After the release the open orders are rechecked by the nightly pass; `reconciliation:recheck-open-incidents` covers only orders with open incidents.
