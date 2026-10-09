# ADR 0014: API Readiness for the Minimal Dashboard

Date: 2026-10-09

Status: implemented in Step 55.

## Decisions

- **Contract is enforced.** Every `/api/v1` and `/internal/v1` route is described in `contracts/openapi.yaml` (copied to `spec/contracts`); `OpenApiRoutesTest` fails when a route has no contract operation or the contract has an operation without a route. The user part of the document is generated from one description (DTO fields and validation rules), so schemas match what the API returns.
- **Store coverage instead of placeholders.** `StoreDTO` previously returned `coverage: null` and `active_incident_count: 0` — a dashboard would have shown "no incidents" for a store with open incidents. Now each store reports what the service can see: connector freshness, money (`provider_not_connected` / `store_not_connected` / `store_data_stale` / `reconciling`), payment attempts (`observing` / `no_recent_attempts` / …) and browser checks (`passing` / `failing` / `not_configured` / …), plus the last passed check and the real active incident count. Unknown is never rendered as healthy.
- **Optimistic concurrency.** `If-Match` (quoted version) is required on store PATCH, scenario PATCH and incident acknowledge/resolve, as the contract already stated; responses carry `ETag`. A stale version answers `409 version_conflict`, a missing header `428`. Repeating an acknowledge stays harmless.
- **Routes aligned with the catalogue** before any frontend exists: scenarios (`/stores/{id}/scenarios`, `/scenarios/{id}`), runs (`/stores/{id}/checks`, `/checks/{id}`, `/checks/{id}/cancel` — cancelling fences out the running worker and is never a site failure), `/artifacts/{id}/download` (with content type and size), `/stores/{id}/integrations`.
- **Request IDs.** Every API response carries `X-Request-ID` (a valid incoming UUID is echoed, otherwise generated).
- Deviations and the endpoints not built yet are listed in `contracts/ui-api-catalog.md` §11.
