# Implementation Step 37 Checklist: Email Notifications On Incident Transitions

Stage: D5 notifications (P0 email slice)
Spec references: `spec/Business-Watchdog-TZ.md` sections 23, 26, 27; `spec/contracts/ui-api-catalog.md` (`/notification-channels`).
Acceptance references: `ACC-28` (email timeout/retry, dedupe, visible uncertain delivery), partially `ACC-39` (quiet hours and recovery preferences).
Decisions: `docs/adr/0002-notification-delivery-decisions.md`.

## Scope

- [x] Migration for `notification_channels` and `notification_deliveries` mirroring `spec/database/schema.sql` (composite tenant FKs, `(tenant_id, channel_id, dedupe_key)` unique, status/kind CHECKs, partial `deliveries_due_idx`), plus `notification_channel_verifications` (ADR 0002 §7).
- [x] Models: `NotificationChannel`, `NotificationDelivery`, `NotificationChannelVerification`.
- [x] Transactional request: `MoneyIncidentCorrelator` writes an `incident.notification_requested` outbox row inside the same transaction as the open / reopen / auto-resolve transition (`IncidentNotificationRequester`). A rolled-back transition leaves no request (tested).
- [x] Fan-out: `DomainOutboxDispatcher` handles the new topic via `IncidentNotificationPlanner` — one delivery per enabled + verified channel of the same tenant, filtered by `store_ids`, `min_severity`, `notify_recovery`; quiet hours set `next_attempt_at`; active suppression records a `suppressed` row. Replays are no-ops through the unique dedupe key.
- [x] Delivery worker `NotificationDeliveryWorker` + `php artisan notifications:deliver`: claims due rows (`queued`/`failed`), retry schedule 1m/5m/15m/1h/6h with `Retry-After`, dead letter after 24h with channel `health`, `uncertain` on timeout (not retried), sweep of rows stuck in `sending` past the lease to `uncertain`, `suppressed` when the channel was disabled after planning.
- [x] `NotificationChannelSender` interface (spec's `NotificationChannel` internal interface) + `EmailNotificationSender` (Laravel Mail; Mailpit locally) + registry.
- [x] Content: structured, PII-free `sanitized_content` (store name, severity, rule, order display number, amount only when discrepancy + currency exponent are known, possible-start interval never as an exact minute, source, checked steps, what to check, cabinet link). Rendered per channel locale at send time from `lang/{ru,en,de}/notifications.php`. Tenant UUID is never in the body. Amounts formatted by string arithmetic (`MinorUnits`), no floats.
- [x] Capture-missing wording does not claim that no capture happened ("не сопоставлено … не означает, что списания точно не было").
- [x] Public API (owner/admin): `GET /notification-channels`, `POST /notification-channels` (Idempotency-Key; email only, telegram → 422), `PATCH /notification-channels/{id}` (label/enabled/preferences merge/destination change → re-verification + audit without the address), `POST /notification-channels/{id}/verify`, `POST /notification-channels/{id}/test` (one per minute). `GET /notification-deliveries` (all roles) with `delivery_uncertain`.
- [x] Only `owner` can set `critical_bypasses_quiet_hours`; enabling an unverified channel is rejected; foreign tenants get 404.
- [x] `audit_log` rows for channel create/update/verify.

## Verification

- [x] `php -l` on all new/changed files (PHP 8.4).
- [x] `php vendor/bin/pint --test` on new/changed files: clean, except `DomainOutboxDispatcher.php`, whose 3 style findings already existed at HEAD and were left untouched.
- [x] Full `php artisan test` (PHP 8.4): 213 tests passed, 733 assertions (32 new: 15 flow, 12 API, 5 unit).
- [x] `php artisan migrate --force` against real PostgreSQL 18 (local Docker) applied the new migration cleanly; `\d notification_deliveries` shows the CHECKs, unique key, composite FKs and partial due index.
- [ ] Not tested against a real SMTP/email provider — only `Mail::fake` and an in-test sender.

## Not Done In This Step

- Telegram channel and binding-code bot flow (P1, `ACC-38`).
- Digest mode and 24h open-incident reminders (opt-in).
- Re-notification after a suppression is revoked.
- Maintenance windows (`financial_notifications` / `all_notifications` scope) — no maintenance model exists yet.
- Manual resend for `uncertain` / `dead_letter` deliveries.
- Scheduler wiring for `outbox:dispatch` / `notifications:deliver` (both are bounded one-shot commands; no scheduler/leader lock yet).
- Resend-verification endpoint (a new code is issued only on create or destination change).
- Channel deletion.
- Notifications for checkout/sales-drop families (no detectors yet) and for severity escalation (no escalation yet).
