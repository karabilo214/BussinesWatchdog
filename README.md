# Business Watchdog

SaaS для обнаружения денежных расхождений и поломок оформления заказа в интернет-магазинах (WooCommerce, Shopware 6). Laravel-бэкенд, кабинет клиента и панель владельца на Vue, WooCommerce-плагин, Shopware App и изолированный Playwright-воркер проверок.

Текущее состояние — `PROJECT_STATE.md`, история шагов — `docs/progress.md`, решения — `docs/adr/`, ТЗ — `spec/`.

## Что где лежит

| Путь | Что это |
|---|---|
| `apps/backend` | Laravel: API кабинета (`/api/v1`), API панели владельца (`/platform-api/v1`), фоновые команды |
| `apps/frontend/customer` | Кабинет клиента (`/app`) |
| `apps/frontend/control` | Панель владельца сервиса (`/owner`) |
| `apps/frontend/public` | Стили публичного сайта (сами страницы — `apps/backend/resources/views/site`) |
| `apps/browser-worker` | Воркер браузерных проверок (Node + Playwright) |
| `plugins/woocommerce-watchdog` | WooCommerce-плагин |
| `plugins/shopware-app` | Shopware App: `src/` (ссылка на `apps/backend/resources/shopware-app` — скрипт наблюдения и шаблоны витрины) и тесты; архив с `manifest.xml` выдаёт кабинет для каждого магазина |
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
- **Версия релиза:** `WATCHDOG_RELEASE` — метка выпуска (например, `2026.10.20-1` или хеш коммита); показывается в админке в разделе «Сервис», по умолчанию `dev`.
- **Публичный сайт (по желанию):** `WATCHDOG_SITE_CONTACT_NOTIFY` — куда слать письма о новых обращениях (несколько адресов через запятую; пусто — всем активным владельцам сервиса), `WATCHDOG_SITE_OWNER_URL` (адрес панели для ссылки в письме, по умолчанию `APP_URL/owner`), `WATCHDOG_SITE_CONTACT_RETENTION_DAYS` (365 — через сколько дней после закрытия удалять обращение), `WATCHDOG_SITE_CONTACT_PER_HOUR` (5 сообщений в час с одного IP), `WATCHDOG_SITE_CACHE_SECONDS` (60 — сколько браузер и прокси держат страницу). Для публичной проверки магазина по URL: `WATCHDOG_PUBLIC_CHECK_CAPTCHA_TTL_MINUTES` (10), `WATCHDOG_PUBLIC_CHECK_HOST_COOLDOWN_DAYS` (30), `WATCHDOG_PUBLIC_CHECK_IP_COOLDOWN_DAYS` (10), `WATCHDOG_PUBLIC_CHECK_SUBMIT_ATTEMPTS_PER_HOUR` (5), `WATCHDOG_PUBLIC_CHECK_MAX_RUNNING` (1), `WATCHDOG_PUBLIC_CHECK_ARTIFACT_TTL_MINUTES` (5).

### 2. База данных

```sh
php artisan migrate --force
```

### 3. Фоновые задачи

Должен работать планировщик Laravel — сервис `scheduler` в Docker Compose или cron раз в минуту:

```sh
* * * * * cd /path/to/apps/backend && php artisan schedule:run >> /dev/null 2>&1
```

