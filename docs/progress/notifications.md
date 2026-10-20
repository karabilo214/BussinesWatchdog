# Progress: Notifications

Email notifications, Telegram binding and webhook, reminders, outgoing mail authentication.

### Step 37: Email Notifications On Incident Transitions

Status: complete for the P0 email slice of section 23

Added:

- `notification_channels`, `notification_deliveries` (per `spec/database/schema.sql`) and `notification_channel_verifications` (schema addition, ADR 0002).
- Transactional request: `MoneyIncidentCorrelator` writes an `incident.notification_requested` outbox row in the same transaction as open/reopen/auto-resolve. `DomainOutboxDispatcher` fans it out via `IncidentNotificationPlanner` into one delivery per enabled+verified channel (store filter, severity threshold, recovery opt-out, quiet hours, suppression → `suppressed` row). Dedupe key `incident:{id}:rev:{revision}:{kind}` + unique `(tenant_id, channel_id, dedupe_key)` make replays safe.
- `NotificationDeliveryWorker` + `php artisan notifications:deliver`: retry 1m/5m/15m/1h/6h with `Retry-After`, dead letter after 24h with channel `health`, timeout → `uncertain` (not retried), stuck `sending` → `uncertain`.
- `NotificationChannelSender` interface, `EmailNotificationSender`, PII-free structured content rendered per locale from `lang/{ru,en,de}/notifications.php`; amounts via string arithmetic (`MinorUnits`).
- API: `GET/POST /notification-channels`, `PATCH /notification-channels/{id}`, `POST .../verify`, `POST .../test`, `GET /notification-deliveries`. Email verification code (hashed, 15 min, 5 attempts), owner-only critical quiet-hours bypass, audit on create/update/verify. Telegram rejected with `channel_kind_not_supported_yet`.
- `docs/adr/0002-notification-delivery-decisions.md` (behaviour choices for owner review), `docs/implementation-step-37-checklist.md`.
- `AGENTS.md` intro no longer claims the repository is specification-only.

Verification:

- PHP syntax checks passed (PHP 8.4) for all new/changed files.
- `php vendor/bin/pint --test` on new/changed files: clean (pre-existing findings in `DomainOutboxDispatcher.php` left as they were at HEAD).
- `php artisan test` (PHP 8.4) passed: 213 tests, 733 assertions.
- `php artisan migrate --force` against real PostgreSQL 18 in local Docker applied the new migration cleanly.
- Email sending exercised only through `Mail::fake` and an in-test sender, not a real SMTP provider.

Remaining:

- Telegram binding (P1), digest and reminders, re-notify after suppression revoke, maintenance windows, manual resend of uncertain/dead-letter, scheduler wiring for `outbox:dispatch`/`notifications:deliver`, resend-verification and channel deletion endpoints.

### Step 88: Telegram Notification Binding

Status: complete (first backend/frontend slice)

Updated:

- Backend accepts `kind=telegram` notification channels as pending channels with a one-time 6-digit binding code, encrypted pending destination, masked destination output and normal tenant/idempotency/audit handling.
- Added secret-protected `POST /api/v1/webhooks/telegram/bind` for the Telegram bot gateway: the code must come from the chat that will receive alerts, then the channel is verified/enabled and the encrypted destination becomes the Telegram chat id.
- Added `TelegramNotificationSender` to the notification sender registry: it uses the configured Bot API token, maps Telegram 429/5xx/timeouts/permanent rejects into existing retry/dead-letter semantics, and keeps notification content PII-free through the existing renderer.
- Customer frontend notifications screen now offers Email/Telegram recipient creation in the existing compact form style, shows the one-time Telegram code, supports reissuing an unverified Telegram code, hides email address changes for Telegram channels and keeps ru/en/de labels/error explanations in sync.
- `contracts/openapi.yaml` and `spec/contracts/openapi.yaml` now document Telegram channel creation and the bot bind webhook; `contracts/ui-api-catalog.md`, `PROJECT_STATE.md` and `spec/ACCEPTANCE.md` were updated as evidence.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications/NotificationChannelApiTest.php tests/Feature/Contracts/OpenApiRoutesTest.php` — 16 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications` — 29 passed.
- Customer frontend: `npm run check -w @bw/customer` from `apps/frontend` — 90 tests passed and production build completed.

