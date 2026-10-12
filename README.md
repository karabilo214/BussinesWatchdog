# Business Watchdog

SaaS для обнаружения денежных расхождений и поломок оформления заказа в интернет-магазинах (WooCommerce, Shopware 6). Laravel-бэкенд, кабинет клиента и панель владельца на Vue, WooCommerce-плагин, Shopware App и изолированный Playwright-воркер проверок.

Текущее состояние — `PROJECT_STATE.md`, история шагов — `docs/progress.md`, решения — `docs/adr/`, ТЗ — `spec/`.

## Что где лежит

| Путь | Что это |
|---|---|
| `apps/backend` | Laravel: API кабинета (`/api/v1`), API панели владельца (`/platform-api/v1`), фоновые команды |
| `apps/frontend/customer` | Кабинет клиента (`/app`) |
| `apps/frontend/control` | Панель владельца сервиса (`/owner`) |
| `apps/frontend/public` | Публичный сайт |
| `apps/browser-worker` | Воркер браузерных проверок (Node + Playwright) |
| `plugins/woocommerce-watchdog` | WooCommerce-плагин |
| `plugins/shopware-app` | Тесты Shopware App (архив приложения выдаёт кабинет для каждого магазина) |
| `contracts` | Рабочие контракты API (`openapi.yaml` сверяется тестом с маршрутами) |
| `infra` | Docker, nginx, egress-прокси, скрипты |

Все команды `php artisan …` ниже выполняются в `apps/backend` (локально — PHP 8.4: `/opt/homebrew/opt/php@8.4/bin/php artisan …`; в Docker — `docker compose exec backend php artisan …`).

## Выпуск релиза: что сделать по порядку

### 1. Переменные окружения (`apps/backend/.env`)

Образец — `apps/backend/.env.example`. Обязательно для боевого запуска:

- **Приложение:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` (HTTPS-адрес сервиса), `APP_KEY` (`php artisan key:generate --show`).
- **База и Redis:** `DB_*` (PostgreSQL 18), `REDIS_*`.
- **Сессии:** `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS` (домен кабинета).
- **Ключи шифрования секретов:** `WATCHDOG_KEYRING_CURRENT=1`, `WATCHDOG_KEYRING_V1=base64:…` (32 случайных байта: `openssl rand -base64 32`, перед значением `base64:`). Ключ нельзя терять — без него не расшифровать ключи интеграций и MFA. Ротация — см. «Обслуживание».
- **Почта:** `MAIL_*`, `MAIL_FROM_ADDRESS`, DKIM (`MAIL_DKIM_*`). Проверка — `php artisan mail:auth-check --strict`.
- **Хранилище файлов (S3):** `AWS_*` — скриншоты проверок и экспорты хранятся приватно.
- **Telegram:** `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`, при необходимости `TELEGRAM_WEBHOOK_URL`.
- **Оплата подписки (Paddle):** `PADDLE_ENVIRONMENT=production` (иначе sandbox), `PADDLE_API_KEY`, `PADDLE_CLIENT_TOKEN`, `PADDLE_WEBHOOK_SECRET`, `PADDLE_ACCOUNT_REF`.
- **Ограничения тарифов:** `WATCHDOG_BILLING_ENFORCEMENT=enforce` включает лимиты тарифов; `pilot` (по умолчанию) — всё доступно всем.
- **Панель владельца (по желанию):** `WATCHDOG_PLATFORM_IDLE_MINUTES` (30), `WATCHDOG_PLATFORM_ABSOLUTE_HOURS` (8), `WATCHDOG_PLATFORM_LOGIN_PER_MINUTE` (5).

### 2. База данных

```sh
php artisan migrate --force
```

### 3. Фоновые задачи

Должен работать планировщик Laravel — сервис `scheduler` в Docker Compose или cron раз в минуту:

```sh
* * * * * cd /path/to/apps/backend && php artisan schedule:run >> /dev/null 2>&1
```

Он сам запускает: доставку событий (`outbox:dispatch`, 10 с), пересчёт сверки (`reconciliation:process-dirty`, 30 с), уведомления (`notifications:deliver`, 30 с; `notifications:remind`, час), ночную сверку (02:30), проверку подтверждения магазинов и свежести коннекторов (минута), планирование браузерных проверок (минута), синхронизацию Stripe / PayPal / WooCommerce REST / Shopware, события Paddle (`billing:paddle-events`, минута; `billing:process-events`, 30 с), очистку старых скриншотов и экспортов (ежедневно). Вручную их запускать не нужно.

### 4. Первый владелец сервиса (вход в `/owner`)

```sh
php artisan platform:create-owner you@example.com --name="Your Name"
```

Команда спросит пароль (скрыто, два раза), покажет ссылку `otpauth://` и секрет для приложения-аутентификатора, попросит код из приложения и один раз выведет 10 резервных кодов — сохраните их офлайн. Если адрес уже зарегистрирован в кабинете клиента, команда предложит сделать эту учётную запись владельцем. Вход: `https://<домен панели>/owner` — e-mail, пароль, код из приложения.

### 5. Воркер браузерных проверок

