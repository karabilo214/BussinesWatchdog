# ADR 0009: Store Connector Freshness (Stale / Partial)

Date: 2026-10-09

Status: implemented in Step 50; thresholds to be confirmed by the owner.

## Context

Spec §9: the plugin sends a heartbeat every 5 minutes; three missing heartbeats are a stale signal; a heartbeat alone does not prove order completeness. ACC-14: a stopped WP-Cron must surface as stale/partial, not as a sales outage. Until Step 50 the backend stored only `last_heartbeat_at` and ignored the plugin's self-report (backlog, oldest pending event), so a silent plugin looked healthy, and money checks and payment-attempt monitoring silently ran on incomplete store data.

## Decision

- Applies to `store_reported` integrations (store connectors) that are `active` or `degraded`. Independent providers (Stripe, PayPal) do not heartbeat and get their own sync freshness with their connectors.
- The heartbeat endpoint stores the plugin self-report in `integrations.health.heartbeat` (`backlog_count`, `oldest_pending_at`, `plugin_version`, `received_at`).
- `integrations:check-freshness` (every minute) assesses each connector:
  - `warming_up` — paired less than 15 minutes ago and no heartbeat yet;
  - `stale` — no heartbeat for 15 minutes (3 × 5 min), or none since pairing after 15 minutes;
  - `partial` — heartbeats arrive but the oldest unsent event is older than 1 hour;
  - `fresh` — otherwise.
- Transition into `stale`/`partial`: integration status `degraded`, state in `health.freshness`, one incident per connector (family `integration`, component `connector_freshness`, title `INTEGRATION_STALE` or `INTEGRATION_DELIVERY_DELAYED`, severity `warning`), notification. The text states that data is missing, not that sales stopped.
- While a store's connector is `stale`/`partial`, money reconciliation for that store reports `MONEY_UNSUPPORTED` / `unknown` / `store_data_stale` and the unmatched-payment scan produces nothing: missing store data must not become "payment without order" or "refund extra".
- Back to `fresh`: status `active`, incident auto-resolved with a recovery notification, the store's 90-day reconciliation window requeued (`connector_recovered`).
- Ingest does not depend on the integration status, so a degraded connector keeps delivering and recovers by itself.

## Not covered

The plugin's own 7-day backlog flag and 100 000-event limit, the "disabled on deactivation" report, a dashboard coverage view, and resolving the incident when a stale connector is revoked (it stays open until resolved by hand).