Он сам запускает: доставку событий (`outbox:dispatch`, 10 с), один проход сверки (`reconciliation:process-dirty`, 30 с; основную работу делают обработчики ниже), уведомления (`notifications:deliver`, 30 с; `notifications:remind`, час), ночную сверку (02:30: только заказы, результат которых мог измениться, плюс заказы «своей» ночи 30-дневного цикла — каждый заказ перепроверяется раз в 30 дней, ~1/30 окна за ночь), проверку подтверждения магазинов и свежести коннекторов (минута), «зависшие» попытки оплаты Shopware и WooCommerce REST API без результата 30 минут (`checkout:payment-attempts-stuck`, минута), правила наблюдения за страницей оформления Shopware и WooCommerce (`checkout:observation-evaluate`, 5 минут), планирование браузерных проверок (минута), раздачу заданий синхронизации Stripe / PayPal / WooCommerce REST / Shopware (сами синхронизации выполняют обработчики очереди), события Paddle (`billing:paddle-events`, минута; `billing:process-events`, 30 с), скидки следующего периода подписок (`billing:plan-discounts`, 15 мин), удаление истории старше срока хранения пакета + 30 дней (`billing:history-purge`, ежедневно 03:45 UTC), объём заказов магазинов по пакетам (`billing:order-volume`, ежедневно 04:10 UTC: среднее за три полных месяца, отметка и письмо владельцу при превышении лимита пакета), смены тарифа и отмены подписок (`billing:plan-changes`, минута: переход на более дешёвый тариф в начале нового периода, проверка изменений с неизвестным ответом Paddle, пересчёт скидок), очистку старых скриншотов и экспортов (ежедневно), письма о новых обращениях с сайта (`site:contact-notify`, минута), удаление старых закрытых обращений (`site:contact-purge`, ежедневно) и удаление публичных диагностик магазина через 5 минут (`site:public-checks-purge`, минута). Вручную их запускать не нужно.

Кроме планировщика **обязательно** должны работать обработчики (ADR 0040), иначе подключения не синхронизируются:

- **Синхронизация подключений** — очередь Redis (`QUEUE_CONNECTION=redis`), сколько угодно экземпляров:
  `php artisan queue:work redis --queue=sync --sleep=3 --max-time=3600 --timeout=840`
- **Сверка** — сколько угодно экземпляров: `php artisan reconciliation:work --max-time=3600`

Оба выходят сами раз в час (или по SIGTERM) — их перезапускает supervisor/systemd или Docker (`restart: unless-stopped`). В Docker Compose это сервисы `sync-worker` и `reconciliation-worker`; число экземпляров — `BW_SYNC_WORKERS` и `BW_RECONCILIATION_WORKERS` (по 2). Нужны PHP-расширения `pcntl` и `redis` (есть в образе `infra/docker/backend`). `REDIS_QUEUE_RETRY_AFTER` (960) должен быть больше `--timeout`.

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
- Доплата «частые проверки» (ADR 0041) — там же, в версии тарифа: «Интервал частых проверок, мин» (меньше интервала пакета) и цена вида «Частые проверки» для каждого периода и валюты. Клиент включает её галочкой при покупке или смене тарифа.
- Пакеты по объёму (ADR 0041) — там же, в версии тарифа: лимит «Заказов в месяц на магазин», «Льготный период при превышении объёма, дней» и «Цена индивидуально» (такой пакет публикуется без цен и на сайте показывается с кнопкой связи).
- Счета и оплаты (ADR 0043): клиент видит их в кабинете (Настройки → Подписка → «Счета и оплаты»: PDF счёта от Paddle, «Изменить способ оплаты»), владелец сервиса — в разделе «Оплаты» панели (`/owner/payments`: все платежи и итоги месяца по валютам — оплачено, налог, комиссия Paddle, остаётся нам). После выпуска этой версии один раз: `php artisan billing:paddle-transactions`.
- Возвраты (ADR 0044): в разделе «Оплаты» у оплаченного платежа кнопка «Вернуть» (владелец сервиса и сотрудники с ролью «биллинг») — сумма (по умолчанию весь остаток, с налогом), причина, по желанию «отменить подписку в конце оплаченного периода». Деньги уходят только после того, как владелец сервиса в списке «Возвраты» нажмёт «Подтвердить возврат» с причиной и кодом из приложения. Paddle может сначала проверить возврат сам («Проверяется Paddle»). Возвраты, сделанные вручную в кабинете Paddle, появляются в списке сами (webhook должен присылать события `adjustment.created` и `adjustment.updated`). Возвраты без ответа Paddle досверяет `billing:plan-changes` (планировщик, раз в минуту).
- Скидки — там же, вкладка «Скидки» (`/owner/discounts`): промокод, скидка выбранным организациям или акция для всех; процент или сумма; на один платёж, N периодов или навсегда; лимит использований, даты, тарифы. Создание, изменение и архив — с причиной и кодом из приложения; скидка сразу создаётся в Paddle и применяется только нашим сервером. Скидки складываются по очереди: акция → личная → промокод; перед каждым продлением `billing:plan-discounts` пересчитывает общую скидку следующего периода. Расшифровка по каждому платежу — вкладка «История скидок».
- `php artisan billing:paddle-catalog` — дозаводит в Paddle цены, которые по какой-то причине не создались (обычно не нужна).
- Только для sandbox: `php artisan billing:seed-placeholder-plans --test-prices` — черновые тарифы Free / Start / Pro с тестовыми ценами, опубликованные для проверки покупки.

