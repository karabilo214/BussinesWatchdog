# Implementation Step 31 Checklist: Allocation Revoke And Unlink Service

Stage: D4 reconciliation foundation
Spec references: `spec/Business-Watchdog-TZ.md` sections 12, 13, 25, 36.
Spec references: `spec/contracts/ui-api-catalog.md` section 5 (`/payment-allocations/{id}/revoke`, `/refund-allocations/{id}/revoke`).
Acceptance references: `ACC-29`, `ACC-30`, `ACC-36`.

This step adds the revoke/unlink half of the allocation service so that manual links created in Step 30 can be safely reviewed and withdrawn before reconciliation runs/findings start reading allocation state.

## Scope

- [x] Add `PaymentAllocationService::revokeCaptureAllocation()`.
- [x] Add `PaymentAllocationService::revokeRefundAllocation()`.
- [x] Require a non-empty reason for every revoke.
- [x] Reject revoking an already-revoked allocation (idempotency-safe, not idempotent silent success).
- [x] Reject revoking a capture allocation that still has active (non-revoked) refund allocations attached; the refund allocation must be unlinked first.
- [x] Lock the allocation row (and, for capture revoke, the active refund allocations) under transaction before mutating.
- [x] Write an `audit_log` row per revoke with actor, reason, and `revoked_at`.
- [x] Add `AuditLog::ACTION_PAYMENT_ALLOCATION_REVOKED` / `ACTION_REFUND_ALLOCATION_REVOKED` and matching entity-type constants.
- [x] Add `PaymentAllocation::refundAllocations()` inverse relation.
- [x] Add focused tests: revoke success + audit row, double-revoke rejected, missing reason rejected, revoke blocked while an active refund allocation exists, revoke allowed after that refund allocation is itself revoked, revoked capacity becomes available again for a new allocation.

## Verification

- [x] `php -l` on all changed files (PHP 8.4).
- [x] `php vendor/bin/pint` on changed files (no unrelated files touched).
- [x] Run focused payment allocation service tests.
- [x] Run full `php artisan test`; 104 tests passed with 337 assertions (PHP 8.4).

## Not Done In This Step

- Public/manual allocation API (`POST /payment-allocations`, `/refund-allocations`, and their `/revoke` HTTP endpoints) — service only, no routes/controllers yet.
- Reconciliation runs and findings.
- Automatic matcher strategies beyond service primitives.
- Nightly allocation audit.
- Emitting a `dirty_order`-style outbox message on revoke: there is no reconciliation consumer yet to read it, so it is deferred to the reconciliation runs step instead of adding an unconsumed topic now.
