# ADR 0082: Stripe multicapture — a capture that grows

Date: 2026-10-27

Status: accepted (implemented in Step 278; the owner accepted the financial assumption below on 2026-10-27)

## Context

With Stripe multicapture, one authorisation is captured in several parts, for example for split shipments. There is still one Charge, and its `amount_captured` grows with each part. The adapter emits one capture operation per charge (id = charge id, amount = `amount_captured`, ADR 0020). ADR 0020 accepts only status changes of a provider operation, so each further part was rejected as `transaction_operation_conflict`: the event stayed unprocessed, and the order looked under-captured forever.

## Decisions

1. **A capture operation is the cumulative amount captured on the charge.** For an independent-provider operation of kind `capture`, the projector accepts a larger amount when everything else matches: succeeded before and after, same currency, exponent and payment. Each step is kept in `metadata.amount_history` (from, to, observed_at, event id) and in `amount_observed_at`. This narrows the rule of ADR 0020: amounts of other kinds, and of store-reported operations, stay immutable.
2. **Never shrinks.** A smaller amount from an observation older than the last accepted one is a late or reordered delivery and is ignored. A smaller amount from a newer observation stays a conflict (the event is not processed), because Stripe never lowers `amount_captured`.
3. **Automatic links follow, manual ones do not.** If the capture has exactly one active allocation, made by a matcher (`exact_reference`, `verified_metadata`, `unique_amount`), and it covered the whole previous amount, it grows to the new amount (`evidence.capture_growth`). A manual allocation, or a capture split between several orders, keeps its amounts; the new remainder is unallocated and shows up as such. Every affected order, and the store's unmatched payments, are marked for a new reconciliation.
4. **Money semantics are unchanged otherwise.** Captures are still compared with the order total (`capture_vs_order`), and refunds do not reduce a capture. The PaymentIntent stays `authorized` until Stripe marks it `succeeded`. The released remainder of a final partial capture is never money received.

## Assumption for the owner

The order is considered fully paid only when the cumulative captured amount reaches the order total. Until the last part is captured, the order shows an under-capture (`MONEY_CAPTURE_AMOUNT`), the same as any partial capture today. If merchants who capture per shipment find this noisy, a later step can treat "authorised, captured in parts, final capture pending" as its own state.

## Consequences

- An event that would earlier have failed now updates the capture. On production no event had failed this way before the change (0 `transaction_operation_conflict` messages on 2026-10-27).
- Other providers are not affected. PayPal gives every capture its own id, so its operations never grow.
