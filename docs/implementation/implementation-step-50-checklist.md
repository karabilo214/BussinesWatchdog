# Implementation Step 50 Checklist: Store Connector Freshness (ACC-14)

Stage: P0 (spec §9 heartbeat/stale, §15 coverage, ACC-14; ADR 0009)

## Scope

- [x] Heartbeat stores the plugin self-report (`backlog_count`, `oldest_pending_at`, `plugin_version`) in `integrations.health.heartbeat`.
- [x] `ConnectorFreshness`: `warming_up` / `fresh` / `stale` (3 missed heartbeats) / `partial` (delivery backlog > 1 h); transitions under a row lock; integration `active` ↔ `degraded`.
- [x] `integrations:check-freshness` scheduled every minute.
- [x] Incidents via the new shared `IncidentRecorder` (open / attach / reopen within 24 h / resolve), also used by `PaymentAttemptMonitor` now.
- [x] Store data stale → order reconciliation `unknown/store_data_stale`, unmatched-payment scan empty; recovery requeues the store window.
- [x] Notifications (ru/en/de) for `INTEGRATION_STALE` / `INTEGRATION_DELIVERY_DELAYED`, explicitly "not a sales outage", with what to check (plugin active, WP-Cron / Action Scheduler, outbound requests).

## Verification

- [x] `ConnectorFreshnessTest` (9 tests, signed heartbeat requests).
- [x] SQLite 282 passed + 4 skipped; PostgreSQL 18 286 passed.
- [x] Plugin e2e on WooCommerce 11.2 against the changed heartbeat endpoint.

## Not Done In This Step

- Plugin-side 7-day backlog flag and 100 000-event limit; "disabled" report on deactivation; dashboard coverage; revoke of a stale connector does not close its incident.
