# ADR 0054: Owner settings — read-only configuration and a maintenance window

Status: accepted for now (owner's backlog item 2026-10-13: the «Настройки» section is not ready). Follows spec/panels/OWNER-PANEL.md §7 and ADMIN-BACKEND (secrets only as masked fingerprint / health); details chosen by the implementer while the owner was away; reviewed by the owner on 2026-10-16 with changes.

## Decisions

1. **Who**: new capability `settings.manage` — platform owner only.
2. **Configuration is read only**: environment, release, app URL, plan-limit enforcement, Paddle environment/account, whether the Paddle API key, client token, webhook secret and the Telegram token/webhook secret are set with a short `sha256:` fingerprint (never the values), mail sender, staff session limits, retention periods, site notification recipients. Secrets are changed in the server environment, not in the panel.
3. **Maintenance window** (the only editable setting): start, end (end after start and in the future, at most 24 h, start at most 90 days ahead) and a message in ru, en and de (3–300 characters); reason and the authenticator code; every publication is a new version that replaces the current one; cancellation also needs reason and code; history of versions with who/when/why; audited (`settings.maintenance_scheduled` / `_cancelled`).
4. **Effect**: customers see a banner in their account (`GET /api/v1/maintenance`) from publication until the end — "planned" before the start, "in progress" during it, in their language. It does not pause checks, notifications or billing by itself.
5. **Not in this step**: worker limits (they need scheduler integration and stay in the environment), pausing checks during maintenance, a banner on the public site.

## Owner decision (2026-10-16)

- Read-only configuration with secrets only as set / fingerprint is confirmed; the maintenance window rules are confirmed.
- During an active maintenance window scheduled browser checks, freshness assessment and customer notifications are paused automatically, so our own outage does not produce false alerts; data gaps that started inside the window must not raise incidents right after it ends. Billing is not paused (Paddle renews on its own).
- The maintenance banner is also shown on the public site, in the page language.

## Implementation of the pause (Step 182)

- `MaintenanceWindow`: a window is active from `starts_at` to `ends_at` unless cancelled. While active: no scheduled browser checks are created (manual runs stay possible), connector freshness is not assessed, customer notification deliveries and incident reminders wait (queued deliveries go out after the window), stuck payment attempts and the checkout-script evaluation are not run. Billing, ingestion, provider sync and reconciliation keep running.
- After a window ends, freshness counts a gap from the end of the window (heartbeat, delivery backlog, polled sync), so a gap that started inside it raises an incident only if it goes on past the usual threshold after the end. A window cancelled before it started changes nothing; one cancelled while running ends at the cancellation.
- Public site banner (Step 183): every public page (not the editor preview) shows the current window in the page language — planned with start and end, or in progress until the end — in Berlin time, with the window's message for that language.
