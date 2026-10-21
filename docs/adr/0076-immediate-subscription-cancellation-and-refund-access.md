# ADR 0076: Cancellation, 14-day withdrawal and Paddle refunds

Status: accepted, 2026-10-21 (owner decision).

## Decision

The customer's cancellation in the first 14 days from Paddle `started_at` requests a full refund of all completed payments on that subscription. While Paddle reports `pending_approval`, the subscription and paid access remain active. Only after all payments are refunded with `approved` adjustments do we revoke access and cancel the linked Paddle subscription immediately. Rejected refunds keep access; the customer may retry the original withdrawal even when Paddle's review passes the 14-day boundary. Subsequent customer cancellation is scheduled with `effective_from=next_billing_period`; access ends with the paid period. Platform staff cancellation is a separate immediate no-refund action and retains verified `paid_until`.

An approved **full** Paddle refund, whether requested in our platform or made in the Paddle dashboard, revokes plan access immediately and cancels the linked live Paddle subscription immediately. A partial refund leaves the subscription and plan unchanged. The refund request or Paddle's `pending_approval` status does not revoke access. A rejected refund has no access effect. The revocation timestamp is stored separately from `paid_until` so the financial coverage record remains intact and later subscription sync cannot restore the refunded access. A refund of an older contract must never cancel a newer subscription.

Platform staff have separate full and partial refund actions. The partial action opens an amount field and leaves the plan unchanged; the full action locks the payment amount. A platform owner enters an authenticator code in the dialog to request and approve the refund in one step; billing staff can only request it for separate owner approval. Paddle may then keep the adjustment `pending_approval`; only its `approved` state counts as a completed refund. For full refunds, cancellation and access revocation occur when Paddle confirms the refund.

## Implementation and risks

Paddle's immediate cancellation response has `status=canceled` and `current_billing_period=null`, so our entitlement decision uses the previously verified `paid_until`, not the now-null provider period. `access_revoked_at` on `billing_subscription_contracts` caps projected access on refund. The webhook inbox retries a failed provider cancellation; event pull also retrieves missed adjustments.

The customer API chooses the policy by Paddle `started_at`; callers cannot override it with `at_period_end`. Scheduled cancellation can be resumed before it takes effect. A live refund approval and cancellation cycle has not been performed as part of this change.

Traceability: PAY-13, EXT-16, EXT-19, OWN-08, BCK-10, ADR 0037, ADR 0044.
