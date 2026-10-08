# Текущее состояние проекта

Этот файл — быстрая точка восстановления контекста в начале новой сессии. Подробная история по шагам — в `docs/progress.md`. Правила работы — в `CLAUDE.md` и `AGENTS.md`.

## Обновлено

2026-10-08 (Step 32)

## Что это за проект

Business Watchdog — SaaS для обнаружения финансовых расхождений и поломок checkout в WooCommerce-магазинах. Laravel модульный монолит + WooCommerce-плагин + Stripe read-only + изолированный Playwright-воркер. ИИ не реализуется. Полное ТЗ — `spec/Business-Watchdog-TZ.md`, порядок чтения — `AGENTS.md`.

## Этап разработки

Репозиторий сейчас в фазе **D1–D4 бэкенд-слайсов** (по внутренней нумерации шагов `docs/progress.md`, не всегда совпадает 1:1 с разделом 36 ТЗ). P0 pilot пока не достигнут — минимальный кабинет, браузерные проверки, incident engine и email ещё не реализованы.

Реализовано (backend, `apps/backend`, до Step 32 включительно):

- Локальный инфраструктурный bootstrap (Docker Compose: PostgreSQL 18, Redis, S3Mock вместо MinIO, Mailpit).
- Auth/tenancy: регистрация, сессии (пока без Sanctum), membership/roles.
- Store pairing: pairing code, HMAC-подписанные credentials, store verification (challenge создаётся, но внешняя проверка DNS/connector ещё не выполняется).
- Durable event ingestion: `event_inbox`, idempotency, обработка дублей/конфликтов revision, лимиты батча, quarantine невалидных событий.
- Projections: orders/order_revisions/refunds/payments/financial_transactions из нормализованных событий.
- Domain outbox: dispatcher, exponential backoff, sweeper для истёкших lease, bounded loop команда `outbox:dispatch`.
- Integration lifecycle: list/detail/revoke endpoints, audit log (`audit_log`).
- Payment/refund allocation foundation: таблицы `payment_allocations`/`refund_allocations`, `PaymentAllocationService` с блокировками строк и проверкой сумм.
- Allocation revoke/unlink: `PaymentAllocationService::revokeCaptureAllocation()`/`revokeRefundAllocation()` — reason обязателен, повторный revoke запрещён, revoke capture allocation блокируется активными refund allocations на неё, каждый revoke пишет `audit_log`.
- Reconciliation foundation: таблицы `reconciliation_runs`/`reconciliation_findings`, `OrderReconciliationService::evaluate()` — считает G/C/RW/RP по одному заказу и пишет findings по правилам `MONEY_UNSUPPORTED`, `MONEY_CAPTURE_MISSING`, `MONEY_CAPTURE_AMOUNT`, `MONEY_REFUND_MISSING`, `MONEY_REFUND_EXTRA` с grace-окнами (30/60 мин) и нулевым tolerance. Синхронный, по одному заказу — без scheduler/nightly sweep/dirty-order coalescing.

114 тестов, 369 assertions проходят (`php artisan test` на PHP 8.4). Миграция reconciliation-таблиц проверена и накатана на реальной PostgreSQL 18 в локальном Docker.

Известные зафиксированные отклонения от спеки — `docs/adr/0001-bootstrap-deviations.md` (S3Mock вместо MinIO, нет Sanctum, упрощённые FK в projection-таблицах, неполная JSON Schema валидация ingest, store verification без реальной внешней проверки, pairing без полного anti-abuse).

Пока пустые заглушки: `apps/frontend` (Vue), `apps/browser-worker` (Node/Playwright), `plugins/woocommerce-watchdog`. `docs/compatibility.md` не заполнен — D0 compatibility spike (точные версии WP/WooCommerce/Stripe gateway/Playwright) не проводился.

## Следующий шаг

По "Not Done In This Step" из `docs/implementation-step-32-checklist.md`:

1. Публичный reconciliation/findings API (`POST /stores/{id}/reconciliations`, `GET /stores/{id}/findings` из `spec/contracts/ui-api-catalog.md` раздел 5) — сервисный слой готов, нужны routes/controllers/DTO. Это удобно сделать вместе с manual allocation API (тот же раздел), который тоже пока без HTTP.
2. Оставшиеся rule codes: `MONEY_PAYMENT_WITHOUT_ORDER`, `MONEY_MULTIPLE_CAPTURES`, `MONEY_CURRENCY_MISMATCH`, `MONEY_ORDER_CHANGED` — требуют сканирования по store/payment, а не по одному заказу (unmatched payments, order-revision diff).
3. Dirty-order coalescing (30с), пересчёт по grace-дедлайну и nightly sweep (90 дней) — сейчас только on-demand по одному заказу синхронно.
4. `rule_configs`: версионируемые tolerance/grace вместо текущих constants в сервисе.
5. Автоматический matcher (раздел 13 ТЗ) сверх текущих ручных allocation primitives.
6. Nightly allocation audit.
7. Incident engine поверх findings (критичность, корреляция, авто-resolve) — сейчас только finding, без инцидентов/уведомлений.

Параллельно остаются открытыми более ранние gaps из ADR 0001 (Sanctum, DNS/connector верификация домена, полная JSON Schema валидация) — не блокируют текущий слайс, но нужны до P0/P1 acceptance.

## Как возобновить работу

1. Прочитать этот файл и `docs/progress.md` (хвост, последние 2–3 шага).
2. Проверить `git log --oneline -10` и `git status`.
3. Перед новым шагом свериться с `AGENTS.md` и соответствующими разделами `spec/Business-Watchdog-TZ.md`.
4. После выполнения шага — обновить и этот файл, и `docs/progress.md`, и при необходимости `docs/adr/`.
