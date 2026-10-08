# Текущее состояние проекта

Этот файл — быстрая точка восстановления контекста в начале новой сессии. Подробная история по шагам — в `docs/progress.md`. Правила работы — в `CLAUDE.md` и `AGENTS.md`.

## Обновлено

2026-10-08 (Step 36)

## Что это за проект

Business Watchdog — SaaS для обнаружения финансовых расхождений и поломок checkout в WooCommerce-магазинах. Laravel модульный монолит + WooCommerce-плагин + Stripe read-only + изолированный Playwright-воркер. ИИ не реализуется. Полное ТЗ — `spec/Business-Watchdog-TZ.md`, порядок чтения — `AGENTS.md`.

## Этап разработки

Репозиторий сейчас в фазе **D1–D5 бэкенд-слайсов** (по внутренней нумерации шагов `docs/progress.md`, не всегда совпадает 1:1 с разделом 36 ТЗ). P0 pilot пока не достигнут — минимальный кабинет, браузерные проверки и email ещё не реализованы.

Реализовано (backend, `apps/backend`, до Step 36 включительно):

- Локальный инфраструктурный bootstrap (Docker Compose: PostgreSQL 18, Redis, S3Mock вместо MinIO, Mailpit).
- Auth/tenancy: регистрация, сессии (пока без Sanctum), membership/roles.
- Store pairing: pairing code, HMAC-подписанные credentials, store verification (challenge создаётся, но внешняя проверка DNS/connector ещё не выполняется).
- Durable event ingestion: `event_inbox`, idempotency, обработка дублей/конфликтов revision, лимиты батча, quarantine невалидных событий.
- Projections: orders/order_revisions/refunds/payments/financial_transactions из нормализованных событий.
- Domain outbox: dispatcher, exponential backoff, sweeper для истёкших lease, bounded loop команда `outbox:dispatch`.
- Integration lifecycle: list/detail/revoke endpoints, audit log (`audit_log`).
- Payment/refund allocation foundation: таблицы `payment_allocations`/`refund_allocations`, `PaymentAllocationService` с блокировками строк и проверкой сумм.
- Allocation revoke/unlink: `PaymentAllocationService::revokeCaptureAllocation()`/`revokeRefundAllocation()` — reason обязателен, повторный revoke запрещён, revoke capture allocation блокируется активными refund allocations на неё, каждый revoke пишет `audit_log`.
- Reconciliation foundation: таблицы `reconciliation_runs`/`reconciliation_findings`, `OrderReconciliationService::evaluate()` — считает G/C/RW/RP по одному заказу. Правила: `MONEY_UNSUPPORTED`, `MONEY_CAPTURE_MISSING`, `MONEY_CAPTURE_AMOUNT`, `MONEY_REFUND_MISSING`, `MONEY_REFUND_EXTRA`, `MONEY_MULTIPLE_CAPTURES`, `MONEY_CURRENCY_MISMATCH`, `MONEY_ORDER_CHANGED` (все — per-order, несмотря на то, что три последних сначала казались store-wide). `MONEY_PAYMENT_WITHOUT_ORDER` — единственное действительно store-wide правило, отдельный `UnmatchedPaymentScanner`. Все 9 rule-кодов раздела 14 ТЗ покрыты. Синхронно, без scheduler/nightly sweep/dirty-order coalescing.
- Публичный API allocation + reconciliation: `POST /payment-allocations[/{id}/revoke]`, `POST /refund-allocations[/{id}/revoke]`, `POST /stores/{id}/reconciliations`, `GET /stores/{id}/findings`. Новая инфраструктура `Idempotency-Key` (`idempotency_keys` таблица + middleware, reserve-then-run mutex через unique constraint) обязательна на всех create/trigger-мутациях. По ходу закрыт реальный пробел в `PaymentAllocationService::allocateRefund` — refund и payment_allocation теперь должны принадлежать одному заказу (`ERROR_ORDER_MISMATCH`).

