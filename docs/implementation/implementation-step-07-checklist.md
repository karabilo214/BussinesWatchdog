# Step 07 Checklist: Tenant Switch And Session Context

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 7, 25.3, 26, 36; `spec/contracts/ui-api-catalog.md` section 2; `spec/ACCEPTANCE.md` ACC-02, ACC-03.

This step wires authenticated session tenant selection into `TenantContext`. It is still a foundation slice, not the full permission matrix.

## Implementation

- [x] Add `POST /api/v1/tenants/{tenant}/activate`.
- [x] Add session-backed TenantContext middleware.
- [x] Verify active tenant belongs to authenticated user before context is set.
- [x] Add diagnostic `GET /api/v1/tenants/context` for current foundation tests.
- [x] Document bootstrap deviations in `docs/adr/0001-bootstrap-deviations.md`.

## Tests

- [x] User can activate own tenant.
- [x] TenantContext is populated from active tenant session.
- [x] User cannot activate a foreign tenant.
- [x] Context endpoint requires authentication.
- [x] Run `php artisan test`; 14 tests passed with 40 assertions.

## Not Done In This Step

- Full tenant list endpoint.
- Store CRUD endpoints.
- Role policy matrix.
- Middleware applied to every future tenant-owned endpoint.
- Replacing diagnostic context endpoint with production DTOs.
