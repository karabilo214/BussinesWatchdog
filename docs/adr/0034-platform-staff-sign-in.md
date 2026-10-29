# ADR 0034: Control panel sign-in for platform staff

Status: implemented (Step 103)

## Context

Owner 2026-10-12: everything sold (plans, prices, billing periods, limits, discounts) must be managed in the owner's panel. spec/panels/OWNER-PANEL.md §1–§2 and ADMIN-BACKEND.md: platform staff in a separate table with its own login and session guard, customer sessions never accepted, mandatory MFA, idle 30 min / absolute 8 h, bootstrap owner by a safe local command, staff audit. This step is the entrance; plans and discounts follow.

## Decisions

1. **Tables** from the reference schema: `platform_staff` (one row per linked `users` account, role `platform_owner|admin|support|billing`, state, `mfa_required` always true, `permission_version`) and `platform_audit_log` (actor staff or system, action, target, result, sanitized changes, request id). Staff invitations come with staff management.
2. **Separate routes and cookies**: `/platform-api/v1` with its own middleware group — session cookie `bw-platform-session` (host-only, SameSite=Strict, ends with the browser, idle lifetime 30 min) and CSRF cookie `BW-PLATFORM-XSRF` sent back in `X-XSRF-TOKEN`; the CSRF token is always required (no same-origin shortcut). Guard `platform` (session, users provider). The customer session and its Sanctum cookies are neither set nor accepted there; a customer account without a staff row gets the same "wrong email or password" as a wrong password.
3. **Sign-in**: password, then an authenticator code or a recovery code (5 minutes, 5 attempts). The account's existing authenticator is used (same person, same TOTP as in the customer app, ADR 0021).
4. **No enrollment after a password alone**: a staff member without an authenticator cannot sign in (403 `platform_mfa_enrollment_required`); otherwise whoever learned the password could attach their own authenticator. The first owner is created and enrolled on the server: `php artisan platform:create-owner <email>` (asks for a new account's password hidden, or confirms attaching an existing account; shows the otpauth URI and secret, requires a code, prints recovery codes once). Later staff will enroll through invitation tokens.
5. **Session limits**: idle 30 min, absolute 8 h, and any change of `permission_version` (role change, disable) ends existing sessions (`platform_session_expired`). Limits: 5 sign-ins per minute per IP and 30 per hour per email; 10 codes per minute per IP.
6. **Capabilities by role** in one map (`PlatformCapabilities`); routes declare the capability they need (`platform.staff:<capability>`); the panel hides what the role cannot do, the backend enforces it.
7. **Audit**: every sign-in attempt (denied at password / not enrolled / wrong code, succeeded), sign-out and the bootstrap are written to `platform_audit_log`; failed attempts by an unknown address store only its SHA-256.
8. **Panel**: `apps/frontend/control` `/owner` — sign-in in two steps, header with the payment mode (TEST sandbox / LIVE), staff name and role, sections from the spec (overview, customers, subscriptions, payments, plans and discounts, service, staff, audit, settings) as placeholders until their steps. ru/en/de.

## Staff passkeys (Step 307, owner's instruction 2026-10-28)

A user-verified discoverable WebAuthn credential can replace the password plus TOTP ceremony for staff **sign-in**. Possession of the credential and local user verification are required; user presence alone is rejected. Enrolled TOTP/recovery remains mandatory as backup, and existing sensitive-action TOTP checks remain unchanged. The profile permits enrolling/listing/deleting only one's own keys; enrollment and deletion require fresh current password plus TOTP or an unused recovery code.

`platform_passkeys.staff_id` references `platform_staff`, never customer `passkeys.user_id`. The opaque HMAC user handle includes the distinct `platform_staff` table name, so an authenticator on the shared RP cannot overwrite a customer's identity. Laravel Passkeys' attestation/signature validators are reused through narrowly scoped storage/lookup actions; the package's global customer model, guard and bindings are never switched. The platform routes use a distinct parameter name because the package globally binds `{passkey}` to customer keys even when its routes are ignored.

Only `platform_passkey.*` session state is used; challenge and enrollment authorization last 300 seconds and completion consumes them even when the credential is invalid. Enrollment authorization binds staff ID and permission version. Session blocking prevents concurrent completion of the same challenge. Origin/RP derive from configured `APP_URL`, not caller headers. UV, origin, RP ID, signature, user handle and counter are verified. The staff session is issued by the existing `PlatformSession` with the same guard, cookies, capability/version checks and 30-minute / 8-hour limits. Disabled users/staff and staff without enrolled MFA are refused.

Disabling a staff member through staff management, resetting MFA (including server reset), or completing a password-recovery link revokes platform credentials; customer credentials remain independent. Own password change retains passkeys. Successful registration, deletion and sign-in, and rejected credential verification are audited without credential material. Last staff login includes passkey logins. See [operations](../ops/platform-passkeys.md), OpenAPI, EXT-03 and `PlatformPasskeyTest`.
