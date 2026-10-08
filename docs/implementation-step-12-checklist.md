# Step 12 Checklist: Connector Pairing Exchange Foundation

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 8, 9, 24, 26, 27, 36; `contracts/ui-api-catalog.md` section 3; `spec/database/schema.sql` `integrations`, `integration_credentials`, `pairing_codes`.

This step adds one-time connector pairing and HMAC credential bootstrap. It is platform-neutral: connector identity is `connector_code`, not a WordPress-only enum.

## Implementation

- [x] Add `integrations`, `integration_credentials`, and `pairing_codes` migrations.
- [x] Add `Integration`, `IntegrationCredential`, and `PairingCode` models.
- [x] Add `POST /api/v1/stores/{store}/pairing-codes`.
- [x] Add stateless `POST /api/v1/pairing/exchange`.
- [x] Hash pairing codes in storage and return raw code only once.
- [x] Enforce tenant scoping and owner/admin store manage role for code creation.
- [x] Validate `connector_code` as lowercase snake_case provider code.
- [x] Enforce exchange at-most-once with `consumed_at`.
- [x] Issue exactly 32 random secret bytes as base64 and store encrypted secret material.
- [x] Bind exchange to the store `base_url`.

## Tests

- [x] Owner can create pairing code.
- [x] Viewer cannot create pairing code.
- [x] User cannot create pairing code for foreign store.
- [x] Connector can exchange pairing code once for HMAC secret.
- [x] Consumed pairing code cannot be exchanged twice.
- [x] Expired pairing code is rejected.
- [x] Exchange requires matching store base URL.
- [x] `connector_code` is platform-neutral and validated.

## Not Done In This Step

- IP-level rate limiting for pairing attempts.
- HMAC request authentication middleware.
- Nonce replay protection.
- Integration list/revoke/rotate endpoints.
- Connector-specific capability discovery.
- Audit log entries for pairing and credential issue.
- Production keyring strategy beyond Laravel encrypted payloads.
