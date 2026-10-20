# Step 08 Checklist: Store CRUD Foundation

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 8, 24, 25.3, 26, 36; `spec/contracts/ui-api-catalog.md` section 3; `spec/ACCEPTANCE.md` ACC-02, ACC-03.

This step adds minimal store CRUD foundation scoped by active tenant. It does not implement domain verification, pairing, integrations, browser checks, or store deletion.

## Endpoints

- [x] `POST /api/v1/stores`
- [x] `GET /api/v1/stores`
- [x] `GET /api/v1/stores/{store}`

## Behavior

- [x] Store creation requires active TenantContext.
- [x] Store list is scoped to active tenant.
- [x] Store detail rejects foreign tenant records as not found.
- [x] Create validation requires HTTPS URL.
- [x] Create validation rejects URL credentials and fragments.
- [x] Create validation checks timezone, locale, and currency.
- [x] No network request is made for URL validation.

## Tests

- [x] User can create store in active tenant.
- [x] Store creation requires active tenant context.
- [x] User lists only active tenant stores.
- [x] User cannot read foreign store.
- [x] Non-HTTPS store URL is rejected.
- [x] Run `php artisan test`; 19 tests passed with 55 assertions.

## Not Done In This Step

- PATCH store.
- Store verification.
- Pairing codes.
- Integrations.
- Browser scenarios.
- Authorization role policy beyond authenticated tenant membership.
