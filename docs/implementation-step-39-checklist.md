# Implementation Step 39 Checklist: Sanctum SPA Sessions, CSRF And Auth Throttling

Stage: ADR 0001 gap closure (auth hardening)
Spec references: `spec/Business-Watchdog-TZ.md` sections 3 (Vue SPA with Sanctum, same-origin session, CSRF), 7 (auth); `spec/contracts/ui-api-catalog.md` (rate-limit defaults, session login/logout).
Acceptance references: `spec/ACCEPTANCE.md` mandatory security checks (CSRF).
Closes: ADR 0001 "Auth API Uses Session Middleware Without Sanctum Package", "Backend Uses File Sessions".

## Scope

- [x] `laravel/sanctum` ^4.3 installed; `config/sanctum.php` published; `personal_access_tokens` migration rewritten for UUID users (`uuidMorphs`, tz timestamps). Tokens are not issued anywhere — SPA session only.
- [x] `bootstrap/app.php`: `statefulApi()`; the former `preventRequestForgery(except: ['api/*'])` exclusion removed, so CSRF is verified for every stateful SPA request.
- [x] Routes: user-facing groups use `stateful.session` + `auth:sanctum` (+ `tenant.session`); HMAC connector routes untouched.
- [x] `RequireStatefulSession` middleware: `400 stateful_session_required` for session endpoints called outside the configured SPA origins.
- [x] `AuthController` uses the explicit `web` guard for login/attempt/logout.
- [x] `SetTenantContextFromSession` tolerates a request without a session.
- [x] Rate limiters `auth-login` (5/min per email+IP) and `auth-signup` (3/hour per IP), configurable in `config/watchdog.php`.
- [x] `.env.example`: `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE=lax`, `SANCTUM_STATEFUL_DOMAINS`.
- [x] Test base sends `Origin: http://localhost` and resets guards/session on `actingAs` (Sanctum `AuthenticateSession` correctly logs out when the session's user changes).

## Verification

- [x] `php -l`, pint on changed files.
- [x] Full `php artisan test` (PHP 8.4): 235 tests passed (7 new in `StatefulSessionSecurityTest`).
- [x] `php artisan migrate --force` on local PostgreSQL 18.
- [x] Manual smoke against `php artisan serve` + PostgreSQL with curl: `/sanctum/csrf-cookie` 204; register without `X-XSRF-TOKEN` → 419; with token → 201; `/auth/me` and `/stores` with the session → 200; same cookies from a foreign Origin → 401.

## Not Done In This Step

- Email verification, password reset (3/hour/account limit), session revoke list, MFA.
- Frontend integration (no SPA exists yet).