Remaining gaps:

- Real Telegram bot deployment/webhook registration and a live Telegram send were not tested in this slice.

### Step 89: Telegram Binding Code Hardening

Status: complete

Updated:

- Telegram binding now looks up only active, unconsumed Telegram verification rows by code hash instead of scanning unrelated verification rows.
- New Telegram binding codes are checked against active global Telegram bindings before issue; if a legacy/manual collision is still present, binding returns `telegram_binding_code_collision` and leaves all channels unverified instead of picking one by timestamp.
- The new rejection code is localized in ru/en/de for the customer app.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications` — 30 passed.
- Customer frontend: `npm run check -w @bw/customer` from `apps/frontend` — 90 tests passed and production build completed.

Coordination:

- Claude's Shopware test files were left unstaged; this step only stages notification hardening and evidence files.

### Step 90: Native Telegram Update Webhook

Status: complete

Updated:

- Added `POST /api/v1/webhooks/telegram/updates` for native Telegram Bot API webhook updates. It accepts `message` and `channel_post`, checks the configured secret against `X-Telegram-Bot-Api-Secret-Token` (and keeps the internal header for controlled tests/tools), extracts a 6-digit binding code from text, and binds the sending chat directly.
- Updates without a binding code or chat id return `202 {"status":"ignored"}` and do not create side effects.
- `contracts/openapi.yaml` and `spec/contracts/openapi.yaml` document the native update endpoint; project state, acceptance and UI catalog evidence were bumped.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications/NotificationChannelApiTest.php tests/Feature/Contracts/OpenApiRoutesTest.php` — 19 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications` — 32 passed.

Remaining gaps:

- Real Telegram bot webhook registration and live update delivery were not tested in this slice.

Coordination:

- Claude's Shopware test files were left unstaged; this step only stages Telegram webhook and evidence files.

### Step 91: Telegram Sender Failure Semantics

Status: complete

Updated:

- Added direct unit coverage for `TelegramNotificationSender` so its Bot API outcome mapping is explicit and protected: success returns provider message id, 429 uses Telegram `retry_after`, 5xx is retryable, 4xx is permanent, missing bot token / invalid destination are permanent configuration failures, and transport exceptions become `uncertain`.
- Acceptance/project/catalog evidence now points to Telegram sender success/failure tests in addition to binding/webhook tests.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Unit/Notifications/TelegramNotificationSenderTest.php` — 5 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Unit/Notifications tests/Feature/Notifications` — 42 passed.

Coordination:

- Claude's Shopware files were left unstaged; this step only stages Telegram sender test and evidence files.

### Step 92: Telegram Webhook Registration Readiness

Status: complete

Updated:

- Added `telegram:webhook set|info|delete` for production operations. `set` registers `/api/v1/webhooks/telegram/updates` through Telegram Bot API with `secret_token`, `allowed_updates=message,channel_post`, optional pending-update drop and HTTPS URL validation; `info`/`delete` support smoke checks and rollback.
- Added `TELEGRAM_WEBHOOK_URL` config alongside the existing bot token, username and webhook secret.
- Acceptance/project/catalog evidence now distinguishes automated webhook registration readiness from the remaining live Telegram delivery smoke.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan list telegram` — command registered.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications/TelegramWebhookCommandTest.php` — 4 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Unit/Notifications tests/Feature/Notifications` — 46 passed.

Remaining gaps:

- No real Telegram token was used in this slice; live send/update delivery must be checked against the production bot after deployment.

Coordination:

- Claude's Shopware files were left unstaged; this step only stages Telegram webhook registration readiness and evidence files.

### Step 94: Telegram Live Smoke and Handle Display

