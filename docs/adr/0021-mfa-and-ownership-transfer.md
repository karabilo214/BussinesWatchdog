# ADR 0021: Two-Factor Authentication and Ownership Transfer

Date: 2026-10-10

Status: accepted with changes by the owner on 2026-10-16 (Step 69; MFA warning Step 164, customer MFA reset Step 165; later enhancements in `docs/backlog.md`).

## Context

Spec §2 (auth): MFA is mandatory for system operators and SHOULD for the tenant owner; role changes and ownership transfer are transactional and a tenant never loses its owner. The UI/API catalog asks for an MFA challenge instead of a session at login, hashed backup codes, enrollment confirmed by a code, and `POST /ownership-transfer` with re-authentication. Platform staff (`/owner`, `/admin`) do not exist yet; this ADR covers tenant users.

## Decisions

- **Method: TOTP (RFC 6238)** — HMAC-SHA1, 6 digits, 30-second steps, ±1 step of clock drift; works with every common authenticator app. Implemented in a small class (`App\Support\Account\Totp`, tested against the RFC reference values) instead of Laravel Fortify: Fortify brings its own login/registration routes, which would replace the SPA auth built in Steps 39 and 64. The only new dependency is `bacon/bacon-qr-code` (the QR library Fortify itself uses) to draw the enrollment QR code as SVG.
- **Secret storage.** The base32 secret is encrypted with the keyring (versioned key, same as integration credentials) in `users.mfa_secret_ciphertext` + `mfa_key_version`. The column was `bytea` in `spec/database/schema.sql`; it is now `text` because the keyring produces a base64 string (the same as every other ciphertext column). MFA is on when `mfa_confirmed_at` is set.
- **Enrollment.** `POST /auth/mfa/setup` needs the current password and issues a new secret (QR + key for manual entry); nothing changes for sign-in until `POST /auth/mfa/confirm` receives a valid code. Confirming turns MFA on, returns **10 recovery codes once** and ends every other session of the user (they were signed in without the second factor).
- **One use per code.** The last accepted step is stored (`mfa_last_used_step`); the same or an older code is refused, so an observed code cannot be replayed. Recovery codes (`xxxxx-xxxxx`, 31-character alphabet without look-alikes, ~49 bits each) are stored only as SHA-256 hashes in `mfa_recovery_codes` and each works once; case and the dash are ignored.
- **Sign-in.** With MFA on, a correct password answers `{"mfa_required": true}` and no session is authenticated; the session only remembers the pending sign-in (user, "remember me") for **5 minutes and 5 wrong codes**. `POST /auth/mfa/challenge` accepts an authenticator or recovery code and only then signs in. Expiry answers `401 mfa_challenge_expired` and the dashboard goes back to the password. Limits: 10 challenge requests per minute per IP on top of the existing login limit.
- **Re-authentication (step-up)** for disabling MFA, new recovery codes and ownership transfer: current password, plus an authenticator or recovery code when MFA is on. 10 such requests per minute per user.
- **MFA is not forced for tenant owners** (spec says SHOULD): the profile shows owners without MFA a recommendation. A password reset does not turn MFA off.
- **Lost phone and lost recovery codes:** there is no self-service recovery. Tenant-owner and platform-staff reset paths were added in Step 165, with step-up, audit, session revocation and an email to the user.
- **Audit.** `user.mfa_enabled`, `user.mfa_disabled`, `user.mfa_recovery_codes_regenerated`, `user.mfa_recovery_code_used` are written to every team the user belongs to (MFA belongs to the person, the audit log is per team).
- **Ownership transfer.** Only the owner; the target is an existing member of the team with a **confirmed e-mail address** (not the owner themself, not a disabled account). In one transaction with both memberships locked: the target becomes owner, the previous owner becomes **admin** (they keep access; the new owner may change or remove them later). Audit `membership.ownership_transferred` with both user ids and the target's previous role. The target does not have to have MFA.

