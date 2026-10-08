# Текущее состояние проекта

Этот файл — быстрая точка восстановления контекста в начале новой сессии. Подробная история по шагам — в `docs/progress.md`. Правила работы — в `CLAUDE.md` и `AGENTS.md`.

## Обновлено

2026-10-08 (Step 34)

## Что это за проект

Business Watchdog — SaaS для обнаружения финансовых расхождений и поломок checkout в WooCommerce-магазинах. Laravel модульный монолит + WooCommerce-плагин + Stripe read-only + изолированный Playwright-воркер. ИИ не реализуется. Полное ТЗ — `spec/Business-Watchdog-TZ.md`, порядок чтения — `AGENTS.md`.

## Этап разработки

Репозиторий сейчас в фазе **D1–D4 бэкенд-слайсов** (по внутренней нумерации шагов `docs/progress.md`, не всегда совпадает 1:1 с разделом 36 ТЗ). P0 pilot пока не достигнут — минимальный кабинет, браузерные проверки, incident engine и email ещё не реализованы.

Реализовано (backend, `apps/backend`, до Step 34 включительно):

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

147 тестов, 453 assertions проходят (`php artisan test` на PHP 8.4). Все новые миграции (reconciliation, idempotency_keys) проверены и накатаны на реальной PostgreSQL 18 в локальном Docker.

Известные зафиксированные отклонения от спеки — `docs/adr/0001-bootstrap-deviations.md` (S3Mock вместо MinIO, нет Sanctum, упрощённые FK в projection-таблицах, неполная JSON Schema валидация ingest, store verification без реальной внешней проверки, pairing без полного anti-abuse).

Пока пустые заглушки: `apps/frontend` (Vue), `apps/browser-worker` (Node/Playwright), `plugins/woocommerce-watchdog`. `docs/compatibility.md` не заполнен — D0 compatibility spike (точные версии WP/WooCommerce/Stripe gateway/Playwright) не проводился.

## Следующий шаг

По "Not Done In This Step" из `docs/implementation-step-34-checklist.md`:

1. `GET /orders/{id}`, `GET /payments/{id}`, `GET /stores/{id}/unmatched-payments` — детальные карточки заказа/платежа и candidate-suggestion для orphan-captures (сознательно не делал, т.к. это не allocation/reconciliation).
2. Dirty-order coalescing (30с), пересчёт по grace-дедлайну и nightly sweep (90 дней) — сейчас только on-demand синхронно (по order_ids или store-wide scan).
3. `rule_configs`: версионируемые tolerance/grace вместо текущих constants в сервисах.
4. Windowed bulk reconciliation trigger (`from`/`to` вместо только `order_ids`), настоящий `dry_run`, подписанный cursor, rate limiting на `POST /stores/{id}/reconciliations`, очистка просроченных `idempotency_keys`.
5. Автоматический matcher (раздел 13 ТЗ) сверх текущих ручных allocation primitives.
6. Nightly allocation audit.
7. Incident engine поверх findings (критичность, корреляция, авто-resolve) — сейчас только finding, без инцидентов/уведомлений.

Параллельно остаются открытыми более ранние gaps из ADR 0001 (Sanctum, DNS/connector верификация домена, полная JSON Schema валидация) — не блокируют текущий слайс, но нужны до P0/P1 acceptance.

## Как возобновить работу

1. Прочитать этот файл и `docs/progress.md` (хвост, последние 2–3 шага).
2. Проверить `git log --oneline -10` и `git status`.
3. Перед новым шагом свериться с `AGENTS.md` и соответствующими разделами `spec/Business-Watchdog-TZ.md`.
4. После выполнения шага — обновить и этот файл, и `docs/progress.md`, и при необходимости `docs/adr/`.
