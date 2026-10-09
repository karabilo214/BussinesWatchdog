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

Not yet: browser-check support (test product, synthetic marker), telemetry, diagnostics download, 7-day backlog degradation flag.

## Tests

```sh
tests/matrix/run.sh              # all targets, integration tests inside WordPress
tests/matrix/run.sh floor latest # selected targets
tests/matrix/e2e.sh              # pairing/heartbeat/challenge/rotation + backend validation of captured events (needs PostgreSQL from the root docker compose)
tests/lint-php74.sh              # syntax check of the plugin under PHP 7.4
```

Local development only: `define('BW_ALLOW_INSECURE_ENDPOINT', true);` allows an `http://` service URL.
