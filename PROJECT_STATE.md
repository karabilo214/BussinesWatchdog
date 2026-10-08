# Текущее состояние проекта

Этот файл — быстрая точка восстановления контекста в начале новой сессии. Подробная история по шагам — в `docs/progress.md`. Правила работы — в `CLAUDE.md` и `AGENTS.md`.

## Обновлено

2026-10-08 (Step 31)

## Что это за проект

Business Watchdog — SaaS для обнаружения финансовых расхождений и поломок checkout в WooCommerce-магазинах. Laravel модульный монолит + WooCommerce-плагин + Stripe read-only + изолированный Playwright-воркер. ИИ не реализуется. Полное ТЗ — `spec/Business-Watchdog-TZ.md`, порядок чтения — `AGENTS.md`.

## Этап разработки

Репозиторий сейчас в фазе **D1–D4 бэкенд-слайсов** (по внутренней нумерации шагов `docs/progress.md`, не всегда совпадает 1:1 с разделом 36 ТЗ). P0 pilot пока не достигнут — минимальный кабинет, браузерные проверки, incident engine и email ещё не реализованы.

Реализовано (backend, `apps/backend`, до Step 31 включительно):

- Локальный инфраструктурный bootstrap (Docker Compose: PostgreSQL 18, Redis, S3Mock вместо MinIO, Mailpit).
- Auth/tenancy: регистрация, сессии (пока без Sanctum), membership/roles.
- Store pairing: pairing code, HMAC-подписанные credentials, store verification (challenge создаётся, но внешняя проверка DNS/connector ещё не выполняется).
- Durable event ingestion: `event_inbox`, idempotency, обработка дублей/конфликтов revision, лимиты батча, quarantine невалидных событий.
- Projections: orders/order_revisions/refunds/payments/financial_transactions из нормализованных событий.
- Domain outbox: dispatcher, exponential backoff, sweeper для истёкших lease, bounded loop команда `outbox:dispatch`.
- Integration lifecycle: list/detail/revoke endpoints, audit log (`audit_log`).
- Payment/refund allocation foundation: таблицы `payment_allocations`/`refund_allocations`, `PaymentAllocationService` с блокировками строк и проверкой сумм.
- Allocation revoke/unlink: `PaymentAllocationService::revokeCaptureAllocation()`/`revokeRefundAllocation()` — reason обязателен, повторный revoke запрещён, revoke capture allocation блокируется активными refund allocations на неё, каждый revoke пишет `audit_log`.

104 теста, 337 assertions проходят (`php artisan test` на PHP 8.4).

Известные зафиксированные отклонения от спеки — `docs/adr/0001-bootstrap-deviations.md` (S3Mock вместо MinIO, нет Sanctum, упрощённые FK в projection-таблицах, неполная JSON Schema валидация ingest, store verification без реальной внешней проверки, pairing без полного anti-abuse).

Пока пустые заглушки: `apps/frontend` (Vue), `apps/browser-worker` (Node/Playwright), `plugins/woocommerce-watchdog`. `docs/compatibility.md` не заполнен — D0 compatibility spike (точные версии WP/WooCommerce/Stripe gateway/Playwright) не проводился.

## Следующий шаг

По "Not Done In This Step" из `docs/implementation-step-31-checklist.md`, логичное продолжение D4-слайса сверки:

1. Reconciliation runs и findings (`reconciliation_runs`, `reconciliation_findings`) — правила из раздела 14 ТЗ, теперь безопасно: allocation-связи можно создавать и безопасно пересматривать (revoke/unlink готов).
2. Публичный/manual allocation API (`POST /payment-allocations`, `POST /refund-allocations` и их `/revoke` из `spec/contracts/ui-api-catalog.md` раздел 5) — сервисный слой готов, нужны только routes/controllers/DTO.
3. Автоматический matcher (раздел 13 ТЗ) сверх текущих ручных allocation primitives.
4. Nightly allocation audit.
5. При добавлении reconciliation runs — решить, нужен ли `dirty_order`-outbox topic для пересчёта findings при revoke (сейчас сознательно не добавлен, так как потребителя для него ещё нет).

Параллельно остаются открытыми более ранние gaps из ADR 0001 (Sanctum, DNS/connector верификация домена, полная JSON Schema валидация) — не блокируют текущий слайс, но нужны до P0/P1 acceptance.

## Как возобновить работу

1. Прочитать этот файл и `docs/progress.md` (хвост, последние 2–3 шага).
2. Проверить `git log --oneline -10` и `git status`.
3. Перед новым шагом свериться с `AGENTS.md` и соответствующими разделами `spec/Business-Watchdog-TZ.md`.
4. После выполнения шага — обновить и этот файл, и `docs/progress.md`, и при необходимости `docs/adr/`.