- Детальные GET-эндпоинты: `GET /orders/{id}` (поля заказа + последний finding по каждому rule_code + captures/refunds/refund_transactions/allocations/revisions), `GET /payments/{id}` (аналогично для платежа), `GET /stores/{id}/unmatched-payments` (подсказки exact_candidate/manual_review для orphan-captures, read-only, ничего не пишет). Общий `App\Support\Api\UuidCursor` для курсорной пагинации.
- Incident engine (money family): таблицы `signals`/`incidents`/`incident_signals`/`incident_activity`/`suppressions`. `MoneyIncidentCorrelator` открывает/привязывает инциденты по mismatch-findings, авто-резолвит по свежему ok-finding, реоткрывает в течение 24ч после resolve или создаёт новый инцидент после. Fingerprint группирует rule_code в более крупный `component` (`capture`, `refund`, ...) — иначе переход MONEY_CAPTURE_MISSING→MONEY_CAPTURE_AMOUNT после починки никогда бы не авто-резолвился. `IncidentLifecycleService`: acknowledge/resolve/comment/snooze/revoke-suppression. Подключено в `ReconciliationController::store()` — каждый trigger автоматически обновляет инциденты. Публичный API: `GET /incidents[/{id}]`, `POST /incidents/{id}/acknowledge|resolve|comments|snooze`, `POST /suppressions/{id}/revoke`.

181 тест, 583 assertions проходят (`php artisan test` на PHP 8.4). Все новые миграции (reconciliation, idempotency_keys, incident engine) проверены и накатаны на реальной PostgreSQL 18 в локальном Docker.

Известные зафиксированные отклонения от спеки — `docs/adr/0001-bootstrap-deviations.md` (S3Mock вместо MinIO, нет Sanctum, упрощённые FK в projection-таблицах, неполная JSON Schema валидация ingest, store verification без реальной внешней проверки, pairing без полного anti-abuse).

Пока пустые заглушки: `apps/frontend` (Vue), `apps/browser-worker` (Node/Playwright), `plugins/woocommerce-watchdog`. `docs/compatibility.md` не заполнен — D0 compatibility spike (точные версии WP/WooCommerce/Stripe gateway/Playwright) не проводился.

## Следующий шаг

По "Not Done In This Step" из `docs/implementation-step-36-checklist.md`:

1. **Уведомления** (раздел 23 ТЗ) — email/Telegram поверх incident transitions. Сейчас incidents создаются/резолвятся, но никто не уведомляется. Естественное продолжение incident engine.
2. Checkout/sales-drop incident families — нужен browser worker и metrics pipeline, которых пока нет (это следующий крупный пласт после уведомлений, если идти по плану D0-D9).
3. Critical severity escalation — сейчас все money-инциденты warning по умолчанию (осознанно, без скрытого threshold).
4. Dirty-order coalescing / scheduler — сейчас correlate() запускается только явно через `POST /stores/{id}/reconciliations`, нет фонового пересчёта.
5. Более мелкие доработки: `rule_configs`-версионирование, windowed bulk trigger, настоящий `dry_run`, подписанный cursor, rate limiting, очистка `idempotency_keys`, автоматический matcher, nightly allocation audit.

Параллельно остаются открытыми более ранние gaps из ADR 0001 (Sanctum, DNS/connector верификация домена, полная JSON Schema валидация) — не блокируют текущий слайс, но нужны до P0/P1 acceptance.

## Как возобновить работу

1. Прочитать этот файл и `docs/progress.md` (хвост, последние 2–3 шага).
2. Проверить `git log --oneline -10` и `git status`.
3. Перед новым шагом свериться с `AGENTS.md` и соответствующими разделами `spec/Business-Watchdog-TZ.md`.
4. После выполнения шага — обновить и этот файл, и `docs/progress.md`, и при необходимости `docs/adr/`.
