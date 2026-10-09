# Текущее состояние проекта

Этот файл — быстрая точка восстановления контекста в начале новой сессии. Подробная история по шагам — в `docs/progress.md`. Правила работы — в `CLAUDE.md` и `AGENTS.md`.

## Обновлено

2026-10-09 (Step 47)

## Что это за проект

Business Watchdog — SaaS для обнаружения финансовых расхождений и поломок checkout в WooCommerce-магазинах. Laravel модульный монолит + WooCommerce-плагин + Stripe read-only + изолированный Playwright-воркер. ИИ не реализуется. Полное ТЗ — `spec/Business-Watchdog-TZ.md`, порядок чтения — `AGENTS.md`.

## Этап разработки

Репозиторий сейчас в фазе **D1–D5 бэкенд-слайсов** (по внутренней нумерации шагов `docs/progress.md`, не всегда совпадает 1:1 с разделом 36 ТЗ). P0 pilot пока не достигнут — минимальный кабинет, браузерные проверки и email ещё не реализованы.

Реализовано (backend, `apps/backend`, до Step 44 включительно):

- Локальный инфраструктурный bootstrap (Docker Compose: PostgreSQL 18, Redis, S3Mock вместо MinIO, Mailpit).
- Auth/tenancy: регистрация, membership/roles; с Step 39 — Sanctum SPA-сессии (`statefulApi`), CSRF на `api/*`, сессии в БД, лимиты login 5/мин и signup 3/час.
- Store pairing: pairing code (с Step 40 — лимит 20/час/IP и audit неудачных попыток), HMAC-подписанные credentials (keyring с версиями ключей шифрования, ротация с 24ч draining), store verification (с Step 42 — реальная проверка DNS TXT / connector challenge через SSRF-безопасный клиент, активация магазина, запрет browser checks до проверки).
- Durable event ingestion (с Step 41 — полная JSON Schema Draft 2020-12 валидация через `opis/json-schema`): `event_inbox`, idempotency, обработка дублей/конфликтов revision, лимиты батча, quarantine невалидных событий.
- Projections: orders/order_revisions/refunds/payments/financial_transactions из нормализованных событий.
- Domain outbox: dispatcher, exponential backoff, sweeper для истёкших lease, bounded loop команда `outbox:dispatch`.
- Integration lifecycle: list/detail/revoke endpoints, audit log (`audit_log`).
- Payment/refund allocation foundation: таблицы `payment_allocations`/`refund_allocations`, `PaymentAllocationService` с блокировками строк и проверкой сумм.
- Allocation revoke/unlink: `PaymentAllocationService::revokeCaptureAllocation()`/`revokeRefundAllocation()` — reason обязателен, повторный revoke запрещён, revoke capture allocation блокируется активными refund allocations на неё, каждый revoke пишет `audit_log`.
- Reconciliation foundation: таблицы `reconciliation_runs`/`reconciliation_findings`, `OrderReconciliationService::evaluate()` — считает G/C/RW/RP по одному заказу. Правила: `MONEY_UNSUPPORTED`, `MONEY_CAPTURE_MISSING`, `MONEY_CAPTURE_AMOUNT`, `MONEY_REFUND_MISSING`, `MONEY_REFUND_EXTRA`, `MONEY_MULTIPLE_CAPTURES`, `MONEY_CURRENCY_MISMATCH`, `MONEY_ORDER_CHANGED` (все — per-order, несмотря на то, что три последних сначала казались store-wide). `MONEY_PAYMENT_WITHOUT_ORDER` — единственное действительно store-wide правило, отдельный `UnmatchedPaymentScanner`. Все 9 rule-кодов раздела 14 ТЗ покрыты. Синхронно, без scheduler/nightly sweep/dirty-order coalescing.
- Публичный API allocation + reconciliation: `POST /payment-allocations[/{id}/revoke]`, `POST /refund-allocations[/{id}/revoke]`, `POST /stores/{id}/reconciliations`, `GET /stores/{id}/findings`. Новая инфраструктура `Idempotency-Key` (`idempotency_keys` таблица + middleware, reserve-then-run mutex через unique constraint) обязательна на всех create/trigger-мутациях. По ходу закрыт реальный пробел в `PaymentAllocationService::allocateRefund` — refund и payment_allocation теперь должны принадлежать одному заказу (`ERROR_ORDER_MISMATCH`).

- Детальные GET-эндпоинты: `GET /orders/{id}` (поля заказа + последний finding по каждому rule_code + captures/refunds/refund_transactions/allocations/revisions), `GET /payments/{id}` (аналогично для платежа), `GET /stores/{id}/unmatched-payments` (подсказки exact_candidate/manual_review для orphan-captures, read-only, ничего не пишет). Общий `App\Support\Api\UuidCursor` для курсорной пагинации.
- Incident engine (money family): таблицы `signals`/`incidents`/`incident_signals`/`incident_activity`/`suppressions`. `MoneyIncidentCorrelator` открывает/привязывает инциденты по mismatch-findings, авто-резолвит по свежему ok-finding, реоткрывает в течение 24ч после resolve или создаёт новый инцидент после. Fingerprint группирует rule_code в более крупный `component` (`capture`, `refund`, ...) — иначе переход MONEY_CAPTURE_MISSING→MONEY_CAPTURE_AMOUNT после починки никогда бы не авто-резолвился. `IncidentLifecycleService`: acknowledge/resolve/comment/snooze/revoke-suppression. Подключено в `ReconciliationController::store()` — каждый trigger автоматически обновляет инциденты. Публичный API: `GET /incidents[/{id}]`, `POST /incidents/{id}/acknowledge|resolve|comments|snooze`, `POST /suppressions/{id}/revoke`.

