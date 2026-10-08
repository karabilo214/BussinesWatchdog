# Step 09 Checklist: Store Update With Optimistic Versioning

Stage: D1 bootstrap
Spec references: `spec/contracts/ui-api-catalog.md` sections 1 and 3; `spec/Business-Watchdog-TZ.md` sections 8, 24, 26, 36.

This step adds partial store update with `If-Match`/`config_version` optimistic concurrency. It does not implement verification, pairing, deletion, or role policy enforcement beyond tenant membership.

## Endpoint

- [x] `PATCH /api/v1/stores/{store}`

## Behavior

- [x] Requires active TenantContext.
- [x] Rejects foreign tenant stores as not found.
- [x] Requires `If-Match` header.
- [x] Rejects stale version with 409 `version_conflict`.
- [x] Allows partial updates for name, timezone, locale, currency, status, browser flag, telemetry flag.
- [x] Normalizes base URL and currency.
- [x] Resets `verified_at` and disables browser checks when base URL changes.
- [x] Increments `config_version` after successful update.

## Tests

- [x] User can update store with matching version.
- [x] Stale version is rejected.
- [x] Missing `If-Match` is rejected.
- [x] Base URL change resets verification/browser flag.
- [x] Foreign store update is rejected.
- [x] Run `php artisan test`; 24 tests passed with 71 assertions.

## Not Done In This Step

- Admin/operator/viewer role policy checks.
- Store verification.
- Pending check cancellation after base URL change.
- Store deletion/revocation workflow.