## Not done

A non-blocking MFA warning for team members remains. Trusted devices were added in Step 256. Customer sessions were added in Step 254; customer passkeys in Step 255. Platform-staff authenticator reset remains a separate backlog item (ADR 0052).

## Owner decision (2026-10-16)

- MFA stays optional for tenant owners, but the dashboard overview shows a prominent warning that the account is at risk while MFA is off.
- Platform staff get a "disable MFA" action for each customer user; the tenant owner gets a "reset MFA" action for any member of their team. Both are audited.
- After an ownership transfer the previous owner becomes admin (confirmed).
- The new owner is not required to have MFA, but must be told clearly that MFA is off.
- Passkeys, trusted devices, a session list and a non-blocking MFA warning for team members go to the general TODO.

## Amendment 2026-10-25 (Step 253, owner decision)

Switching off a customer member's MFA from the control panel is reserved for the service owner (`platform_owner`), still with a fresh authenticator code and a reason. Other staff get 403 `owner_only` even if they hold `customers.mfa_reset`; the denied attempt is audited. Staff with `tenants.support` can instead send the member the standard password reset e-mail (reason required, audited); they never see or set the password.

## Amendment 2026-10-25 (Step 254, customer sessions)

The profile lists the user's active customer sessions from Laravel's database session store, with only a browser family, IP address, last activity and a current-session marker. Raw session IDs and payloads are not returned. Sessions expired by the configured lifetime are omitted; a new current session is included before Laravel writes it at the end of the request. "Sign out everywhere" requires the current password and, when enabled, an authenticator or recovery code; it removes every customer session, revokes the remember token through logout, ends the current session and audits the action in every tenant the user belongs to. This does not manage separate platform staff sessions.

## Amendment 2026-10-25 (Step 255, customer passkeys)

Customer accounts may register discoverable WebAuthn passkeys after a fresh password and TOTP/recovery-code step-up. Registration authorization and challenges expire after five minutes; challenge data is consumed once. The WebAuthn library checks RP ID, allowed origin, challenge, user verification, signature and signature counter. Passkey login is passwordless and uses the authenticator's local user verification; it creates a customer session only for an enabled user with team membership. The existing password and TOTP path remains available. Keys belong to a user across their teams, are listed without credential material, and require step-up for deletion. Register, login and delete are audited in every team. Laravel Passkeys server and JS client provide the WebAuthn ceremony; package routes are disabled in favor of the project's stateful, throttled API. Production must use a stable APP_URL origin and user-handle secret across key rotation. Platform staff authentication remains separate.

## Amendment 2026-10-25 (owner correction)

Customer-team MFA stays optional. Members without MFA receive a visible recommendation and setup link like the organization owner; sign-in and team work are not blocked. The earlier team-wide forced-MFA backlog wording is superseded.

## Amendment 2026-10-25 (Step 256, trusted customer devices)

After a successful TOTP/recovery challenge, the customer may explicitly trust this browser for 30 days. A 256-bit random token is stored only as a hash on the server and sent in a host-only, HttpOnly, SameSite=Strict cookie scoped to customer auth paths (Secure in production). A later password sign-in skips the TOTP prompt only when the token belongs to that exact user and has not expired. Sensitive account and ownership step-up still requires the factor. The profile lists browser family and expiry, without token or raw user agent, and allows revocation. Password change/reset, MFA reset/disable/re-enrollment, sign-out-everywhere and privacy anonymization revoke all trusts. This applies only to customer login, never platform staff. Trusted-device creation, reuse and revocation are audited in every tenant of the user.

## Amendment 2026-10-25 (Step 257, team-member warning)

The existing overview warning and profile setup link are available to all customer roles without MFA. The profile now gives admins, operators and viewers the same visible recommendation as owners, with explicit wording that enrollment is optional. Both warnings disappear once MFA is enabled. No server policy or sign-in behavior changes.
