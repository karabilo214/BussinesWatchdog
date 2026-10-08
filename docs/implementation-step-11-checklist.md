# Step 11 Checklist: Store Verification Challenge Foundation

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 24, 25.3, 26, 33, 36; `contracts/ui-api-catalog.md` section 3; `spec/database/schema.sql` `store_verifications`.

This step adds the first domain verification challenge API. It creates and reads verification state, but it does not perform DNS lookup, connector/plugin challenge fetch, automatic store activation, or pairing yet.

## Implementation

- [x] Add `store_verifications` migration aligned with the reference schema.
- [x] Add `StoreVerification` model.
- [x] Add `POST /api/v1/stores/{store}/verify`.
- [x] Add `GET /api/v1/stores/{store}/verification`.
- [x] Scope both endpoints by active `TenantContext`.
- [x] Protect challenge creation with owner/admin store manage role.
- [x] Allow verification read for store read roles.
- [x] Return public challenge instructions without exposing `challenge_hash`.

## Tests

- [x] Owner can create DNS verification challenge.
- [x] Owner can create platform-neutral plugin verification challenge.
- [x] Invalid verification method is rejected.
- [x] Viewer cannot create verification challenge.
- [x] Foreign store cannot be verified.
- [x] Operator can read latest verification state.
- [x] Expired pending verification is marked expired on read.
- [x] Run `php artisan test`; 34 tests passed with 105 assertions.

## Not Done In This Step

- DNS TXT lookup and hostname ownership validation.
- Connector/plugin `.well-known` challenge fetch.
- Store `verified_at` update on successful external verification.
- Store status transition to `active`.
- Pairing codes and integration credentials.
- Audit log entries for verification attempts.
- PostgreSQL-backed migration test run in CI.
