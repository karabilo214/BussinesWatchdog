# Implementation Step 47 Checklist: Plugin Delivery, Rescan, Backfill And Environment Events

Stage: D2/D3 connector (WooCommerce plugin)
Spec references: `spec/Business-Watchdog-TZ.md` section 9 (retry schedule, 401/403/422/429 handling, delete only accepted/duplicate, rescan 15 min / 48 h overlap, daily 90-day audit, backfill pages of 100 at ≤1 page/s, resumable), section 11 (batch ≤100 / 1 MiB, 202/207 semantics), deployment/capabilities events.

## Scope

- [x] `Outbox::due/deleteByIds/deadLetter/retryLater` with jittered backoff; byte-aware batching.
- [x] `DeliveryJob` with atomic `bw_state` lock (WordPress `add_option` is not atomic), per-event result handling, suspension on revoked credentials, Retry-After, 413 split, 422 dead letter.
- [x] Async delivery after capture, recurring delivery every 60 s.
- [x] Capture gated on an existing connection (cached per request, reset on pair/forget).
- [x] `RescanJob` (48 h overlap, 15 min), `BackfillJob` (90 days, resumable page cursor, daily audit, cancel).
- [x] `EnvironmentEvents`: `integration.capabilities_changed` (mixed checkout → `custom`), `deployment.observed` on version change (version scan / explicit hook).
- [x] Diagnostics show last delivery/rescan/backfill; WP-CLI commands; uninstall clears all schedules.
- [x] 9 new integration tests (29 total) using a fake backend via `pre_http_request`.
- [x] E2E: real delivery to the backend, backend outbox dispatch, projections checked (EUR order supported, refund 5000 deleted, JPY order deleted/unsupported, no quarantined events).

## Verification

- [x] PHP 7.4 lint.
- [x] Integration 29/29 on all six targets.
- [x] E2E on all six targets (the e2e backend runs with a raised pairing limit; the production default 20/hour/IP correctly blocked repeated local runs).
- [x] Backend suite green (no backend code changes).

## Not Done In This Step

- 7-day backlog degraded flag and 100 000-event operational limit, deactivate "disabled" report, diagnostics download, missing-order detection during the daily audit, browser-check/telemetry features.
