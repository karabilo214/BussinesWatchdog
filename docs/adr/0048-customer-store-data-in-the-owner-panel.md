# ADR 0048: Customer store data in the owner panel

Status: accepted for now (owner's backlog item 2026-10-13: in the customer card, add a store and open a store to see "findings, orders and screenshots, incidents"). Details chosen by the implementer while the owner was away; reviewed by the owner on 2026-10-16 with changes.

## Decisions

1. **Same data as the customer sees**: platform endpoints `/platform-api/v1/tenants/{tenant}/…` do not re-implement queries. They set `TenantContext` to that organisation (`source = platform_staff`) for the request and call the existing account actions (store, findings, order, checks, check, screenshot URL, incidents, incident). Tenant scoping, the history window of the plan, money as minor-unit strings and redacted screenshots therefore behave exactly as in the account. Every id in the path is looked up with `tenant_id` of the organisation first (another organisation's id answers 404).
2. **Orders list** is new (the account has none): orders of the store, newest first, cursor pages, within the history window; the order DTO has no buyer personal data.
3. **Read only**: no acknowledging/resolving incidents, no rechecks, no allocations, no check starts from the panel.
4. **Who**: `tenants.manage` (platform owner). Support and other roles do not see store data.
5. **Audit**: opening a store is recorded as `tenant.store_viewed` (store id, organisation); individual list/detail reads are not recorded separately to keep the audit useful. Adding a store is `tenant.store_created` with the name, address, platform and a required reason.
6. **Adding a store** uses the account's own action: https address rules, the plan's store limit (403 `plan_store_limit_reached`), status starts as in the account (not verified; the customer connects the plugin and verifies the domain themselves).

## Amendment (Step 127): team members of the customer

7. The owner can invite a team member of the organisation from the customer card (roles admin, operator, viewer — like an owner in the account; owner role only through ownership transfer) and revoke a pending invitation, each with a reason. `TeamService::inviteForPlatform` / `revokeForPlatform` share the account flow (earlier pending invitation of the address revoked, 409 for an existing member, 7-day link); the letter names "the Business Watchdog team" as the inviter, the organisation audit records it as a system action with `by: platform`, the platform audit as `tenant.member_invited` / `tenant.invitation_revoked`. Changing or removing existing members from the panel is not part of this step.

## Owner decision (2026-10-16)

- Not read only any more: staff (support, by an assignable permission) may rerun browser checks and acknowledge/resolve incidents on behalf of the customer, each with a reason and audited.
- Audit records actions that change something (POST/PUT/PATCH/DELETE), not views: `tenant.store_viewed` is dropped; every change made from the panel is recorded in the platform audit and in the organisation audit as `by: platform`.
- The plan's store limit stays strict for adding stores. Actions started by staff (for example a browser check rerun) do not consume the customer's limits or usage.
- Staff may change roles and remove existing members of the customer's team (on the customer's request), with a reason and audited; the owner role still moves only through ownership transfer.

## Implementation of the support actions (Step 175)

- Capability `tenants.support` ("Customers: support actions", implies customers view, in the support preset for new members; ADR 0064) — given by the platform owner.
- Rerun: `POST /platform-api/v1/tenants/{tenant}/stores/{store}/checks` with a scenario and a reason. Same conditions as a manual run (eligible store, in plan, not capacity-paused, no active run, one support run a minute), but the customer's manual-run allowance is neither checked nor used; the run has trigger `support` (shown to the customer as "rerun by support").
- Incidents: `POST …/incidents/{incident}/acknowledge|resolve` with a reason. The incident activity has no customer user as actor and records `by: platform` with the reason; the customer sees "Business Watchdog support" and the reason in the timeline.
- Every action is in the platform audit (`tenant.check_rerun`, `tenant.incident_acknowledged`, `tenant.incident_resolved`, with the reason) and in the organisation audit (`check_run.support_started`, `incident.acknowledged_by_platform`, `incident.resolved_by_platform`, `by: platform`).