### 8. Почта

Отправитель — `MAIL_FROM_ADDRESS`, адрес возвратов — `MAIL_RETURN_PATH` (домен тот же или поддомен/родитель домена отправителя; пусто — берётся домен отправителя). DKIM — один из двух вариантов:

- **Подписывает почтовый провайдер** (Postmark, SES и т. п.): в кабинете провайдера подтвердить домен, опубликовать его DKIM-записи, затем `MAIL_DKIM_PROVIDER_MANAGED=true`, `MAIL_DKIM_DOMAIN`, `MAIL_DKIM_SELECTOR` (селектор провайдера).
- **Подписывает сервис:** ключ `openssl genrsa -out dkim.pem 2048`, публичная часть для DNS — `openssl rsa -in dkim.pem -pubout | grep -v -- ----- | tr -d '\n'`. Задать `MAIL_DKIM_ENABLED=true`, `MAIL_DKIM_DOMAIN`, `MAIL_DKIM_SELECTOR` и `MAIL_DKIM_PRIVATE_KEY_PATH` (файл с правами 600) или `MAIL_DKIM_PRIVATE_KEY` (переносы строк как `\n`); `MAIL_DKIM_PASSPHRASE` — если ключ зашифрован. Если ключ не читается, письма уходят без подписи, в логе — `mail.dkim_signing_failed`.

DNS-записи (TXT):

- SPF на домене `MAIL_RETURN_PATH`: `v=spf1 include:<сервер провайдера> -all`;
- DKIM на `<селектор>._domainkey.<MAIL_DKIM_DOMAIN>`: `v=DKIM1; k=rsa; p=<публичный ключ>`;
- DMARC на `_dmarc.<домен отправителя>`: начать с `v=DMARC1; p=none; rua=mailto:<адрес отчётов>`, после проверки отчётов — `p=quarantine`.

```sh
php artisan mail:auth-check --strict   # SPF, DKIM (ключ читается и совпадает с DNS), DMARC, выравнивание доменов
```

В `production` команда без `--strict` тоже завершается ошибкой, если что-то не настроено.

### 9. WooCommerce-плагин (ZIP для магазинов)

```sh
plugins/woocommerce-watchdog/bin/build-release.sh plugins/woocommerce-watchdog apps/backend/storage/app/plugins
plugins/woocommerce-watchdog/tests/matrix/release-checks.sh    # проверки выпуска на собранном ZIP
```

ZIP кладётся в каталог `WATCHDOG_PLUGIN_DIR` (по умолчанию `apps/backend/storage/app/plugins`; в Docker — тот же каталог внутри контейнера бэкенда, он должен быть на постоянном томе). Кабинет показывает кнопку «Скачать плагин» с самой новой версией `business-watchdog-X.Y.Z.zip` из этого каталога; старые версии можно оставить или удалить.

Shopware App отдельно не собирается: кабинет выдаёт архив приложения для каждого магазина.

### 10. Фронтенды

```sh
cd apps/frontend
npm ci
npm run build -w @bw/customer   # кабинет клиента → customer/dist (/app)
npm run build -w @bw/control    # панель владельца → control/dist (/owner, отдельный домен по ТЗ)
npm run build -w @bw/public     # стили публичного сайта → apps/backend/public/site
```

Публичный сайт (`/`, `/ru|en|de/...`) отдаёт сам Laravel готовым HTML (SSR, Blade), Node на сервере не нужен. `@bw/public` собирает только его стили в `apps/backend/public/site` (папка не в git — собирать при каждом выпуске до запуска бэкенда). Без сборки сайт работает, но без оформления.

