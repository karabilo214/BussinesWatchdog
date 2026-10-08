# Implementation Step 24 Checklist: Payment Snapshot Projection Foundation

Stage: D3 Woo projections bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 12, 28, 36; `spec/contracts/event.schema.json` `payment.snapshot`; `spec/database/schema.sql` `payments`.

This step adds the first payment projection for `payment.snapshot` events. It upserts payment state by external payment ID and uses `source_updated_at` freshness to avoid stale overwrites.

## Scope

- [x] Add `Payment` model.
- [x] Add `PaymentSnapshotProjector`.
- [x] Process `payment.snapshot` events inside `EventInboxProcessor`.
- [x] Upsert payment state by integration and payment external ID.
- [x] Ignore stale payment snapshots by `source_updated_at`.

## Verification

- [x] Run PHP syntax checks for changed backend files.
- [x] Run `php artisan test`; 77 tests passed with 252 assertions.

## Not Done In This Step

- Payment allocation to orders.
- Provider adapter lookups.
- Reconciliation findings.
- Full JSON Schema Draft 2020-12 validation.
