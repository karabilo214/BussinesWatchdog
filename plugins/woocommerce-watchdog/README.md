# Business Watchdog for WooCommerce

WordPress plugin that connects a WooCommerce store to Business Watchdog.

Supported floor: PHP 7.4+, WordPress 5.9+, WooCommerce 6.0+ (see `docs/compatibility.md`, ADR 0005). HPOS and legacy order storage, classic and block checkout.

## Implemented (Step 45)

- Feature-detected environment (`src/Compat`): order storage, checkout mode, Action Scheduler vs WP-Cron.
- Local tables `{prefix}bw_outbox`, `{prefix}bw_revisions`, `{prefix}bw_state` via `dbDelta` with a schema version.
- Pairing with a one-time code (admin page under WooCommerce, REST `POST /wp-json/business-watchdog/v1/pair`, `wp business-watchdog pair`).
- HMAC-signed client, heartbeat every 5 minutes, credential rotation on request, suspension on revoked credentials.
- Public `GET /wp-json/business-watchdog/v1/challenge/{id}` returning only the pending store-verification challenge as plain text.
- Diagnostics (admin page, REST, `wp business-watchdog diagnostics`).

## Implemented (Step 46)

- Order and refund snapshots into `{prefix}bw_outbox` with per-aggregate revisions in `{prefix}bw_revisions` (new revision only when the snapshot hash changes).
- Hooks are only a trigger: orders are collected during the request and captured once on `shutdown` (no HTTP in checkout).
- Money as minor-unit strings with ISO 4217 exponents; amounts that do not fit the currency exponent are not sent and are reported in diagnostics.
- Deleted refunds are detected by comparing current refunds with previously sent ones (works on every version, including WC 7.9 HPOS which fires no hook); order deletion → `order.deleted`; trash → snapshot with status `trash`; Blocks drafts skipped.
- Stripe gateways (`stripe`, `stripe_*`) → `financial_support: supported`; other gateways `unsupported`; no gateway `unknown`.

## Implemented (Step 47)

- Delivery every minute and right after new events (async): batches of ≤100 events / 1 MiB, signed; `accepted`/`duplicate` removed, `quarantined`/`invalid`/`conflict` moved to dead letter, 401 credential_revoked / signature_invalid / 403 suspend the connection (backlog kept), 429 honours `Retry-After`, other failures back off 30 s → 2 min → 10 min → 1 h → 6 h (±20 %). Atomic run lock in `bw_state`.
- Capture only while a connection exists; history is covered by the backfill.
- Rescan of orders modified in the last 48 h every 15 min (also catches refund deletions on WooCommerce versions that fire no hook).
- Backfill of the last 90 days after pairing and as a daily audit: resumable page cursor, ≤1 page/s.
- `integration.capabilities_changed` (HPOS, checkout mode) and `deployment.observed` (WordPress, WooCommerce, theme, active plugin version changes; version scan on heartbeat, explicit hook after upgrades).
- WP-CLI: `wp business-watchdog deliver|rescan|backfill [--start] [--pages=N]`.

Not yet (at Step 47): browser-check support (test product, synthetic marker), telemetry, diagnostics download, 7-day backlog degradation flag.

## Safety (Step 73, ADR 0023)

- The plugin never takes part in payment, order creation, stock or store e-mails; nothing calls Business Watchdog during a customer request (checked with the service unreachable, see release checks).
- Local queue limit: 100 000 events. When reached, new order/refund snapshots are not queued (orders keep working, skipped changes are counted); below 90 000 the queue is open again and a backfill re-reads the orders of the last 90 days, so the skipped changes are recovered from the orders themselves. Deletions, capability/deployment events and payment-attempt windows are small and still queued. The heartbeat reports `outbox_full` and `skipped_events`; the service shows the store data as incomplete (`local_queue_full`). Events waiting longer than 7 days are flagged on the settings page.
- Emergency stop without the service: **Stop monitoring** on the settings page or `wp business-watchdog stop` (and `resume`) halt all scheduled work and capture; `define('BUSINESS_WATCHDOG_DISABLED', true);` in `wp-config.php` stops the plugin before any of its code is loaded.
- Uninstall removes only the plugin's own options, schedules and connection (its tables only on explicit opt-in) and needs no network; it is safe to run twice.

