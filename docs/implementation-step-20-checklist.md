# Implementation Step 20 Checklist: Order Snapshot Projection Foundation

Stage: D3 Woo projections bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 11, 28, 35.2, 36; `spec/database/schema.sql` `orders`, `order_revisions`.

This step adds the first commerce projection for `order.snapshot` events. It creates or updates the current `orders` row and records immutable `order_revisions` before the inbox event is marked processed.

## Scope

- [x] Add `orders` and `order_revisions` migrations.
- [x] Add `Order` and `OrderRevision` models.
- [x] Add `OrderSnapshotProjector`.
- [x] Project new `order.snapshot` events into current order state.
- [x] Store one immutable revision per `order_id` and `source_revision`.
- [x] Ignore stale revisions for current order state.
- [x] Keep the projection inside the inbox processing transaction.

## Verification

- [x] Run PHP syntax checks for changed backend files.
- [ ] Run `php artisan test` inside the backend container.

## Not Done In This Step

- `order.deleted` handling.
- Refund, payment, financial transaction, and allocation projections.
- Revision hash conflict handling for same source revision with different content.
- Full JSON Schema Draft 2020-12 validation.
- PostgreSQL-only partial indexes for nullable transaction references.
