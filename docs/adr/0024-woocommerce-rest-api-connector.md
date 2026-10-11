# ADR 0024: WooCommerce REST API connector (read key)

Status: accepted (owner, 2026-10-11); decisions on the assumptions below

## Context

ADR 0023 makes a WooCommerce REST API key with Read permission the base connection; the plugin becomes optional. The spike checks what the API gives before the connector is built.

## Spike (2026-10-11, `plugins/woocommerce-watchdog/tests/matrix/rest-api-spike.sh`)

Matrix stores without the Watchdog plugin, a Read key created as in WooCommerce → Settings → Advanced → REST API; WooCommerce 6.0.2 (legacy), 7.9.2 (legacy and HPOS), 8.9.5, 9.9.7 and 11.2.0 (HPOS).

- **URL form.** WooCommerce up to 9.9 only authenticates keys on URLs containing `/wp-json/wc/` (pretty permalinks); `?rest_route=/wc/v3/...` is rejected as unauthenticated (401 `woocommerce_rest_cannot_view`). WooCommerce 11.2 accepts both. The connector uses `/wp-json/wc/v3/`; stores with plain permalinks on older WooCommerce cannot use a key.
- **HTTPS only.** Basic auth over plain HTTP is refused by WooCommerce. The connector requires an `https://` store URL.
- **Orders**: `GET /wc/v3/orders` with `modified_after`, `dates_are_gmt`, `orderby=modified&order=asc`, `per_page=100`, `X-WP-Total`/`X-WP-TotalPages`/`Link`. `_fields` limits the response: 100 orders ≈ 26–32 KB instead of 150–185 KB, and without billing/shipping/customer data. Fields used: id, number, status, currency, total, payment_method, transaction_id, order_key, date_created_gmt, date_modified_gmt, date_paid_gmt, refunds (id, total), meta_data.
- **Meta.** Gateway meta with a leading underscore is returned (`_stripe_intent_id`, `_ppcp_paypal_order_id`, `_ppcp_paypal_payment_mode`, `_bw_payment_mode`); refunds carry `_stripe_refund_id`. `meta_data` cannot be narrowed by key, so other meta (possibly personal, e.g. a payer e-mail written by a gateway) is received and immediately dropped — never stored or logged.
- **Refunds**: `GET /wc/v3/orders/{id}/refunds` gives id, amount, date_created_gmt, refunded_payment, meta. A store-wide `GET /wc/v3/refunds` exists from WooCommerce 9.x. A new refund updates the order's `date_modified` on 7.9+ (legacy and HPOS) but **not on 6.0** — there only a re-read of recent orders finds late refunds.
- **Trash and deletion**: trashed orders are listed only with `status=trash` (not in `any`); a deleted order is 404 and disappears from `include=` lists.
- **Amounts** come as decimal strings rounded to `dp` (default: the store's price decimals; `1500.00` for JPY). The connector asks `dp=6` and accepts only values exactly representable in the currency exponent.
- **Write refused**: with the Read key, PUT/POST/DELETE on orders, refunds, notes, webhooks, settings and products all return 401 `woocommerce_rest_authentication_error`; the order is unchanged.
- **Read is broad**: the Read key also reads customers, settings, payment gateways, system status and the webhook list. The connection page must say so; the connector itself only calls the order endpoints.
- **Telling a Read key from a Read/Write key without writing anything**: WooCommerce checks the key's permission for the HTTP method before routing. `POST /wc/v3/system_status` — a route with no write handler at all — returns 401 `woocommerce_rest_authentication_error` for a Read key and 404 `rest_no_route` for a Read/Write key, on 6.0 and 11.2. Nothing can change either way.

## Decisions (Step 74)

- **Provider `woocommerce_api`**, source authority `store_reported` (store data, never proof of payment), credentials `woocommerce_api` (keyring-encrypted consumer key and secret). Connecting: `POST /stores/{id}/integrations/woocommerce-api` with consumer key and secret; the store's confirmed `https://` URL is used, no other URL is accepted.
- **Connect checks**: an authenticated read of `/wc/v3/orders` (1 order) must succeed; the permission probe above must answer 401 `woocommerce_rest_authentication_error`. A key with write permission is **refused** (`woocommerce_key_not_read_only`) — the owner creates a Read key. The probe is the only non-GET request the connector ever sends, only at connect time, and to a route without a write handler.
- **Read-only by construction**: the client allows only GET on `/wp-json/wc/v3/orders`, `/orders/{id}/refunds` and `/wc/v3` (route index), plus the connect probe; SSRF-safe (public addresses only, pinned DNS, no redirects, HTTPS), 20 s timeout, at most one sync per store at a time, a page budget per run.
- **Sync**: delta every 5 minutes from the watermark (`date_modified_gmt` of the last seen order) minus 10 minutes, `status=any` plus `status=trash`; recent re-read hourly of orders created in the last 48 h (finds late refunds on WooCommerce that does not bump the order); 90-day audit daily, which also finds deleted orders (known orders missing from the listing are confirmed with `include=` before `order.deleted` is sent). Refunds of an order are read when its refund summary changed. Events are the same as the plugin's (`order.snapshot`, `refund.snapshot`, `order.deleted`), with per-object revisions and content-hash change detection, so the existing projections, matching and reconciliation work unchanged.
- **Mode** (test/live): from `_bw_payment_mode` (written by the plugin, if it was ever installed) or `_ppcp_paypal_payment_mode`; otherwise none is sent and the backend keeps its default (live). Stripe test-mode orders of an API-only store are therefore treated as live — assumption 6.
- **Freshness**: no heartbeat; the connector is `stale` when no successful sync for 30 minutes and the money checks become `unknown`. A rejected key (401) degrades the integration and stops polling (research D7); the owner reconnects.

## Assumptions for the owner

1. **One order source per store.** A store has either the plugin or the REST API connection as its order source, not both: connecting one while the other is active is refused. Making the plugin an add-on next to the API (checkout attempts, browser checks) is a later step.
2. **A Read/Write key is refused**, not accepted with a warning.
3. **Store verification is unchanged**: a working key does not by itself confirm the domain; DNS or plugin verification still applies.
4. **Deleted orders** are detected by the daily audit (up to a day late), and only within 90 days.
5. **WooCommerce 6.0–7.8**: late refunds are seen within an hour (recent re-read), not within 5 minutes.
6. **Stripe test/live without the plugin**: the API gives no reliable mode for Stripe orders (only the current gateway setting, which can change), so they are labelled live; a test store paired with a test-mode Stripe account needs the plugin or shows mode mismatches.

## Owner decisions (2026-10-11)

1. One order source per store — **accepted**.
2. A Read/Write key is refused — **accepted**.
3. Store verification — asking customers to edit DNS will put many off. **Accepted (Step 78):** a Read key that works on the store's own HTTPS origin proves admin access to that store (only store managers can create keys), so a successful connection confirms the store with the new verification method `api_key` (recorded only by connecting, cannot be started by hand; nothing changes for a store already verified for its URL). DNS and plugin verification stay as alternatives.
4. Deleted orders found by the daily audit within 90 days — **accepted**.
5. Late refunds within an hour on WooCommerce 6.0–7.8 — outside our control; **the connection screen warns the customer** (dashboard step).
6. No test/live mode without the plugin — **show “not supported in this mode, the plugin is needed”** instead of a mode. Implemented (Step 77): the order projection stores `mode_reported = false` when the store sent no mode, the order API returns `mode_reported`; when every provider that could hold the order's money runs in test mode, the order's money check is `unknown` / `payment_mode_needs_plugin` instead of a false missing capture, and test-mode payments without an order in an API-connected store are `unknown` with the same reason instead of awaiting review. With a live provider such orders are checked as usual.

Dashboard (Step 79): the store page's "Order source" block has a "REST API key" tab (recommended, opened first unless the plugin is paired) and a "Plugin" tab; the key tab explains how to create a Read key, what is read and what cannot be done, the requirements (HTTPS, permalinks not "Plain"), that connecting confirms the store, and both warnings (late refunds before WooCommerce 7.9; test/live mode needs the plugin). An order whose mode was not reported shows "test/live mode: plugin needed".
