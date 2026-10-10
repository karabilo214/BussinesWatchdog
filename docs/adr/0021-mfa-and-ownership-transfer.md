# ADR 0021: Two-Factor Authentication and Ownership Transfer

Date: 2026-10-10

Status: implemented in Step 69; awaiting owner review.

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
- **Lost phone and lost recovery codes:** there is no self-service recovery; resetting MFA needs support through a future secure recovery runbook (platform staff panel, not built yet).
- **Audit.** `user.mfa_enabled`, `user.mfa_disabled`, `user.mfa_recovery_codes_regenerated`, `user.mfa_recovery_code_used` are written to every team the user belongs to (MFA belongs to the person, the audit log is per team).
- **Ownership transfer.** Only the owner; the target is an existing member of the team with a **confirmed e-mail address** (not the owner themself, not a disabled account). In one transaction with both memberships locked: the target becomes owner, the previous owner becomes **admin** (they keep access; the new owner may change or remove them later). Audit `membership.ownership_transferred` with both user ids and the target's previous role. The target does not have to have MFA.

## Not done

MFA for platform staff (with the panels), WebAuthn/passkeys, MFA reset by support, a "trusted device" option, a list of sessions per device, forcing MFA per team.
