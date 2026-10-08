# Step 06 Checklist: Auth Session Foundation

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 7, 24, 26, 36; `spec/contracts/ui-api-catalog.md` section 2.

This step adds the first same-origin session auth endpoints. It does not implement email verification, password reset, MFA, invitations, ownership transfer, Sanctum publishing, or tenant switching yet.

## Endpoints

- [x] `POST /api/v1/auth/register`
- [x] `POST /api/v1/auth/login`
- [x] `POST /api/v1/auth/logout`
- [x] `GET /api/v1/auth/me`

## Behavior

- [x] Register normalizes email.
- [x] Register creates user, tenant, and owner membership in one transaction.
- [x] Register logs in the new user and stores `active_tenant_id` in session.
- [x] Login returns user DTO, active tenant id, and memberships.
- [x] `me` requires authentication.
- [x] Logout invalidates session.
- [x] Responses avoid password hash, MFA ciphertext, and secrets.

## Tests

- [x] Register creates user, tenant, and owner membership.
- [x] Login and `me` return membership context.
- [x] `me` rejects unauthenticated access.
- [x] Logout invalidates session.

## Not Done In This Step

- Email verification.
- Password reset.
- MFA.
- Sanctum package publication/config hardening.
- Tenant switch endpoint.
- Invitations.
- Policies and role permission matrix.
