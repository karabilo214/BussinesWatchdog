# Implementation Step 55 Checklist: API Readiness for the Dashboard

Stage: P0 (spec §36 "UI endpoints MUST be in OpenAPI before the frontend"; `contracts/ui-api-catalog.md`; ADR 0014)

## Scope

- [x] `StoreCoverage` and real `StoreDTO.coverage`, `last_successful_check_at`, `active_incident_count` (batched for lists).
- [x] Catalogue routes for scenarios, runs, cancel, artifact download, store integrations; old Step 51 routes removed (no client yet).
- [x] `If-Match`/`ETag` on scenarios and incident acknowledge/resolve; `ETag` on store GET/PATCH.
- [x] `X-Request-ID` on every API response.
- [x] OpenAPI: all user endpoints and schemas, heartbeat and connector rotation as implemented; spec copy identical.
- [x] `OpenApiRoutesTest` (routes ↔ contract, both directions; copies identical).
- [x] Catalogue §11: deviations and not-yet-implemented endpoints.

## Verification

- [x] New tests: store coverage (4), scenario API with If-Match/ETag, cancel fencing, incident If-Match, contract coverage (2).
- [x] SQLite 309 passed + 4 skipped; PostgreSQL 18 313 passed.
