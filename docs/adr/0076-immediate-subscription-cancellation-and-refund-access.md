# ADR 0076: Immediate Paddle cancellation and refund access

Status: accepted, 2026-10-21 (owner decision).

## Decision

The customer and platform staff cancel an active Paddle subscription with `effective_from=immediately`. Paddle stops renewal and marks the subscription canceled at once. We retain the already verified `paid_until` on our contract and grant the purchased plan only until that time. No refund is initiated by cancellation. A canceled Paddle subscription cannot be resumed; a customer can make a new subscription.

An approved Paddle refund of any amount, whether requested in our platform or made in the Paddle dashboard, revokes plan access immediately and cancels the linked live Paddle subscription immediately. The refund request or Paddle's `pending_approval` status does not revoke access. A rejected refund has no access effect. The revocation timestamp is stored separately from `paid_until` so the financial coverage record remains intact and later subscription sync cannot restore the refunded access. A refund of an older contract must never cancel a newer subscription.

Platform staff have a full refund action on a completed, wholly refundable payment. The existing refund approval with owner MFA is retained. The payment amount is locked to the full refundable amount in that action. The cancellation and access effect occur when Paddle confirms the refund. Any approved partial refund has the same cancellation and access effect.

## Implementation and risks

Paddle's immediate cancellation response has `status=canceled` and `current_billing_period=null`, so our entitlement decision uses the previously verified `paid_until`, not the now-null provider period. `access_revoked_at` on `billing_subscription_contracts` caps projected access on refund. The webhook inbox retries a failed provider cancellation; event pull also retrieves missed adjustments.

Previously scheduled cancellation remains accepted by the backend through `at_period_end=true` for compatibility and can be resumed before it takes effect. The site buttons choose immediate cancellation. A live refund approval and cancellation cycle has not been performed as part of this change.

Traceability: PAY-13, EXT-16, EXT-19, OWN-08, BCK-10, ADR 0037, ADR 0044.
