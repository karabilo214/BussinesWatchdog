# Compatibility

Only combinations that were actually executed are listed. "Latest" is never a compatibility statement; every row is a pinned version.

## WooCommerce plugin (`plugins/woocommerce-watchdog`)

Declared floor (owner decision 2026-10-09, ADR 0005): PHP 7.4+, WordPress 5.9+, WooCommerce 6.0+.

Verified on 2026-10-09 with `plugins/woocommerce-watchdog/tests/matrix/run.sh` (integration tests inside real WordPress) and `e2e.sh` (pairing → signed heartbeat → store-verification challenge served publicly → credential rotation against the Laravel backend):

| Target | WordPress (image) | PHP | WooCommerce | Order storage | Checkout page | Scheduler | Integration | E2E |
|---|---|---|---|---|---|---|---|---|
| floor | 5.9.3 (`wordpress:5.9.3-php7.4-apache`) | 7.4 | 6.0.2 | legacy | classic | Action Scheduler | pass | pass |
| wc7-legacy | 6.2.2 (`wordpress:6.2.2-php8.0-apache`) | 8.0 | 7.9.2 | legacy | classic | Action Scheduler | pass | pass |
| wc7-hpos | 6.2.2 (`wordpress:6.2.2-php8.0-apache`) | 8.0 | 7.9.2 | HPOS | classic | Action Scheduler | pass | pass |
| wc8 | 6.5.5 (`wordpress:6.5.5-php8.1-apache`) | 8.1 | 8.9.5 | HPOS | blocks | Action Scheduler | pass | pass |
| wc9 | 6.8.3 (`wordpress:6.8.3-php8.3-apache`) | 8.3 | 9.9.7 | HPOS | blocks | Action Scheduler | pass | pass |
| latest | 7.1.3 (`wordpress:7.1.3-php8.3-apache`) | 8.3 | 11.2.0 | HPOS | blocks | Action Scheduler | pass | pass |

Database for all targets: `mariadb:10.6.21`. WP-CLI 2.11.0.

Scope of this verification: Step 45 — activation, schema install/re-install, environment detection, scheduling, secret storage, REST permissions, public challenge endpoint, pairing, signed heartbeat, rotation. Step 46 — order/refund/deletion capture and backend validation (JSON Schema + semantic) of the events produced by each real WooCommerce version. Step 47 — outbox delivery (202/207/401/429/5xx handling, batching, locking), 48 h rescan, 90-day backfill, capabilities and deployment events: 29 integration tests per target, and an e2e in which the plugin delivers to `/api/v1/ingest/events` and the backend projects orders, refunds and deletions correctly.

### Version differences found by the matrix (Step 46)

| Behaviour | WC 6.0 legacy | WC 7.9 legacy | WC 7.9 HPOS | WC 8.9 HPOS | WC 9.9 HPOS | WC 11.2 HPOS | Plugin handling |
|---|---|---|---|---|---|---|---|
| Hook fired when a refund is deleted | `before_delete_post` | `before_delete_post` | **none** | `woocommerce_pre_delete_order_refund` (filter) | same as 8.9 | `woocommerce_delete_order_refund` + filter | hooks where available; otherwise detected by comparing current refunds with previously sent ones on the next snapshot/rescan |
| Trash updates the cached order | yes | yes | **no** (raw `UPDATE`, stale `OrderCache`) | yes | yes | yes | order cache is cleared before every snapshot |
| `get_refunds()` after deleting a refund | fresh | fresh | **stale cache** | fresh | fresh | fresh | refunds read via `wc_get_orders(type=shop_order_refund, parent=…)` |
| `checkout-draft` status exists | no | yes | yes | yes | yes | yes | drafts skipped where the status exists |
| Refund deletion via generic `woocommerce_delete_order` with the refund id | n/a | n/a | no hook | yes | yes | yes | refund ids recognised in generic order-deletion hooks |