```sh
php artisan browser:worker-create worker-1            # токен выводится один раз
php artisan browser:worker-create worker-1 --rotate   # замена токена
```

Воркеру (`apps/browser-worker`) задаются `BW_WORKER_TOKEN` (токен из команды), `BW_API_URL` (адрес бэкенда) и `BW_EGRESS_PROXY` (egress-прокси `infra/docker/egress`); доступа к БД и Redis у воркера нет.

### 6. Telegram

```sh
php artisan telegram:webhook set     # регистрирует webhook на APP_URL/api/v1/webhooks/telegram/updates
php artisan telegram:webhook info    # проверить
```

### 7. Paddle (оплата подписки)

- В кабинете Paddle: ссылка оплаты по умолчанию (Checkout settings), домен сервиса одобрен, webhook на `https://<APP_URL>/api/v1/webhooks/paddle` с секретом из `PADDLE_WEBHOOK_SECRET`.
- Тарифы, цены, периоды оплаты, лимиты и пробный период создаются в панели владельца: `/owner/plans` → «Новый тариф» → заполнить черновик → добавить цены → «Опубликовать» (причина + код из приложения). При публикации товар и цены создаются в Paddle текущего режима (`PADDLE_ENVIRONMENT`); клиентам видны только опубликованные версии.
- `php artisan billing:paddle-catalog` — дозаводит в Paddle цены, которые по какой-то причине не создались (обычно не нужна).
- Только для sandbox: `php artisan billing:seed-placeholder-plans --test-prices` — черновые тарифы Free / Start / Pro с тестовыми ценами, опубликованные для проверки покупки.

### 8. Почта

```sh
php artisan mail:auth-check --strict   # DKIM, SPF, DMARC для боевой отправки
```

### 9. WooCommerce-плагин (ZIP для магазинов)

```sh
plugins/woocommerce-watchdog/bin/build-release.sh plugins/woocommerce-watchdog dist/plugin
plugins/woocommerce-watchdog/tests/matrix/release-checks.sh    # проверки выпуска на собранном ZIP
```

Shopware App отдельно не собирается: кабинет выдаёт архив приложения для каждого магазина.

### 10. Фронтенды

```sh
cd apps/frontend
npm ci
npm run build -w @bw/customer   # кабинет клиента → customer/dist (/app)
npm run build -w @bw/control    # панель владельца → control/dist (/owner, отдельный домен по ТЗ)
npm run build -w @bw/public     # публичный сайт
```

Внутри скриптов npm использовать только `node "$npm_execpath"`, не голый `npm` (см. `docs/progress.md`, Step 57).

## Обслуживание

| Команда | Когда |
|---|---|
| `php artisan security:reencrypt-secrets` | После добавления нового ключа шифрования (`WATCHDOG_KEYRING_KEYS="2:base64:…"`, затем `WATCHDOG_KEYRING_CURRENT=2`): перешифровать ключи интеграций, адреса уведомлений и секреты аутентификаторов. Старый ключ убирать только после неё |
| `php artisan platform:create-owner <email>` | Добавить ещё одного владельца сервиса или восстановить доступ |
| `php artisan browser:worker-create <name> --rotate` | Сменить токен воркера |
| `php artisan telegram:webhook info\|set\|delete` | Проверить или перерегистрировать webhook Telegram |
| `php artisan billing:paddle-events` / `billing:process-events` | Вручную дочитать события Paddle (обычно делает планировщик) |
| `php artisan stripe:sync`, `paypal:sync`, `woocommerce-api:sync`, `shopware:sync` (`--audit`) | Внеочередная синхронизация (обычно делает планировщик) |
| `php artisan reconciliation:process-dirty` / `reconciliation:nightly-sweep` | Внеочередной пересчёт сверки |
| `php artisan artifacts:purge` / `exports:purge` | Внеочередная очистка просроченных файлов |

## Локальная разработка и тесты

```sh
make setup && make up               # PostgreSQL, Redis, S3Mock, Mailpit, бэкенд, планировщик
make backend-test                   # тесты бэкенда (SQLite)
make backend-test-pgsql             # тесты бэкенда на PostgreSQL 18
make frontend-install
make frontend-check                 # типы, тесты и сборка фронтендов
make frontend-smoke                 # кабинет в Chromium против настоящего бэкенда
make frontend-dev                   # кабинет на :5173; make frontend-dev-control — панель на :5174
make worker-test                    # тесты воркера
make egress-test                    # проверка egress-прокси
```

Живые проверки (нужен интернет и ключи sandbox):

- `apps/frontend/customer/tests/e2e/run-billing-live.sh` — покупка подписки через sandbox Paddle тестовой картой.
- `apps/frontend/control/tests/e2e/run-live.sh login` — вход в панель владельца с настоящим кодом TOTP; `run-live.sh plans` — создание, цены, публикация в sandbox Paddle и снятие тарифа с продажи.
- `plugins/woocommerce-watchdog/tests/matrix/*.sh`, `plugins/shopware-app/tests/**` — матрица версий и сквозные прогоны с настоящими магазинами и провайдерами.