Status: complete

Updated:

- Fixed Telegram bot handle rendering in the customer notification binding hint: `TELEGRAM_BOT_USERNAME` may be configured with or without `@`, but the UI now displays exactly one leading `@`.
- Completed live Telegram smoke with the real bot through a Cloudflare quick tunnel: `telegram:webhook set` registered the native update endpoint, `telegram:webhook info` confirmed the URL with zero pending updates, a real Telegram message with the binding code reached the backend, the channel became verified, and a backend-triggered test notification was delivered to the Telegram chat.
- Acceptance/project/catalog evidence now marks ACC-38 as done, with the remaining deployment note limited to replacing the quick tunnel with a stable production domain/tunnel.

Verification:

- Customer frontend: `npm run check -w @bw/customer` — 92 tests passed and production build completed.
- Live Telegram: Cloudflare `/up` returned 200; unauthenticated webhook update returned 401; secret-authenticated update returned 202; real bot binding update verified `Telegram chat ***3272`; test delivery `01a12aea-a2e8-71d3-81e2-fd89a15d2f13` reached `sent` and the message was received in Telegram.

Coordination:

- Claude's Shopware script files were left unstaged; this step only stages Telegram UI/evidence files.

### Step 95: Notification Reminders

Status: complete

Updated:

- Added opt-in open-incident reminders for notification channels: `reminder_hours` is validated and normalized per channel, `incident_reminder` deliveries are deduped per incident/channel/window, and reminders are only planned after a previously sent open/reopen/reminder delivery.
- Added `notifications:remind` plus hourly scheduler wiring. The planner respects tenant/store scope, enabled+verified channels, severity/store filters, active suppressions and quiet hours.
- Customer notifications UI now exposes reminders inside the existing compact preferences form and shows the reminder interval in the channel summary. RU/EN/DE translations and OpenAPI contracts were updated.
- Acceptance/catalog/project evidence for ACC-39 now records reminders as implemented; daily digest remains the notification gap.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications tests/Unit/Notifications` — 49 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan list notifications` — `notifications:remind` registered.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test` — 401 passed, 4 skipped.
- Customer frontend: `npm run check -w @bw/customer` — 93 tests passed and production build completed.

Coordination:

- Claude's Shopware script files were left unstaged; this step only stages notification reminder and evidence files.

### Step 107: Outgoing Mail Authentication (DKIM, SPF, DMARC)

Status: complete

- `MAIL_RETURN_PATH` (Laravel's global `mail.return_path`) sets the bounce address on every message.
- `DkimMailSigner` signs every outgoing message on `MessageSending` (relaxed/relaxed, SHA-256, `Return-Path` not signed) when `MAIL_DKIM_ENABLED=true` with `MAIL_DKIM_DOMAIN`, `MAIL_DKIM_SELECTOR` and a key (`MAIL_DKIM_PRIVATE_KEY` with `\n` or `MAIL_DKIM_PRIVATE_KEY_PATH`, optional passphrase). An unreadable key does not block delivery: the message leaves unsigned and `mail.dkim_signing_failed` is logged (domain, selector, exception class only) — alerts must still go out; `mail:auth-check` catches the misconfiguration before release.
- `php artisan mail:auth-check [--strict]` (`MailAuthenticationInspector`): SPF on the return-path domain, DKIM configured (own key readable as RSA, or `MAIL_DKIM_PROVIDER_MANAGED=true` with domain + selector), DKIM TXT present and not revoked (empty `p=`), own private key matches the published `p=`, DMARC (`v=DMARC1` with `p=none|quarantine|reject`, also found on a parent domain), relaxed alignment of return-path and DKIM domains with the sender domain (equal, subdomain or parent; siblings are reported as not aligned because no public suffix list is used — conservative). Fails in `--strict` or in `production`.
- README «Выпуск релиза → Почта»: both DKIM options, key generation, the three DNS records, the check command.

Verification:

- `tests/Feature/Mail/MailAuthenticationTest.php` — 10 tests: header added; a multipart HTML+text message sent through the Laravel mailer verifies cryptographically (relaxed body hash and header signature against the public key; tampered body or recipient fail); unreadable key sends unsigned and logs; inspector happy path, mismatched and revoked DKIM key, unreadable private key, provider-managed DKIM with a provider bounce subdomain, DMARC on the parent domain, strict failure and non-strict report.
- Backend SQLite: 468 passed, 4 skipped; PostgreSQL 18: 472 of 472.
- Real SMTP to local Mailpit with a freshly generated key: the raw message received by Mailpit verified with the independent `dkimpy` 1.1.8 verifier (`True`; with the recipient changed `False`). No real mail provider or public DNS was used.

### Step 137: Telegram Recipients Shown as Subscription-Only When the Plan Lacks Them

Status: complete

- Owner's backlog item. Notifications → add recipient → Telegram now reads the account entitlements: when limits are enforced and the plan does not include `notifications_telegram`, the tab carries «по подписке», the form shows that Telegram needs a subscription that includes it with a link to the plans, and «Добавить» stays disabled. In pilot mode, with the feature included, or when entitlements cannot be loaded, the form works as before. The backend refusal (403 `feature_not_in_plan`, `EntitlementsTest::test_telegram_channel_needs_the_feature`) is unchanged and remains the real guard.

Verification: `tests/notificationsPlan.test.ts` (2: locked without the feature; open with the feature and in pilot mode). Customer 119 tests; frontend check green. Backend SQLite 557 passed, 4 skipped (unchanged).

### Step 198: Daily Digest (ADR 0067)

Status: complete; the choices marked [owner] in ADR 0067 wait for the owner's review

- New channel preference `daily_digest_at` (`HH:MM` in the channel timezone, `null` = off, the default). Validated like the other preferences; returned in `preferences` / `effective_preferences`.
- `DailyDigestPlanner` + `notifications:digest` (scheduler, every 15 minutes): at most one `daily_digest` delivery per channel and local day (dedupe `digest:{local date}`), on the first run after the channel's time; enabled + verified channels only, none when no store is in the channel's scope; quiet hours defer it (no critical bypass); paused while our maintenance window is active.
- `DailyDigestContentBuilder` (template `digest.v1`, snapshot at planning time, last 24 h): verdict as on the overview — «there are active incidents» / «no problems found, data incomplete» with each store's gaps (connector not fresh, no payment provider, checkout check not passing, paused by the plan) / «no problems found» only with full coverage; up to 10 active incidents (critical first) with links; verified discrepancy amounts of active money incidents per mode, currency and component, summed by the database; incidents without an amount counted; per store — active by severity, new/resolved in 24 h, checks passed/failed/other, last successful check. All severities are counted regardless of `min_severity`. No order numbers or customer data.
- `NotificationRenderer` renders the digest in ru/en/de (`lang/*/notifications.php` → `digest`); incident titles reuse the family subjects.
- Customer cabinet: «Ежедневная сводка» block in the channel preferences (checkbox + time, 08:00 suggested), «сводка в HH:MM» in the channel summary, delivery log labels for `daily_digest` and the previously missing `incident_reminder`.
- Contracts: OpenAPI `NotificationPreferences.daily_digest_at`, `notification_kind` + `daily_digest`; `contracts/ui-api-catalog.md`. ACC-39 → done. `docs/ops/setup.md` lists the new scheduled command. No migrations.

Verification: `tests/Feature/Notifications/DailyDigestTest.php` (5 tests: opt-in and once per local day; «data incomplete» with gaps and no «fine»; scope by tenant and store filter, all severities, verified amount per mode/currency, rendered links; quiet hours and maintenance window; disabled channel and no stores); `NotificationChannelApiTest` validates `daily_digest_at`. Backend SQLite 635 passed + 4 skipped, PostgreSQL 18 639/639, Pint ok; customer cabinet 134/134, typecheck and build. Real SMTP/Telegram delivery of a digest was not run.
