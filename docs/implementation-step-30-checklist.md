# Implementation Step 30 Checklist: Payment And Refund Allocation Foundation

Stage: D4 reconciliation foundation
Spec references: `spec/Business-Watchdog-TZ.md` sections 12, 13, 25, 36.
Acceptance references: `ACC-29`, `ACC-30`, `ACC-35`, `ACC-36`.

This step adds the allocation persistence and service layer required before reconciliation findings can safely compare orders, captures, and refunds.

## Scope

- [x] Add `payment_allocations` bootstrap table.
- [x] Add `refund_allocations` bootstrap table.
- [x] Add `PaymentAllocation` and `RefundAllocation` models.
- [x] Add `PaymentAllocationService`.
- [x] Validate tenant/store scope before allocation.
- [x] Validate capture/refund transaction kind and succeeded status.
- [x] Validate currency consistency.
- [x] Enforce active capture allocation sum under row locks.
- [x] Enforce active refund allocation sum under row locks.
- [x] Add focused tests for capture/refund allocation success and rejection paths.

## Verification

- [x] Run PHP syntax checks for changed allocation files.
- [x] Run focused payment allocation service tests.
- [x] Run full `php artisan test`; 97 tests passed with 323 assertions.

## Not Done In This Step

- Public/manual allocation API.
- Allocation revoke/unlink service.
- Reconciliation runs and findings.
- Automatic matcher strategies beyond service primitives.
- Nightly allocation audit.
