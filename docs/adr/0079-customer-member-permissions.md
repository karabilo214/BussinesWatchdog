# ADR 0079: Customer member permissions

Date: 2026-10-26

Status: accepted (owner's decisions 2026-10-26; implemented in Step 263)

## Context

Customer members had a fixed role (`admin` / `operator` / `viewer`) whose rights lived in `TenantRoles` as lists of roles per route, and one organisation-wide switch `show_money_amounts_to_team` decided whether the team saw amounts. The owner asked for per-member permissions modelled on platform staff (ADR 0064): checkboxes per cabinet section, roles as presets, editable for members and pending invitations, server checks everywhere.

## Decisions

1. **Owner and members.** The organisation owner has every permission, always; `memberships.permissions` is `NULL` for the owner and a list for everyone else (PostgreSQL CHECK). `role` stays: `owner`, the preset the list matches (`admin` / `operator` / `viewer`) or `custom`.
2. **Catalog** (`TenantPermissions`), grouped by section: stores — `stores.read`, `stores.manage`, `integrations.manage`; checks — `checks.run`; reconciliation — `reconciliation.read`, `reconciliation.run`, `allocations.manage`; incidents — `incidents.read`, `incidents.manage`, `incidents.snooze`; notifications — `notifications.manage`; amounts — `money.view`; settings — `team.manage`.
3. **Owner only** (shown greyed out, never given; owner's decision): `billing.manage`, `stores.delete`, `ownership.transfer`, `members.mfa_reset`, `support.access`, `organization.delete`. This keeps TZ §7 (subscription belongs to the owner) and ADR 0021/0069/0078.
4. **Base and implied permissions.** `stores.read` is every member's base (without it nothing opens). `reconciliation.run` and `allocations.manage` bring `reconciliation.read`; `incidents.manage` and `incidents.snooze` bring `incidents.read`.
5. **Section views are separate** (owner's decision): without `incidents.read` the incident list, cards and incident exports answer 403 and `GET /overview` returns `incidents: null`; without `reconciliation.read` findings, payments without an order and findings/transaction exports answer 403, and `discrepancies`, `unknown_amount_incidents` and the `findings` of order/payment cards are `null` — hidden, never shown as zero. The cabinet hides those menu items and tiles and shows the verdict "no overall verdict" instead of "no problems found".
6. **Amounts are a permission** (owner's decision): `money.view` replaces the organisation switch. The migration gives each member and pending invitation `money.view` exactly when their organisation's switch was on, then drops `tenants.show_money_amounts_to_team` and `PATCH /money-visibility`. Seeing amounts does not decide the preset (an operator without amounts is still "operator").
7. **Presets** are the former role rights: admin — everything assignable except amounts; operator — base, reconciliation view/run, incidents view/handle; viewer — base, reconciliation and incident view. Every existing member and pending invitation got exactly its role's set; behaviour did not change.
8. **Who may change what.** The owner manages every other member and gives any assignable permission. A member with `team.manage` gives only permissions they have themselves and manages only members and invitations whose permissions they all have (otherwise 403 `team_forbidden`, a request beyond their own — 422 `permission_not_assignable`). Nobody changes their own permissions; the owner is never changed here. Consequence: an admin may now also manage another admin with the same permissions (before, admins managed only operators and viewers).
9. **Effect and trace.** Every check (`tenant.can:<permission>` middleware, `TenantAccess` in controllers) reads the stored list, so a change applies on the member's next request, also in an open session; the cabinet re-reads its session in the background when it is older than a minute. A change writes `membership.permissions_changed` / `invitation.permissions_changed` with role and list before and after, and the member gets an email listing what was added and removed. Pending invitations can be corrected until accepted (`PATCH /invitations/{id}`; the link stays the same).
10. **Every protected route declares its permission.** A test fails when a route of the signed-in tenant group has no `tenant.can:` middleware, except the tenant switch and organisation deletion (ADR 0069), which check membership/ownership themselves because they must work while the organisation is being deleted.

## Consequences

- Platform staff changing a member's role at the customer's request (ADR 0048) apply the preset and keep the member's choice about amounts.
- A rollback of the migration restores the organisation switch as "on" and turns `custom` members into viewers; it is a safety net, not a lossless undo.
