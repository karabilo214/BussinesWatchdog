# Implementation Step 23 Checklist: Refund Snapshot Projection Foundation

Stage: D3 Woo projections bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 12, 28, 36; `spec/contracts/event.schema.json` `refund.snapshot`; `spec/database/schema.sql` `refunds`.

This step adds the first refund projection for `refund.snapshot` events. It upserts refund state only when the referenced order projection already exists.

## Scope

- [x] Add financial projection migration with `refunds`, `payments`, and `financial_transactions` tables.
- [x] Add `Refund` model.
- [x] Add `RefundSnapshotProjector`.
- [x] Process `refund.snapshot` events inside `EventInboxProcessor`.
- [x] Upsert refund state by integration and refund external ID.
- [x] Ignore stale refund revisions for current refund state.
- [x] Fail processing when a refund references an unknown order.

## Verification

- [x] Run PHP syntax checks for changed backend files.
- [x] Run `php artisan test`; 77 tests passed with 252 assertions.

## Not Done In This Step

- Refund allocation to provider refund transactions.
- Reconciliation findings.
- Same-revision hash conflict handling.
- Full JSON Schema Draft 2020-12 validation.
