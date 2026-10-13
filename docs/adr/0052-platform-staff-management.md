# ADR 0052: Platform staff management («Сотрудники»)

Status: accepted for now (owner's backlog item 2026-10-13: the «Сотрудники» section is not ready). Follows spec/panels/OWNER-PANEL.md §7 (staff); details chosen by the implementer while the owner was away; to be confirmed by the owner.

## Decisions

1. **Who manages**: `staff.manage` — platform owners only. Any of the four roles can be granted, including platform owner (only an owner can do that).
2. **Invitation**: email + role + reason + the owner's authenticator code; link valid 72 hours, a new invitation revokes an earlier pending one for the address; an existing staff account is refused. Audit `staff.invited` keeps a hash of the address, not the address.
3. **Joining**: the link opens `/owner/staff-invitation`; a new person sets a name and a password (min 12) — the address counts as verified because the link was emailed to it; an existing account proves its password. Then the authenticator app must be connected (QR / key) and a code confirmed; only then the staff member exists (`staff.joined`), recovery codes are shown once. Staff without an authenticator still cannot sign in.
4. **Changes**: role change and disable/enable need a reason and the authenticator code; ending sessions needs a reason. All of them bump `permission_version`, so the member's sessions end at once. One cannot change oneself; the last active owner can never be demoted or disabled (checked under a row lock).
5. **Authenticator reset** is not a panel action: `php artisan platform:reset-mfa <email> --reason=…` on the server disables the old authenticator, ends sessions, enrols a new one and is audited (`staff.mfa_reset`).
6. **Rate limits**: invitation and role/state changes use the step-up limiter; the public invitation pages have their own limit (20 a minute per IP), separate from sign-in.