### 11. Публичный сайт: первое наполнение

В панели владельца `/owner/site`:

- **Главная, Тарифы, Связаться с нами** есть сразу. Пока для языка не опубликован свой текст, главная показывает встроенный текст, а тарифы и форма — встроенное вступление. Тарифы и цены берутся из `/owner/plans` (только опубликованные; в тестовом режиме Paddle — с пометкой «Тестовый режим», примеры Free / Start / Pro видны только в нём).
- **Новая страница:** «Новая страница» → раздел (для вложенной, например `legal`) → адрес (slug, задаётся один раз) → меню → «Создать». Затем на каждом языке: заголовок, описание для поисковиков, HTML → «Сохранить черновик» → «Опубликовать» (с причиной). Язык без опубликованного текста отвечает 404.
- Условия, гарантии, о компании: например `legal` (без текста, просто раздел) и вложенные `legal/terms`, `legal/privacy`, `about`. В настройках страницы укажите «Юридический документ»: «Условия использования», «Политика конфиденциальности» или «Условия подписки» — именно с этими страницами клиент соглашается при регистрации и покупке, ссылка на политику появляется под формой обратной связи.
- **Регистрация** — `/{язык}/signup` (кнопки «Регистрация» и «Выбрать тариф»). Пока страницы не отмечены как «Условия использования», «Политика конфиденциальности» и «Условия подписки» и не опубликованы, согласие не спрашивается — перед продажами отметьте их (ADR 0042). Лимит регистраций с одного IP — `WATCHDOG_RATE_SIGNUP_PER_HOUR` (3 в час).
- **Публичная проверка магазина** — `/{язык}/check-store`: одноразовая диагностика публичных страниц по HTTPS URL без регистрации и без подтверждения владения. Обязательна локальная image math CAPTCHA с шумом; один normalized host/origin проверяется раз в 30 дней, один IP — один сайт за 10 дней. Проверка может один раз безопасно добавить публичный товар в корзину, но не отправляет оформление заказа, оплату, логин или регистрацию. По публичным признакам показывает осторожную подсказку платформы (WooCommerce / Shopware / неизвестно). Скриншоты и диагностика удаляются через 5 минут и не являются мониторингом, financial coverage или доказательством успешной оплаты.
- **Обращения** из формы — `/owner/contacts`: статус «новое / в работе / закрыто» и внутренняя заметка; ответ — по ссылке на e-mail.

Внутри скриптов npm использовать только `node "$npm_execpath"`, не голый `npm` (см. `docs/progress.md`, Step 57).

## Обслуживание

