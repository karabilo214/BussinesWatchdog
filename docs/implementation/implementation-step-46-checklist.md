# Implementation Step 46 Checklist: WooCommerce Order And Refund Snapshots

Stage: D2/D3 connector (WooCommerce plugin)
Spec references: `spec/Business-Watchdog-TZ.md` sections 9 (hooks, no external HTTP in checkout, local tables, revisions), 11 (envelope, snapshots), 12 (money minor units); `contracts/event.schema.json`.
Decisions: ADR 0005; version differences recorded in `docs/compatibility.md`.

## Scope

- [x] `bw_revisions` schema v2 (`parent_key`, `last_data`) via `dbDelta`; atomic revision bump only on a new snapshot hash (`INSERT … ON DUPLICATE KEY UPDATE` with `IF`).
- [x] `Outbox::enqueue` (event UUID, aggregate key, revision, payload, hash, pending state).
- [x] `MinorUnits` (string arithmetic, rejects lossy/negative/overflow) and ISO 4217 `CurrencyExponent`.
- [x] `OrderSnapshotBuilder` / `RefundSnapshotBuilder` per schema; Stripe gateway → `financial_support`.
- [x] `OrderCapture`: dirty-set during the request, single capture on `shutdown`, errors never break the request and are stored as `last_capture_error`.
- [x] `OrderHooks`: create/update/status/payment/refund hooks, Blocks checkout hooks (old and new names), HPOS and legacy deletion/trash hooks, generic `woocommerce_pre_delete_order_refund` filter, refund ids in generic order-deletion hooks.
- [x] Version-independent refund deletion detection (compare current refunds with previously sent children).
- [x] `OrderCacheBuster` before each snapshot (WC 7.9 HPOS trash leaves a stale `OrderCache`); refunds read through `wc_get_orders` (stale `get_refunds()` cache on WC 7.9 HPOS).
- [x] 12 new integration tests (20 total) and e2e validation of real events with the backend's JSON Schema + semantic validators.

## Verification

- [x] PHP 7.4 lint (`tests/lint-php74.sh`).
- [x] Integration 20/20 on floor, wc7-legacy, wc7-hpos, wc8, wc9, latest.
- [x] E2E on all six targets: pairing, heartbeat, challenge, rotation, and every captured event (`order.snapshot`, `refund.snapshot` recorded/deleted, `order.deleted`, JPY order) accepted by the backend validators.
- [x] Backend suite green (no backend changes).

## Not Done In This Step

- Delivery of outbox to `/ingest/events` (Step 47), rescan every 15 min with 48 h overlap (needed for WC 7.9 HPOS refund deletions), backfill, capabilities/deployment events.
