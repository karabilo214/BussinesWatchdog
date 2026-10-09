# Implementation Step 51 Checklist: Browser Check Backend

Stage: P0 (spec §18–20, §22; OpenAPI `/internal/v1/browser/*`, `/api/v1/stores/{store_id}/checks`; ADR 0010)

## Scope

- [x] Migration: `browser_workers`, `check_scenarios`, `check_runs`, `check_attempts`, `check_steps`; partial unique indexes (one active run per store / scenario), PostgreSQL checks.
- [x] `browser:worker-create` (token printed once, hash stored), bearer-token middleware, `/internal/v1/browser` routes with JSON errors.
- [x] `CheckScheduler` (eligibility, jitter, one active run, manual run limit), `browser:schedule` every minute.
- [x] `BrowserLeaseService`: lease (`SKIP LOCKED`), heartbeat (absolute deadline), result (fencing, idempotent replay, conflict), expiry recovery.
- [x] `CheckOutcomePolicy` (retry / confirm / inconclusive) and `CheckOutcomeEvaluator` (checkout incidents, coverage incidents, scheduled-pass recovery).
- [x] Strict sanitized-result validation.
- [x] User API: `GET/PUT /stores/{id}/check-scenario`, `POST /stores/{id}/checks`, `GET /stores/{id}/check-runs`, `GET /check-runs/{id}`.
- [x] Notifications (ru/en/de) per component; flow failure says no order was created and that it proves one path, not a full outage.
- [x] OpenAPI (both copies) updated.

## Verification

- [x] `BrowserChecksTest` (16 tests).
- [x] SQLite 298 passed + 4 skipped; PostgreSQL 18 302 passed.

## Not Done In This Step

- Node/Playwright worker (Step 52); artifacts, synthetic marker, Blocks draft cleanup (Step 53); critical severity corroboration; maintenance windows; plan quotas.
