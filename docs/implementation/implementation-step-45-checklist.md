# Implementation Step 45 Checklist: WooCommerce Plugin Foundation And Compatibility Matrix

Stage: D2/D3 connector (WooCommerce plugin), D0 compatibility spike
Spec references: `spec/Business-Watchdog-TZ.md` sections 4 (versions), 8 (pairing, challenge), 9 (plugin rules, local tables, heartbeat), 11 (event envelope), 29 (HMAC); `spec/contracts/ui-api-catalog.md` §9 (WordPress REST surfaces).
Decisions: ADR 0005 (floor PHP 7.4 / WP 5.9 / WC 6.0, capability detection, matrix); `docs/compatibility.md`.

## Scope

- [x] Plugin skeleton (`business-watchdog.php`, autoloader, PHP 7.4 syntax, floor checks, HPOS/Blocks compatibility declarations).
- [x] `Compat\Environment` (WooCommerce version/support, HPOS vs legacy, HPOS sync, Action Scheduler, checkout classic/blocks/mixed) and scheduler adapters (Action Scheduler, WP-Cron with custom intervals).
- [x] Local tables `bw_outbox`, `bw_revisions`, `bw_state` via `dbDelta` with schema version; persistent install UUID.
- [x] `SecretBox` (AES-256-GCM from WordPress salts) for the stored HMAC secret.
- [x] Pairing (`PairingClient`, connector code `woocommerce`, `home_url()` as base URL), admin page under WooCommerce, REST `/pair` `/disconnect` `/diagnostics` (manage_woocommerce), WP-CLI commands.
- [x] `SignedClient` (canonical string v1), `HeartbeatJob` every 5 min: backlog stats, suspension on 401 credential_revoked/signature_invalid or 403, pending challenge remembered, rotation via `/ingest/credentials/rotate` + confirmation request.
- [x] Public `/challenge/{id}` as plain text, only for the pending verification id.
- [x] Uninstall: clears credentials and schedules; drops tables only with `bw_purge_history_on_uninstall=yes`.
- [x] Matrix harness (`tests/matrix/run.sh`, `targets.env`, pinned images, cached WooCommerce zips and WP-CLI) and in-WordPress integration tests (8 tests).
- [x] E2E (`tests/matrix/e2e.sh`) against the Laravel backend.

## Verification

- [x] `php -l` of every plugin file under `php:7.4-cli`.
- [x] Integration tests pass on all six targets (floor WC 6.0.2/PHP 7.4 … latest WC 11.2.0/WP 7.1.3/PHP 8.3), legacy and HPOS.
- [x] E2E passes on all six targets: pairing, heartbeat, challenge served via the public REST route equals the backend challenge, rotation leaves credentials `[revoked, active]`.
- [x] Harness issue found and fixed: WC 7.9 has no `wp wc hpos` command; HPOS is enabled through `FeaturesController` + `DataSynchronizer` for that target.
- [x] Backend suite unchanged and green.

## Not Done In This Step

- Order/refund snapshots, revisions and outbox delivery (next step), rescan/backfill, capabilities and deployment events, deactivate "disabled" report, diagnostics download, settings beyond connection.
- Stripe gateway / theme matrix.
