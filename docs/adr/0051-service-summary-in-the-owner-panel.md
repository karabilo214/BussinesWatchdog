# ADR 0051: Service summary («Сервис») in the owner panel

Status: accepted for now (owner's backlog item 2026-10-13: the «Сервис» section is not ready). Definitions follow spec/panels/OWNER-PANEL.md §7 (service overview); details chosen by the implementer while the owner was away; reviewed by the owner on 2026-10-16.

## Decisions

1. **Read only, aggregates only**: `GET /platform-api/v1/health` returns counts, ages and states; no tenant ids, names or payloads. No buttons that change anything; no shell/SQL from the UI.
2. **Who**: new capability `service.read` for platform owner and platform admin (the spec's operations staff); support and billing do not see it.
3. **What**: components (database, Redis, cache, encryption keys — the readiness checks, now catching any error so a missing extension shows as a problem instead of a 500); queues — store event inbox, domain outbox, Paddle events (with the last received time), reconciliation backlog, notification deliveries, framework jobs — each with waiting count, age of the oldest waiting entry and problems (dead letters/quarantine; failed notifications and jobs in the last 24 h); browser checks queued/running with workers seen in the last 5 minutes (queued checks and no worker = problem); active connectors without a successful sync for 24 h; release (`WATCHDOG_RELEASE`), environment, PHP and Laravel versions.
4. **Thresholds** (constants in `ServiceHealth`, shown in the panel): oldest waiting entry older than 15 minutes — attention, older than 60 minutes — problem; any dead entry — attention; connector sync older than 24 hours — attention. The overall state is the worst part.
5. **Not in this step**: maintenance windows and worker limits (they belong to «Настройки» with versioned config), entitlement drift, deployment history, alerts.

## Owner decision (2026-10-16)

- Thresholds (15/60 minutes, 24 hours) are confirmed for now.
- When the overall state becomes "problem", an e-mail goes to the responsible person whose address is set in config (env).
- Repair actions from the panel are not needed now; they may be added later.
