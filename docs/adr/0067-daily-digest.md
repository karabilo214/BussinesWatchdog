# ADR 0067: Daily digest of notification channels

Status: implemented in Step 198; the choices marked **[owner]** wait for the owner's review.

## Context

TZ §1 and §23: when the sources are healthy, the user gets notifications about problems and, if they want, a short summary; the tenant chooses the digest together with recipients, quiet hours and the severity threshold. ACC-39 kept «Daily digest not implemented» as the last notification gap; `contracts/ui-api-catalog.md` reserved a `daily_digest` preference. Incident notifications (ADR 0002) and reminders (Step 95) already exist per channel.

## Decision

1. **Per channel, opt-in** **[owner]**. The preference is `daily_digest_at` — local time `HH:MM` in the channel timezone, `null` (default) means off. The cabinet suggests 08:00. It is a time instead of the reserved boolean, so each recipient gets the summary at its own working start.
2. **Once per local day.** `notifications:digest` runs every 15 minutes and plans the digest on its first run after `daily_digest_at`, with dedupe key `digest:{local date}` per channel (the unique delivery constraint makes repeats a no-op). A digest switched on after today's time is sent at once, the next one tomorrow at the time.
3. **Period**: the 24 hours before planning. Content is a snapshot at planning time (like incident notifications); delivery later because of quiet hours or retries does not rebuild it.
4. **Scope**: the channel's store filter. **All severities** are counted and listed, `min_severity` is not applied **[owner]**: a once-a-day summary that hides active warnings would contradict its own verdict.
5. **Verdict** as on the overview (TZ §24): «there are active incidents» when any is active; «no problems found» only when every store in scope has a fresh connector, reconciliation running and passing checkout checks; otherwise «no problems found, data incomplete» with the gaps of each store. A store paused by the plan is shown as paused, its results unknown.
6. **Content**: up to 10 active incidents (critical first, then oldest) with store, title and link; verified discrepancy amounts of active money incidents per mode, currency and component, summed by the database and never across them; incidents without a verified amount are counted, not zero; per store — active by severity, new and resolved in 24 h, checkout checks passed/failed/other, last successful check, gaps. No order numbers, customer data or secrets. Amounts follow incident notifications **[owner]**: the owner's «hide amounts from the team» setting (Step 161) covers the dashboard and API, not notification channels, which owners and admins configure.
7. **Delivery** follows ADR 0002: the same retry schedule, dead letter and `uncertain` handling. Quiet hours defer the digest to their end (no critical bypass — it is not urgent). During our maintenance window planning waits; the day's digest goes out after the window.
8. **No digest** for disabled or unverified channels and when no store is in the channel's scope.
9. **No plan gating** **[owner]**: available wherever the channel itself is (Telegram stays a plan feature).

## Consequences

- New notification kind `daily_digest` (`notification_deliveries.incident_id` is null), template `digest.v1`, texts in ru/en/de.
- ACC-39 is closed.

## Tracking

- `docs/progress/notifications.md` (Step 198)
