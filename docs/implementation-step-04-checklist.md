# Step 04 Checklist: Auth, Tenancy, and Store Schema Foundation

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 7, 8, 24, 25, 26, 36; `spec/ACCEPTANCE.md` ACC-01, ACC-02, ACC-03.

This step creates the first Laravel migrations and models for identity, tenant membership, invitations, and stores. It does not implement controllers, policies, registration, pairing, or store verification workflows yet.

## Migrations

- [x] Align `users` with reference UUID schema.
- [x] Add case-insensitive unique email index through `lower(email)`.
- [x] Keep framework `password_reset_tokens` and `sessions` support tables.
- [x] Add `tenants`.
- [x] Add `memberships` with composite primary key.
- [x] Add `invitations`.
- [x] Add `stores`.
- [x] Add reference CHECK constraints for roles, states, config version, HTTPS base URL, and currency code.
- [x] Add tenant/store unique key for future composite references.

## Models

- [x] Update `User` for UUIDs and `password_hash`.
- [x] Add `Tenant`.
- [x] Add `Membership`.
- [x] Add `Invitation`.
- [x] Add `Store`.
- [x] Add basic relationships.

## Verification

- [ ] Run `make backend-shell`.
- [ ] Run `php artisan migrate:fresh`.
- [ ] Run `php artisan test`.
- [ ] Confirm `/health/ready` remains green after migrations.

## Not Done In This Step

- Registration/login implementation.
- Sanctum installation/configuration.
- TenantContext enforcement.
- Policies and permission matrix.
- Store CRUD endpoints.
- Pairing, HMAC credentials, integrations, or verification flows.
