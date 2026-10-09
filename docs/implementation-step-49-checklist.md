# Implementation Step 49 Checklist: Payment Attempt Monitoring, Layer 1

Stage: P0 (spec §17.1 layer 1, ADR 0007)
Spec references: `spec/Business-Watchdog-TZ.md` §17.1 (server-side attempt/outcome aggregates per payment method, 5-minute windows, N=3 consecutive failures, 30-minute pending window, no PII), §11 (event contract), §22 (incident engine), §23 (notifications).

## Scope

Contract:

- [x] `checkout.payment_attempts` / aggregate `checkout` in `contracts/event.schema.json`, `spec/contracts/event.schema.json` and the backend copy; per-method counters, `trailing_failures`, `failure_classes` (object, class-name pattern, no texts).

Plugin (`plugins/woocommerce-watchdog`, schema v3):

- [x] `bw_payment_attempts` table; `AttemptLog` (start / retry / resolve / late success / reject / prune 7 days).
- [x] `AttemptHooks`: Classic (`woocommerce_checkout_process`, error notices, `woocommerce_checkout_order_processed`), Blocks / Store API (`*_checkout_order_processed`, `rest_request_after_callbacks` for `/wc/store[/vN]/checkout`), outcomes from `woocommerce_payment_complete` and status changes; gated on an active connection; never throws into checkout.
- [x] `PaymentAttemptsJob` every 60 s: re-checks open attempts against the order, marks `pending_stuck` after 30 min, reports each closed 5-minute window once (outbox write and "reported" flag in one DB transaction).
- [x] WP-CLI `business-watchdog payment-attempts [--advance=<seconds>]`.
- [x] Fix: delivery re-encoded events through PHP arrays, turning empty JSON objects into lists (found by the e2e; covered by a delivery test).

Backend:

- [x] `payment_attempt_windows` (unique per store/method/window, PostgreSQL checks and scoped event FK); semantic validation; `PaymentAttemptsProjector` (idempotent by data hash, conflict on same revision with different data, checked before any write).
- [x] `PaymentAttemptMonitor`: streak across windows, `checkout_payment` incidents (open / attach / auto-resolve on success / reopen within 24 h), signals with evidence, notifications.
- [x] Notification content and rendering per incident family; ru/en/de texts that state declines cannot always be told apart from breakage.

## Verification

- [x] Backend: SQLite 273 passed + 4 skipped, PostgreSQL 18 277 passed.
- [x] Plugin integration tests on all six targets (see `docs/compatibility.md`).
- [x] E2E with real HTTP checkouts (Classic `wc-ajax=checkout` and Store API) against each WooCommerce version: a declining test gateway, an invalid e-mail and a bank transfer per channel; delivered windows projected by the backend.

## Not Done In This Step

- Layer 2 (checkout script) and the server-side checkout render counter.
- "Pay for order" page attempts; statistical success-rate rule; dashboard coverage; entitlement gating (`payment_attempts_server`, no entitlement system yet).
