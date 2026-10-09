# Business Watchdog спецификация

Версия 1.1, 8 октября 2026. Подготовлено для владельца проекта и Codex. **Это пакет проектной документации, не готовое приложение.**

Основной файл — `Business-Watchdog-TZ.md`. Markdown выбран потому, что сохраняет код, таблицы и точные имена полей, читается в обычном редакторе и подходит помощнику Codex. Откройте весь каталог в редакторе либо сначала прочитайте основное ТЗ.

В комплекте:

- `database/schema.sql` — 41 доменная таблица, FK, CHECK, unique constraints и indexes для PostgreSQL18.
- `contracts/openapi.yaml` — основные внешние и внутренние API.
- `contracts/event.schema.json` — versioned JSON Schema нормализованных событий.
- `contracts/ui-api-catalog.md` — уточнения DTO остальных интерфейсов и правил API.
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
- `database/platform-billing-extension.sql` —21 дополнительная таблица; вместе с base41 всего62.
- `contracts/platform-billing-api.yaml` —20 дополнительных core операций.
- `examples/saas-billing-cases.json` —16 сценариев собственного биллинга.
- `PANELS-ACCEPTANCE.md` — приёмка дополнения1.1.

Дополнение1.2:

- `Business-Watchdog-Market-Research-and-Product-Addendum-RU.md` — исследование рынка и контроль исполнения оплаченных заказов (обязательства заказа, адаптеры Sendcloud/Postmark, модель данных, API, сценарии). Согласование с принятыми решениями и открытые вопросы — раздел 43 основного ТЗ.

Сначала base SQL, затем extension. При противоречии краткого billing описания1.0 и подробного дополнения1.1 применять новые billing contracts; мониторинг магазинов не изменяет финансовую семантику. Platform owner/staff и tenant owner/admin — разные роли и sessions. Реального UI/backend в пакете пока нет.

Порядок: ТЗ → схема/контракты → примеры → acceptance → инструкция Codex. При реализации перенести документацию в repository, оформить D0 ADR и начать D1. Миграции Laravel должны сохранять смысл reference DDL, включая composite tenant FK.

Проектные defaults по interval/grace/retention являются предложенными настройками пилота и требуют калибровки. Публичные prices, jurisdiction, billing provider и точная Woo gateway version пока не утверждены. ИИ исключён из реализации; предусмотрена только возможность отдельного будущего расширения.

Не используйте `.invalid` hostname и `pi_demo_*` IDs для реальных запросов: это примеры. SQL предназначен для пустой БД и не содержит production authentication/RLS bootstrap. OpenAPI core не заменяет документацию provider; DTO каталога нужно перенести в OpenAPI до реализации соответствующего endpoint. Проверка синтаксиса документа не равна успешному запуску SaaS.
