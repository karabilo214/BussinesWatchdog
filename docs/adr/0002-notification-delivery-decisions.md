# ADR 0002: Notification Delivery Decisions (Step 37)

Date: 2026-10-09

Status: accepted for the P0 email slice; owner review requested for the behavioural choices marked **[owner]**.

Section 23 of `spec/Business-Watchdog-TZ.md` leaves several behaviours open. This ADR records how Step 37 resolved them, so none of them is an unannounced business assumption.

## Decisions

### 1. Which incident transitions notify **[owner]**

Notify on: incident opened, incident reopened (within the 24h reopen window), incident auto-resolved by a fresh `ok` reconciliation (recovery).

Do not notify on: a repeated mismatch attaching to an already active incident (`signal_linked`, including the amount/rule changing within the same component), acknowledge, comment, snooze, manual resolve.

Reason: a repeated sweep over the same broken order would otherwise re-notify on every run — the "notification storm" the spec forbids. A person who manually resolves already knows.

Return plan: revisit once severity escalation exists (an escalation to `critical` should notify).

### 2. `uncertain` deliveries are not retried automatically **[owner]**

A provider timeout, or a worker that died while a delivery was `sending`, records `status = uncertain` and stops. It stays visible via `GET /notification-deliveries` (`delivery_uncertain: true`).

Reason: the message may already have been delivered; `spec/database/schema.sql` limits the due index to `queued`/`failed`, which matches this reading. The trade-off is that a genuinely lost message is not re-sent without a person seeing the uncertain state.

Return plan: a manual "resend" action, or a single automatic retry, if the owner prefers possible duplicates over possible silence.

### 3. Preference defaults **[owner]**

- `min_severity`: `warning` (so `info` is not sent by default; all current money incidents are `warning`).
- `notify_recovery`: `true`.
- `locale`/`timezone`: inherited from the tenant at send-planning time unless set on the channel.
- `store_ids`: `null` = all stores of the tenant.
- Quiet hours defer delivery to the end of the window (never drop it). `critical_bypasses_quiet_hours` defaults to `false` and only an `owner` may set it to `true`.

### 4. Suppression

If the incident has an active suppression (snooze) at fan-out time, the delivery row is still created with `status = suppressed`, `error_code = incident_suppressed`, so the skipped notification is auditable. Revoking a suppression does not yet re-notify (spec allows "once per dedupe transition"); deferred.

### 5. Fan-out happens in the outbox consumer, not in the transition transaction

The transition transaction writes a `domain_outbox` row (`incident.notification_requested`, dedupe key `incident:{id}:rev:{revision}:{kind}`). The outbox consumer creates one `notification_deliveries` row per deliverable channel with the same dedupe key; the `(tenant_id, channel_id, dedupe_key)` unique constraint makes replays a no-op. Content is built from the incident state at fan-out time, which may be seconds newer than the transition.

### 6. Retry schedule

Delays after attempts 1..5: 1 min, 5 min, 15 min, 1 h, 6 h; later attempts reuse 6 h. A provider `Retry-After` replaces the delay when it is longer. When the next attempt would be at or past `created_at + 24h`, the delivery becomes `dead_letter` and the channel `health` gets `status = failing`, `last_error_code`, `last_dead_letter_at`. A permanent provider error dead-letters immediately.

### 7. Email verification storage (schema deviation)

`spec/database/schema.sql` has no table for channel verification codes. Step 37 adds `notification_channel_verifications` (HMAC-SHA256 of the code keyed by `APP_KEY`, 15 min TTL, max 5 wrong attempts, older pending codes expire when a new one is issued). The code is mailed synchronously during the request and is never stored in plaintext or in `notification_deliveries`.

`notification_channels.destination_ciphertext` is `text` (Laravel `Crypt`, AES-256 with MAC) instead of `bytea`, the same deviation already used for `integration_credentials.ciphertext` in ADR 0001.

### 8. Email timeout detection is heuristic

`EmailNotificationSender` classifies a Symfony transport exception as `uncertain` when its message mentions a timeout, otherwise as a transient failure. Real SMTP behaviour has only been exercised against fakes (`Mail::fake`, an in-test sender), not a real provider.

### 9. Telegram is rejected for now

`POST /notification-channels` with `kind = telegram` returns `422 channel_kind_not_supported_yet`. Telegram is P1 and needs the binding-code bot flow (`ACC-38`).

### 10. Test notifications

`POST /notification-channels/{id}/test` uses dedupe key `test:{UTC YYYYMMDDHHmm}`; the unique constraint is the one-per-minute limit (`429 test_rate_limited`).

## Tracking

- `docs/implementation-step-37-checklist.md`
- `docs/progress.md` (Step 37)
