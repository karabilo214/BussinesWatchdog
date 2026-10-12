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