## Checkout observation (0.3.0, ADR 0059)

- While the service answers the heartbeat with `checkout_observation.observe = true`, the checkout and order-received pages get `assets/checkout-observer.js` (no dependencies, in the page head, kept out of WP Rocket, Autoptimize, LiteSpeed Cache and SiteGround Optimizer; marked `data-no-optimize`, `data-no-defer`, `data-cfasync="false"`).
- It counts checkout stages only (page loaded, payment form ready, order sent, error shown, stalled 60 s, order-received page) and sends them with `sendBeacon` to this site's `POST /wp-json/business-watchdog/v1/observe` with a short-lived token (page kind and expiry only). No cookies or storage, no form values, no visitor identifiers.
- The plugin counts the pages it renders itself (and a `noscript` beacon) in `wp_bw_checkout_observation`, 5-minute windows, and sends closed windows as `checkout.observation` through the outbox (`bw_checkout_observation` every minute; `wp business-watchdog checkout-observation --advance=N` runs it now). Pages rendered for a verified browser check are not counted and the script sends nothing there.

## Store paused by the plan (0.3.1, ADR 0037 point 7)

- While the store is paused by the plan the service answers every event `skipped` (`store_capacity_paused`); the plugin removes those events from the queue instead of retrying or dead-lettering them, and the heartbeat answer switches the checkout observation off.
- After the pause the heartbeat carries `backfill_requested_at` and `backfill_since`. Once per value the plugin starts a backfill (`reason: capacity_resumed`) that sends the current snapshot of every order changed since `backfill_since` (at most 90 days back) with its current revision, even when that snapshot was already sent during the pause.

## Emergency instructions (WooCommerce)

1. Admin works: WooCommerce → Business Watchdog → **Stop monitoring**, or deactivate the plugin.
2. Admin does not work but WP-CLI does: `wp business-watchdog stop` or `wp plugin deactivate business-watchdog`.
3. Add `define('BUSINESS_WATCHDOG_DISABLED', true);` to `wp-config.php` — no plugin code runs.
4. WordPress Recovery Mode can deactivate the plugin (it does not catch every background error).
5. No admin access: rename `wp-content/plugins/business-watchdog` to `business-watchdog.disabled` via hosting panel/SFTP.
6. Check the storefront and a checkout. Revoke the store's connection in Business Watchdog and contact support with diagnostics (they contain no secrets). Renaming the folder does not undo changes made by other software.

The plugin is never a dependency of the payment process; disabling it only stops monitoring.

## Release package

Current source: 0.3.4, a local candidate (Step 271). The owner has submitted the separate 0.3.3 ZIP to WordPress.org and is awaiting review; the submitted artifact is unchanged. The last recorded approved SaaS download remains 0.3.2. Exact hashes, required checks and the two approval processes are recorded in [`docs/ops/integrations.md`](../../docs/ops/integrations.md). A source commit does not publish a ZIP or update the WordPress.org submission.

```sh
bin/build-release.sh                     # dist/business-watchdog-<version>.zip: no tests or dotfiles, PHP 7.4 lint of the package
tests/matrix/release-checks.sh latest    # the package as shipped: upgrade from the previous package (BW_PREVIOUS_REF, default origin/main), rollback and upgrade again, checkout with the service unreachable, both switches, no WooCommerce, deactivation and repeated uninstall without network
```

## Tests

```sh
tests/matrix/run.sh              # all targets, integration tests inside WordPress
tests/matrix/run.sh floor latest # selected targets
tests/matrix/e2e.sh              # pairing/heartbeat/challenge/rotation + backend validation of captured events (needs PostgreSQL from the root docker compose)
tests/lint-php74.sh              # syntax check of the plugin under PHP 7.4
```

Local development only: `define('BW_ALLOW_INSECURE_ENDPOINT', true);` allows an `http://` service URL.