- Email-уведомления (раздел 23 ТЗ, P0-часть): таблицы `notification_channels`/`notification_deliveries`/`notification_channel_verifications`. Переход инцидента (open/reopen/auto-resolve) в той же транзакции пишет `incident.notification_requested` в outbox; outbox-диспетчер раскладывает его в delivery на каждый включённый и подтверждённый канал (фильтр магазинов, порог severity, recovery opt-out, quiet hours, suppression → `suppressed`). Worker `notifications:deliver`: retry 1м/5м/15м/1ч/6ч + Retry-After, dead letter после 24ч с health канала, timeout → `uncertain` без автоповтора. Шаблоны ru/en/de без PII. API: `/notification-channels` (создание с кодом подтверждения на email, PATCH, verify, test раз в минуту), `GET /notification-deliveries`. Telegram пока отклоняется (P1). Решения — `docs/adr/0002-notification-delivery-decisions.md` (владелец принял как есть 2026-10-09).
- Фоновая обработка (Step 38): «грязные» заказы (`reconciliation_dirty_subjects`) помечаются в транзакции проекции события и при создании/отзыве allocation, коалесинг 30 с; `reconciliation:process-dirty` пересчитывает их и обновляет инциденты (а значит и уведомления) без ручного вызова API. Повторная проверка в момент окончания grace. Ночной sweep 90 дней раз в сутки (02:30 UTC) с уникальным окном в `scheduled_job_windows`. Laravel scheduler: outbox каждые 10 с, dirty и уведомления каждые 30 с; в Docker Compose добавлен сервис `scheduler`. Решения — `docs/adr/0003-scheduler-and-dirty-reconciliation.md`.

263 теста: на SQLite 259 проходят + 4 PostgreSQL-only пропускаются; на PostgreSQL 18 (`make backend-test-pgsql`) проходят все 263 (`php artisan test` на PHP 8.4). Все новые миграции (reconciliation, idempotency_keys, incident engine, notifications, scheduler) проверены и накатаны на реальной PostgreSQL 18 в локальном Docker. Email проверен только через `Mail::fake`/тестовый sender, не через реальный SMTP.

Отклонения из ADR 0001 закрыты или формализованы в Steps 39–44 (у каждого пункта есть Resolution); локальное S3 — `docs/adr/0004-local-object-storage.md` (MinIO больше не раздаётся публично, остаётся S3Mock). Остальные решения — ADR 0002 (уведомления), ADR 0003 (scheduler).

WooCommerce-плагин (`plugins/woocommerce-watchdog`, Step 45): фундамент — слой совместимости по возможностям (HPOS/legacy, classic/blocks, Action Scheduler/WP-Cron), локальные таблицы, pairing, подписанный heartbeat с ротацией, публичный challenge. Нижняя граница по решению владельца: PHP 7.4 / WP 5.9 / WC 6.0 (ADR 0005). Docker-матрица из 6 целей (WC 6.0.2 … 11.2.0, legacy и HPOS) + e2e с бэкендом — всё зелёное; проверенные комбинации в `docs/compatibility.md`. С Step 46 плагин пишет снимки заказов/refund/удалений в локальный outbox с ревизиями (хуки только помечают заказ, снимок на shutdown; суммы строками в minor units); найденные различия версий WC задокументированы в `docs/compatibility.md`. С Step 47 плагин доставляет события в бэкенд (батчи, retry, dead letter, suspend), делает rescan 48ч каждые 15 мин, backfill 90 дней и шлёт capabilities/deployment события; e2e на всех 6 версиях WooCommerce доходит до проекций бэкенда.

Пока пустые заглушки: `apps/frontend` (Vue), `apps/browser-worker` (Node/Playwright).

## Следующий шаг

WooCommerce-плагин работает сквозь всю цепочку (P0-часть коннектора). Варианты дальше (выбирает владелец):

1. **Минимальный кабинет** (`apps/frontend`): вход, магазины + верификация + pairing code, интеграции, инциденты, сверка, каналы уведомлений.
2. **Stale-integration detection** на бэкенде (нет 3 heartbeat → stale/partial, ACC-14) и coverage-сигналы — данные от плагина теперь есть.
3. **Stripe read-only коннектор** (P1) — без него сверка видит только данные магазина, а captures/refunds провайдера не приходят.
4. Решено, отложено (ADR 0006, ТЗ §10.1): Stripe опционален, ключ вводится в кабинете; без Stripe сверка денег → `unknown/provider_not_connected` без инцидентов; события `independent_provider` только от ключа провайдера. Реализовать до первого реального запуска сверки.
5. Доработки плагина: флаг деградации при backlog > 7 дней и лимит 100 000, отчёт «disabled» при деактивации, выгрузка диагностики, поиск пропавших заказов в ежедневном аудите.

## Как возобновить работу

1. Прочитать этот файл и `docs/progress.md` (хвост, последние 2–3 шага).
2. Проверить `git log --oneline -10` и `git status`.
3. Перед новым шагом свериться с `AGENTS.md` и соответствующими разделами `spec/Business-Watchdog-TZ.md`.
4. После выполнения шага — обновить и этот файл, и `docs/progress.md`, и при необходимости `docs/adr/`.
