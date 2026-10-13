# ADR 0048: Customer store data in the owner panel

Status: accepted for now (owner's backlog item 2026-10-13: in the customer card, add a store and open a store to see "findings, orders and screenshots, incidents"). Details chosen by the implementer while the owner was away; to be confirmed by the owner.

## Decisions

1. **Same data as the customer sees**: platform endpoints `/platform-api/v1/tenants/{tenant}/…` do not re-implement queries. They set `TenantContext` to that organisation (`source = platform_staff`) for the request and call the existing account actions (store, findings, order, checks, check, screenshot URL, incidents, incident). Tenant scoping, the history window of the plan, money as minor-unit strings and redacted screenshots therefore behave exactly as in the account. Every id in the path is looked up with `tenant_id` of the organisation first (another organisation's id answers 404).
2. **Orders list** is new (the account has none): orders of the store, newest first, cursor pages, within the history window; the order DTO has no buyer personal data.
3. **Read only**: no acknowledging/resolving incidents, no rechecks, no allocations, no check starts from the panel.
4. **Who**: `tenants.manage` (platform owner). Support and other roles do not see store data.
5. **Audit**: opening a store is recorded as `tenant.store_viewed` (store id, organisation); individual list/detail reads are not recorded separately to keep the audit useful. Adding a store is `tenant.store_created` with the name, address, platform and a required reason.
6. **Adding a store** uses the account's own action: https address rules, the plan's store limit (403 `plan_store_limit_reached`), status starts as in the account (not verified; the customer connects the plugin and verifies the domain themselves).
