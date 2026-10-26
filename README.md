# Business Watchdog

SaaS для обнаружения денежных расхождений и поломок оформления заказа в интернет-магазинах (WooCommerce, Shopware 6). Laravel-бэкенд, кабинет клиента и панель владельца на Vue, WooCommerce-плагин, Shopware App и изолированный Playwright-воркер проверок.

Текущее состояние — `PROJECT_STATE.md`, история шагов — `docs/progress.md`, решения — `docs/adr/`, ТЗ — `spec/`.

Срез на 2026-10-26: реализация доведена до Step 272; Steps 262–272 ещё не выложены. Плагин WooCommerce 0.3.3 отправлен в WordPress.org и ожидает проверки; 0.3.4 — отдельный локальный кандидат. Точные версии, SHA-256 и порядок выдачи ZIP — [docs/ops/integrations.md](docs/ops/integrations.md). Git push отправляет исходники и запускает CI; выкладка SaaS и публикация плагина выполняются отдельно.

## Что где лежит

| Путь | Что это |
|---|---|
| `apps/backend` | Laravel: API кабинета (`/api/v1`), API панели владельца (`/platform-api/v1`), фоновые команды |
| `apps/frontend/customer` | Кабинет клиента (`/app`) |
| `apps/frontend/control` | Панель владельца сервиса (`/owner`) |
| `apps/frontend/public` | Стили публичного сайта (сами страницы — `apps/backend/resources/views/site`) |
| `apps/browser-worker` | Воркер браузерных проверок (Node + Playwright) |
| `plugins/woocommerce-watchdog` | WooCommerce-плагин |
| `plugins/shopware-app` | Shopware App: `src/` (ссылка на `apps/backend/resources/shopware-app` — скрипт наблюдения и шаблоны витрины) и тесты; с версии 1.5.0 кабинет выдаёт один одобренный архив для всех магазинов |
| `contracts` | Рабочие контракты API (`openapi.yaml` сверяется тестом с маршрутами) |
| `infra` | Docker, nginx, egress-прокси, скрипты |

## Документация по запуску и обслуживанию

| Файл | Что там |
|---|---|
| [docs/ops/setup.md](docs/ops/setup.md) | Выпуск релиза: переменные окружения, база, фоновые задачи и обработчики, первый владелец, сборка фронтендов |
| [docs/ops/production-server.md](docs/ops/production-server.md) | Боевой сервер: выкладка через git, применение `.env`, команды artisan на сервере, браузерный воркер |
| [docs/ops/backups.md](docs/ops/backups.md) | Бэкапы базы и почты: расписание, хранение, внешняя копия, восстановление |
| [docs/ops/mail.md](docs/ops/mail.md) | Почта: DKIM/SPF/DMARC, свой почтовый сервер, ящики и псевдонимы, fail2ban |
| [docs/ops/billing.md](docs/ops/billing.md) | Paddle: ключи и webhook, ссылка оплаты, тарифы, оплаты, возвраты, скидки |
| [docs/ops/site.md](docs/ops/site.md) | Публичный сайт: страницы, регистрация, проверка магазина, юридические страницы |
| [docs/ops/test-shops.md](docs/ops/test-shops.md) | Тестовые магазины WooCommerce и Shopware на боевом сервере: версии, пароли, пересоздание |
| [docs/ops/integrations.md](docs/ops/integrations.md) | Воркер браузерных проверок, Telegram, WooCommerce-плагин, Shopware App |
| [docs/ops/maintenance.md](docs/ops/maintenance.md) | Команды обслуживания: ключи шифрования, сотрудники, внеочередные синхронизации и очистки |
| [docs/ops/development.md](docs/ops/development.md) | Локальная разработка, тесты, живые проверки; CI — `.github/workflows/ci.yml` |
