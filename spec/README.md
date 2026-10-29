# Business Watchdog спецификация

Базовая версия 1.1 от 8 октября 2026 года. Это проектная спецификация внутри репозитория с работающей реализацией. Текущий статус кода, боевого выпуска и открытых работ — в `../PROJECT_STATE.md` и `../docs/readiness.md`; история — в `../docs/progress.md`. Даты и proposed defaults исходного ТЗ не означают актуальное состояние сервиса.

Основной файл — `Business-Watchdog-TZ.md`. Markdown выбран потому, что сохраняет код, таблицы и точные имена полей, читается в обычном редакторе и подходит помощнику Codex. Откройте весь каталог в редакторе либо сначала прочитайте основное ТЗ.

В комплекте:

- `database/schema.sql` — 47 reference-таблиц, FK, CHECK, unique constraints и indexes для PostgreSQL 18.
- `../contracts/openapi.yaml` — основные внешние и внутренние API (рабочий контракт живёт только в корневом `contracts/`).
- `../contracts/event.schema.json` — основной versioned JSON Schema нормализованных событий; runtime-копия в `../apps/backend/resources/contracts/event.schema.json` синхронизируется с ним и проверяется `IngestEventsApiTest`.
- `../contracts/ui-api-catalog.md` — рабочие уточнения DTO остальных интерфейсов и правил API; `spec/contracts/ui-api-catalog.md` — исторический снимок.
- `examples/ingest-batch.json` — synthetic Woo snapshot batch.
- `examples/reconciliation-cases.json` — 24 финансовых примера.
- `ACCEPTANCE.md` — 50 сценариев приёмки с traceability.
- `CODEX-IMPLEMENTATION.md` — правила работы и первый implementation slice.
- `VALIDATION.md` — фактически выполненные проверки пакета и ограничения.

Дополнение1.1:

- `panels/OWNER-PANEL.md` — управление владельца SaaS, бизнес-показатели, клиенты, тарифы и сотрудники.
- `panels/ADMIN-PANEL.md` — внутренняя админка сотрудников, очереди, подключения, проверки и поддержка.
- `panels/CLIENT-PANELS.md` — кабинеты владельца и администратора организации клиента.
- `panels/PUBLIC-SUBSCRIPTION-FRONTEND.md` — сайт, тарифы, регистрация, hosted checkout, продление и отмена подписки нашей услуги.
- `panels/ADMIN-BACKEND.md` — backend, права, lifecycle биллинга, API и эксплуатация.
- `database/platform-billing-extension.sql` — 23 дополнительные reference-таблицы; вместе с base — 70.
- `../contracts/platform-billing-api.yaml` — 25 операций дополнения.
- `examples/saas-billing-cases.json` —16 сценариев собственного биллинга.
- `PANELS-ACCEPTANCE.md` — приёмка дополнения1.1.

Дополнение1.2:

- `Business-Watchdog-Market-Research-and-Product-Addendum-RU.md` — исследование рынка и контроль исполнения оплаченных заказов (обязательства заказа, адаптеры Sendcloud/Postmark, модель данных, API, сценарии). Согласование с принятыми решениями и открытые вопросы — раздел 43 основного ТЗ.

Reference DDL читается сначала как base SQL, затем как extension; фактическая схема приложения развивается через Laravel migrations в `../apps/backend/database/migrations`. При противоречии краткого billing описания 1.0 и подробного дополнения 1.1 применять новые billing contracts; мониторинг магазинов не изменяет финансовую семантику. Platform owner/staff и tenant owner/admin — разные роли и sessions. Рабочие API-контракты находятся только в корневом `../contracts/`.

Порядок чтения: `../PROJECT_STATE.md` → ТЗ → схема/контракты → примеры → acceptance → нужные ADR и история шагов. D0 и D1 уже пройдены; новые миграции Laravel должны сохранять смысл reference DDL, включая composite tenant FK.

Проектные defaults по interval/grace/retention из исходного ТЗ не заменяют текущую конфигурацию и ADR. Paddle выбран для биллинга SaaS, тарифы опубликованы и применяются на боевом сервере; юридические тексты проверяет юрист владельца. Версии проверенных Woo gateway указаны в `../docs/compatibility.md`. ИИ исключён из реализации; предусмотрена только возможность отдельного будущего расширения.

Не используйте `.invalid` hostname и `pi_demo_*` IDs для реальных запросов: это примеры. SQL предназначен для пустой БД и не содержит production authentication/RLS bootstrap. OpenAPI core не заменяет документацию provider; DTO каталога нужно перенести в OpenAPI до реализации соответствующего endpoint. Проверка синтаксиса документа не равна успешному запуску SaaS.