Step 49 — payment attempt observation: 39 integration tests per target, and an e2e that places real HTTP checkouts against each WooCommerce version — Classic (`?wc-ajax=checkout` with the form nonce) and Store API (`/wc/store[/v1]/checkout`) — with a declining test gateway (`tests/matrix/mu-plugins`), an invalid e-mail and bank transfer, advances the plugin clock past the 30-minute pending timeout, delivers the windows and checks that the backend `payment_attempt_windows` totals equal the plugin's local outcomes.

### Version differences found by the matrix (Step 49)

| Behaviour | WC 6.0 legacy | WC 7.9 legacy/HPOS | WC 8.9 HPOS | WC 9.9 HPOS | WC 11.2 HPOS | Plugin handling |
|---|---|---|---|---|---|---|
| Store API namespace | `/wc/store` | `/wc/store/v1` | `/wc/store/v1` | `/wc/store/v1` | `/wc/store/v1` | route matched with an optional version segment |
| Store API checkout with a legacy (non-blocks) gateway | **200, empty `payment_status`, payment not processed, order stays pending** | processed | processed | processed | processed | attempt stays open and becomes `pending_stuck` after 30 min — the customer could not pay |
| Store API error when the gateway declines | — | non-payment error code → `checkout_error` | same as 7.9 | `woocommerce_rest_checkout_process_payment_error` → `payment_error` | same as 9.9 | both classes count as failures |
| Classic decline (error notice after the order exists) | `gateway_error` | `gateway_error` | `gateway_error` | `gateway_error` | `gateway_error` | — |

Delivery fix found by the same e2e: events were re-encoded through PHP arrays before sending, so an empty JSON object (`failure_classes: {}`) became a list and the window was quarantined by the backend schema; events are now decoded as objects.

## Browser worker (`apps/browser-worker`, Step 52)

Verified on 2026-10-09 with `plugins/woocommerce-watchdog/tests/matrix/browser-e2e.sh`: the worker image (`mcr.microsoft.com/playwright:v1.55.0-noble`, Chromium 140, sandbox on, read-only root, all capabilities dropped) leases runs from the real backend and walks product → add to cart → cart → checkout → payment form against each WooCommerce target. After all runs the store has the same number of orders and payment attempts as before (no order was placed).

| Target | Classic checkout | Blocks checkout | No payment methods |
|---|---|---|---|
| floor (WC 6.0.2) | passed | no block checkout page created by this version | `site_failure` at `payment_form` (`no_payment_methods`) |
| wc7-legacy / wc7-hpos (WC 7.9.2) | passed | no block checkout page created | `site_failure` at `payment_form` (`payment_form_missing`) |
| wc8 (WC 8.9.5) | passed | passed | `site_failure` at `payment_form` |
| wc9 (WC 9.9.7) | passed | passed | `site_failure` at `payment_form` |
| latest (WC 11.2.0) | passed | passed | `site_failure` at `payment_form` |

Found by the matrix: WooCommerce's add-to-cart form is `multipart/form-data`, so the network policy parses multipart bodies; until it did, the policy blocked add-to-cart as an unknown mutation (safe, but it would have produced a false "cart empty" failure — failures right after the worker blocked an unknown mutation are now reported as unsupported, never as a store failure).

Step 53 (plugin paired, synthetic marker on): the plugin verified the marker for every run; the Blocks check created one checkout draft on WC 8.9.5 and 9.9.7 (marked, then removed by the cleanup) and none on 11.2.0; a customer draft was kept on every target; no orders and no payment attempts were recorded.

Not verified: variable products, required shipping before the payment step, gateways that need an address before rendering, third-party gateway iframes (Stripe, PayPal), custom themes.

Not verified: WooCommerce Stripe gateway versions, themes, multisite, PHP 7.4 with WooCommerce ≥ 7 (official WordPress images for newer WP versions no longer ship PHP 7.4), WP-Cron-only path (Action Scheduler is bundled in every WooCommerce ≥ 6.0 tested).

## Backend

- PHP 8.4 (Homebrew `php@8.4` locally), Laravel 13, PostgreSQL 18 (local Docker), SQLite in-memory for the fast suite.
