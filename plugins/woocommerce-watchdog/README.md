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

Not yet: order/refund snapshots, outbox delivery, rescan/backfill, capabilities/deployment events.

## Tests

```sh
tests/matrix/run.sh              # all targets, integration tests inside WordPress
tests/matrix/run.sh floor latest # selected targets
tests/matrix/e2e.sh              # pairing/heartbeat/challenge/rotation against the local backend (needs PostgreSQL from the root docker compose)
```

Local development only: `define('BW_ALLOW_INSECURE_ENDPOINT', true);` allows an `http://` service URL.
