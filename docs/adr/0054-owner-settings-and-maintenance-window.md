# ADR 0054: Owner settings — read-only configuration and a maintenance window

Status: accepted for now (owner's backlog item 2026-10-13: the «Настройки» section is not ready). Follows spec/panels/OWNER-PANEL.md §7 and ADMIN-BACKEND (secrets only as masked fingerprint / health); details chosen by the implementer while the owner was away; to be confirmed by the owner.

## Decisions

1. **Who**: new capability `settings.manage` — platform owner only.
2. **Configuration is read only**: environment, release, app URL, plan-limit enforcement, Paddle environment/account, whether the Paddle API key, client token, webhook secret and the Telegram token/webhook secret are set with a short `sha256:` fingerprint (never the values), mail sender, staff session limits, retention periods, site notification recipients. Secrets are changed in the server environment, not in the panel.
3. **Maintenance window** (the only editable setting): start, end (end after start and in the future, at most 24 h, start at most 90 days ahead) and a message in ru, en and de (3–300 characters); reason and the authenticator code; every publication is a new version that replaces the current one; cancellation also needs reason and code; history of versions with who/when/why; audited (`settings.maintenance_scheduled` / `_cancelled`).
4. **Effect**: customers see a banner in their account (`GET /api/v1/maintenance`) from publication until the end — "planned" before the start, "in progress" during it, in their language. It does not pause checks, notifications or billing by itself.
5. **Not in this step**: worker limits (they need scheduler integration and stay in the environment), pausing checks during maintenance, a banner on the public site.
