# Implementation Step 25 Checklist: Financial Transaction Projection Foundation

Stage: D3 Woo projections bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 12, 28, 36; `spec/contracts/event.schema.json` `transaction.observed`; `spec/database/schema.sql` `financial_transactions`.

This step adds append-only financial transaction projection for `transaction.observed` events. It creates one transaction per provider operation key and treats exact duplicate observations as idempotent success.

## Scope

- [x] Add `FinancialTransaction` model.
- [x] Add `FinancialTransactionProjector`.
- [x] Process `transaction.observed` events inside `EventInboxProcessor`.
- [x] Insert transaction rows by integration, kind, and external operation ID.
- [x] Link to existing payment projection when `payment_external_id` is known.
- [x] Reject same operation key with different operation content.

## Verification

- [x] Run PHP syntax checks for changed backend files.
- [ ] Run `php artisan test` inside the backend container.

## Not Done In This Step

- Payment allocations.
- Refund allocations.
- Operation correction strategy.
- Reconciliation findings.
- Full JSON Schema Draft 2020-12 validation.
