# Implementation Step 21 Checklist: Order Deleted Projection Foundation

Stage: D3 Woo projections bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 11, 28, 35.2, 36; `spec/contracts/event.schema.json` `order.deleted`; `spec/database/schema.sql` `orders`, `order_revisions`.

This step adds projection handling for `order.deleted` events. It marks an existing order projection as deleted and stores the delete event as an immutable revision before the inbox event is marked processed.

## Scope

- [x] Add `OrderDeletedProjector`.
- [x] Process `order.deleted` events inside `EventInboxProcessor`.
- [x] Mark existing orders with `deleted_at` for newer delete revisions.
- [x] Store one immutable revision per `order_id` and delete `source_revision`.
- [x] Ignore stale delete revisions for current order state.
- [x] Fail processing when a delete event references an unknown order.

## Verification

- [x] Run PHP syntax checks for changed backend files.
- [ ] Run `php artisan test` inside the backend container.

## Not Done In This Step

- Physical order deletion.
- Refund, payment, financial transaction, and allocation cleanup or reconciliation.
- Same-revision hash conflict handling.
- Full JSON Schema Draft 2020-12 validation.