| Команда | Когда |
|---|---|
| `php artisan security:reencrypt-secrets` | После добавления нового ключа шифрования (`WATCHDOG_KEYRING_KEYS="2:base64:…"`, затем `WATCHDOG_KEYRING_CURRENT=2`): перешифровать ключи интеграций, адреса уведомлений и секреты аутентификаторов. Старый ключ убирать только после неё |
| `php artisan platform:create-owner <email>` | Добавить ещё одного владельца сервиса или восстановить доступ |
| `php artisan platform:reset-mfa <email> --reason="…"` | Сбросить приложение-аутентификатор сотрудника сервиса (потерян телефон): старый код перестаёт работать, все сессии завершаются, сразу привязывается новый; записывается в аудит. Остальных сотрудников приглашают и отключают в админке, раздел «Сотрудники» |
| `php artisan browser:worker-create <name> --rotate` | Сменить токен воркера |
| `php artisan telegram:webhook info\|set\|delete` | Проверить или перерегистрировать webhook Telegram |
| `php artisan billing:paddle-events` / `billing:process-events` | Вручную дочитать события Paddle (обычно делает планировщик) |
| `php artisan billing:paddle-transactions` (`--tenant=<id>`) | Дозагрузить из Paddle все транзакции (счета и оплаты) подписок текущего режима — после первого выпуска этой версии или если события потерялись |
| `php artisan billing:history-purge` | Внеочередное удаление истории, вышедшей за срок хранения пакета более 30 дней назад (обычно делает планировщик) |
| `php artisan billing:order-volume` | Внеочередной пересчёт объёма заказов магазинов и проверка лимита «заказов в месяц» пакетов (обычно делает планировщик) |
| `php artisan billing:plan-discounts` | Внеочередной пересчёт скидок следующего периода подписок (обычно делает планировщик; повторяет неудачные попытки) |
| `php artisan stripe:sync`, `paypal:sync`, `woocommerce-api:sync`, `shopware:sync` (`--audit`) | Внеочередная синхронизация: ставит задания в очередь для обработчиков; `--inline` — выполнить прямо в этом процессе, по очереди, без обработчиков |
| `php artisan queue:failed` / `queue:retry all` | Посмотреть и повторить упавшие задания синхронизации |
| `php artisan reconciliation:work --stop-when-empty` | Разобрать очередь сверки до конца и выйти (например, после восстановления) |
| `php artisan reconciliation:process-dirty` / `reconciliation:nightly-sweep` | Внеочередной пересчёт сверки |
| `php artisan artifacts:purge` / `exports:purge` | Внеочередная очистка просроченных файлов |
| `php artisan checkout:observation-evaluate` | Внеочередная проверка правил наблюдения за оформлением заказа Shopware и WooCommerce: скрипт молчит, отправки заказа без заказов (обычно делает планировщик) |
| `php artisan checkout:payment-attempts-stuck` | Внеочередная отметка попыток оплаты Shopware и WooCommerce REST API без результата 30 минут (обычно делает планировщик) |
| `php artisan site:contact-notify` / `site:contact-purge` | Вручную отправить письма о новых обращениях с сайта / удалить закрытые обращения старше срока хранения (обычно делает планировщик) |
| `php artisan site:public-checks-purge` | Внеочередное удаление публичных диагностик магазина и их скриншотов старше 5 минут (обычно делает планировщик каждую минуту) |

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
make shopware-observation-e2e       # скрипт наблюдения App на настоящем Shopware 6.6/6.7 с настоящими покупателями (Docker, своя база bw_observation_e2e)
make woocommerce-observation-e2e     # скрипт наблюдения плагина WooCommerce с настоящими покупателями на матрице (Docker, своя база bw_wc_observation_e2e; TARGETS=latest — одна цель)
make woocommerce-rest-attempts-e2e   # попытки оплаты при подключении через REST API без плагина (Docker, своя база bw_wc_attempts_e2e)
make shopware-attempts-e2e          # наблюдение за оплатами на настоящем Shopware 6.6/6.7 (Docker, своя база bw_attempts_e2e)
make worker-e2e-shopware            # браузерные проверки на настоящем Shopware 6.6/6.7 (Docker, своя база bw_browser_e2e; IMAGES="dockware/play:6.7.2.2" — одна версия)
make egress-test                    # проверка egress-прокси
```

Живые проверки (нужен интернет и ключи sandbox):

- `apps/frontend/customer/tests/e2e/run-billing-live.sh` — покупка подписки через sandbox Paddle тестовой картой; `BW_PROMO=<код> BW_EXPECT_AFTER=<итог, например 20,16> run-billing-live.sh` — покупка с промокодом (и действующими акциями).
- `apps/frontend/control/tests/e2e/run-live.sh login` — вход в панель владельца с настоящим кодом TOTP; `run-live.sh plans` — создание, цены, публикация в sandbox Paddle и снятие тарифа с продажи; `run-live.sh discounts` — создание промокода (выводит `PROMO <код>`); `run-live.sh tenants` — поиск организации, продление пробного периода, приостановка и возобновление сервиса, аудит; `run-live.sh signup` — отметка страниц условий и политики в панели, регистрация на сайте с согласием; `run-live.sh site` — создание и публикация страницы, чтение её на сайте без JavaScript, отправка формы обратной связи, закрытие обращения в панели, удаление страницы.
- `plugins/woocommerce-watchdog/tests/matrix/*.sh`, `plugins/shopware-app/tests/**` — матрица версий и сквозные прогоны с настоящими магазинами и провайдерами.
