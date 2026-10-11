# ADR 0023: Launch market, REST API-first WooCommerce and plugin safety

Status: accepted (owner, 2026-10-11) for the decisions; Step 73 implementation recorded below

## Context

`spec/Business-Watchdog-Platforms-and-Safe-Integrations-RU.md` (research, 2026-10-11) proposes the platform order, an API-first WooCommerce connection and safety requirements for local connectors (§8) with release criteria (§9). The TZ is not changed by it.

## Decisions (owner, 2026-10-11)

1. **Launch geography: Germany / DACH.** Platform order: WooCommerce → Shopware 6 (App with limited permissions) → Shopify → others by demand; CRM later (HubSpot, Pipedrive).
2. **WooCommerce REST API-first.** The base connection becomes a WooCommerce REST API key with Read permission; the plugin stays as an optional addition for what the API cannot give (checkout attempts, browser-check marker, fast change hints). Design is a separate step.
3. **Start with plugin safety** (this step), then the REST API connector.

## Step 73: plugin safety (implemented)

- **Local queue limit** (TZ §9 “100 000”, research B5 / “big queue”): 100 000 rows in `bw_outbox` (pending and dead letter). Checked by the delivery and heartbeat jobs, not on the customer request. When full, order/refund snapshots are skipped **before** a revision is stored, so the next backfill sees them as changed; skipped changes are counted. Below 90 000 the queue reopens and a backfill (`queue_recovery`, 90 days) recovers them from the orders. Order deletions, capability/deployment events and payment-attempt windows still pass (rare and small; a deletion could not be recovered later). Assumption for the owner: changes older than the 90-day backfill window that were skipped during a long overflow are not recovered.
- **Heartbeat** reports `outbox_full` and `skipped_events`; the service treats a full queue as `partial` / `local_queue_full` (money checks unknown, incident “plugin is sending data late”) until the plugin reports it drained.
- **Emergency switches** (D8): `BUSINESS_WATCHDOG_DISABLED` constant checked in the main file before the autoloader (no plugin code runs, admin notice); soft stop from the settings page or `wp business-watchdog stop|resume` clears all schedules and registers no hooks except the settings page and these CLI commands. Neither depends on the service or a licence.
- **7-day backlog** flagged on the settings page and in diagnostics (the service already shows partial data after 1 h).
- **Uninstall** also removes the switch and queue options; still no network and safe to repeat.
- **Release package** (C6, E1): `bin/build-release.sh` builds the ZIP without tests/dotfiles, checks contents and lints the package on PHP 7.4; `tests/matrix/release-checks.sh` installs the package as shipped (no source mount) and checks: upgrade from the previous package keeps queue, connection and schema; rollback and upgrade again; classic and block checkout succeed in ~2 s with the service unreachable (DNS failure and a silent address); delivery backs off; both switches; no WooCommerce; deactivation, repeated uninstall and WordPress uninstall without network; no PHP fatal errors. PASS on floor (PHP 7.4, WP 5.9, WC 6.0.2) and latest (PHP 8.3, WP 7.1, WC 11.2). Plugin version 0.2.0.

## Not done yet (research §8–9)

- Diagnostics export with explicit consent (F3); a diagnostics download does not exist yet.
- Checkout performance budget (p95/p99, memory, queries before/after) — to measure before release.
- Combinations with caching/security plugins; multisite; PHP below 7.4 is refused by the WordPress header (`Requires PHP`) and the bootstrap check, not exercised on a real PHP 7.3.
- Subscription end only stops monitoring — billing does not exist yet.
- Negative rights check in a test store applies to the REST API connector (next step).
