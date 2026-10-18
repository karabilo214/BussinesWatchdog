# ADR 0064: Staff permissions set by the platform owner

Status: implemented (Step 173). Owner decision of 2026-10-16 (ADR 0046, ADR 0052): instead of fixed roles in code the platform owner assigns each staff member what they may do. Points 3 and 4 below were chosen by the implementer and wait for the owner's confirmation.

## Decisions

1. **Two roles:** `platform_owner` — everything, always; `platform_member` — exactly the capabilities in their own list (`platform_staff.capabilities`, also on invitations). The database enforces that an owner has no list and a member has one.
2. **Capabilities** (the existing capability codes, in panel order): customers view / change / MFA reset, billing view, refund request / approval, plans view / change, website, contact requests view / reply, service, audit, settings, staff.
3. **Owner only:** `staff.manage` (ADR 0052: managing staff is the owner's) and `refunds.approve` (ADR 0044: staff request, the platform owner approves — money leaves the platform). They cannot be given to a member; the panel shows them greyed out.
4. **Implied capabilities:** a capability that is useless alone brings its read capability (customers change and MFA reset → customers view; refund request → billing view; plans change → plans view; replies → contact requests view).
5. **Presets:** the former roles `platform_admin`, `platform_support`, `platform_billing` are buttons that fill the list; existing members were migrated to `platform_member` with exactly the capabilities their role had, pending invitations too. Saving a preset name stores a member with that preset.
6. **Changing access** is the existing staff change: reason, the owner's authenticator code, not oneself, never the last active owner, `permission_version` bumped — the member's sessions end — and the audit keeps the capability list before and after. A member needs at least one capability (422 `capabilities_invalid`).
7. **Panel:** the staff section shows each member's access, the invitation and change forms have the permission picker; the main menu shows only the sections a member may open, and a member without billing access starts in their first section instead of the overview.

## Consequences

- No hidden capability: the server checks the member's own list on every request (`PlatformCapabilities::forStaff`).
- Owner-specific rules elsewhere stay with the owner role (for example the unmasked customer email in the organisation card).
