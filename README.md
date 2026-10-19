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
- **Панель владельца (по желанию):** `WATCHDOG_PLATFORM_IDLE_MINUTES` (30), `WATCHDOG_PLATFORM_ABSOLUTE_HOURS` (8), `WATCHDOG_PLATFORM_LOGIN_PER_MINUTE` (5), `WATCHDOG_PLATFORM_AUDIT_RETENTION_DAYS` (730 — через сколько дней удаляются записи аудита платформы, ADR 0053).
- **Окна перепроверки (по желанию, в днях, по умолчанию 90):** `WATCHDOG_STRIPE_AUDIT_DAYS`, `WATCHDOG_PAYPAL_AUDIT_DAYS`, `WATCHDOG_WOOCOMMERCE_API_AUDIT_DAYS`, `WATCHDOG_SHOPWARE_AUDIT_DAYS` — сколько дней назад ежедневная полная синхронизация (`--audit`) перечитывает источник; `WATCHDOG_RECONCILIATION_LOOKBACK_DAYS` — за сколько дней ночная сверка и перезапуск сверки магазина (после восстановления коннектора, подключения провайдера) берут заказы. Новые платежи и возвраты по старым заказам ловит обычный опрос независимо от этих окон.
- **Оповещения «Сервиса» (ADR 0051):** `WATCHDOG_SERVICE_ALERT_EMAIL` — адрес ответственного (несколько — через запятую; пусто — письма не отправляются), `WATCHDOG_SERVICE_ALERT_LOCALE` (`ru`, также `en`/`de`). Письмо приходит, когда общее состояние раздела «Сервис» становится «проблема», и ещё одно — когда оно восстанавливается; повторов на каждой проверке нет, неотправленное письмо повторяется через минуту.
- **Версия релиза:** `WATCHDOG_RELEASE` — метка выпуска (например, `2026.10.20-1` или хеш коммита); показывается в админке в разделе «Сервис», по умолчанию `dev`.
- **Публичный сайт (по желанию):** `WATCHDOG_SITE_CONTACT_NOTIFY` — куда слать письма о новых обращениях (несколько адресов через запятую; пусто — всем активным владельцам сервиса), `WATCHDOG_SITE_OWNER_URL` (адрес панели для ссылки в письме, по умолчанию `APP_URL/owner`), `WATCHDOG_SITE_CONTACT_RETENTION_DAYS` (365 — через сколько дней после закрытия удалять обращение), `WATCHDOG_SITE_CONTACT_PER_HOUR` (5 сообщений в час с одного IP), `WATCHDOG_SITE_CACHE_SECONDS` (60 — сколько браузер и прокси держат страницу). Для публичной проверки магазина по URL: `WATCHDOG_PUBLIC_CHECK_CAPTCHA_TTL_MINUTES` (10), `WATCHDOG_PUBLIC_CHECK_HOST_COOLDOWN_DAYS` (30), `WATCHDOG_PUBLIC_CHECK_IP_COOLDOWN_DAYS` (10), `WATCHDOG_PUBLIC_CHECK_SUBMIT_ATTEMPTS_PER_HOUR` (5), `WATCHDOG_PUBLIC_CHECK_MAX_RUNNING` (1), `WATCHDOG_PUBLIC_CHECK_ARTIFACT_TTL_MINUTES` (5). Файлы редактора страниц (ADR 0063): `WATCHDOG_SITE_MEDIA_TYPES` (`jpeg,png,webp`; добавьте `pdf`, чтобы разрешить PDF), `WATCHDOG_SITE_MEDIA_MAX_BYTES` (5 МБ), `WATCHDOG_SITE_MEDIA_MAX_SIDE` (6000 px), `WATCHDOG_SITE_MEDIA_MAX_PIXELS` (16 000 000), сжатие — `WATCHDOG_SITE_MEDIA_RESIZE_MAX_SIDE` (2560 px по длинной стороне, `0` — не уменьшать), `WATCHDOG_SITE_MEDIA_JPEG_QUALITY` и `WATCHDOG_SITE_MEDIA_WEBP_QUALITY` (82), `WATCHDOG_SITE_MEDIA_PNG_COMPRESSION` (9), хранилище — `WATCHDOG_SITE_MEDIA_DISK` (по умолчанию то же приватное S3, что и скриншоты); публичный сайт отдаёт файлы по адресу `/media/…`. Размер файла ограничен также PHP и nginx: в образе бэкенда `upload_max_filesize=32M`, `post_max_size=34M`, `memory_limit=256M` (`infra/docker/backend/php-uploads.ini`), в nginx `client_max_body_size 34m` (`infra/nginx/backend.conf`) — чтобы разрешить больше, поднимите их вместе с `WATCHDOG_SITE_MEDIA_MAX_BYTES`; при больших `MAX_SIDE`/`MAX_PIXELS` нужен и больший `memory_limit` (около 10 байт на пиксель). Образ бэкенда должен содержать расширения PHP `gd` (JPEG, PNG, WebP) и `exif` — в `infra/docker/backend/Dockerfile` они есть; после обновления образы нужно пересобрать (`docker compose build && docker compose up -d`).

### 2. База данных

```sh
php artisan migrate --force
```

### 3. Фоновые задачи

Должен работать планировщик Laravel — сервис `scheduler` в Docker Compose или cron раз в минуту:

```sh
* * * * * cd /path/to/apps/backend && php artisan schedule:run >> /dev/null 2>&1
```

Во время активного окна обслуживания (админка → «Настройки») плановые браузерные проверки, оценка свежести коннекторов, уведомления клиентам, «зависшие» попытки оплаты и наблюдение за оформлением ставятся на паузу автоматически (ADR 0054); биллинг, приём данных и сверка продолжают работать. Он сам запускает: доставку событий (`outbox:dispatch`, 10 с), один проход сверки (`reconciliation:process-dirty`, 30 с; основную работу делают обработчики ниже), уведомления (`notifications:deliver`, 30 с; `notifications:remind`, час), ночную сверку (02:30: только заказы, результат которых мог измениться, плюс заказы «своей» ночи 30-дневного цикла — каждый заказ перепроверяется раз в 30 дней, ~1/30 окна за ночь), проверку подтверждения магазинов и свежести коннекторов (минута), «зависшие» попытки оплаты Shopware и WooCommerce REST API без результата 30 минут (`checkout:payment-attempts-stuck`, минута), правила наблюдения за страницей оформления Shopware и WooCommerce (`checkout:observation-evaluate`, 5 минут), планирование браузерных проверок (минута), раздачу заданий синхронизации Stripe / PayPal / WooCommerce REST / Shopware (сами синхронизации выполняют обработчики очереди), события Paddle (`billing:paddle-events`, минута; `billing:process-events`, 30 с), скидки следующего периода подписок (`billing:plan-discounts`, 15 мин), удаление истории старше срока хранения пакета + 30 дней (`billing:history-purge`, ежедневно 03:45 UTC), объём заказов магазинов по пакетам (`billing:order-volume`, ежедневно 04:10 UTC: среднее за три полных месяца, отметка и письмо владельцу при превышении лимита пакета), смены тарифа и отмены подписок (`billing:plan-changes`, минута: переход на более дешёвый тариф в начале нового периода, проверка изменений с неизвестным ответом Paddle, пересчёт скидок), очистку старых скриншотов и экспортов (ежедневно), оповещение ответственного о проблеме в «Сервисе» и о восстановлении (`service:alerts`, минута), письма о новых обращениях с сайта (`site:contact-notify`, минута), удаление записей аудита платформы старше срока хранения (`platform:audit-purge`, ежедневно 03:50 UTC), удаление старых закрытых обращений (`site:contact-purge`, ежедневно) и удаление публичных диагностик магазина через 5 минут (`site:public-checks-purge`, минута). Вручную их запускать не нужно.

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

Shopware App отдельно не собирается: кабинет выдаёт архив приложения для каждого магазина. Новая версия App (`ShopwareApp::APP_VERSION`, файлы в `apps/backend/resources/shopware-app`) выходит вместе с бэкендом: магазины, где App уже подключено, увидят в кабинете «Скачать обновление приложения» (архив для того же подключения) и загрузят его в Shopware как обновление.

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

## Боевой сервер (business-watchdog.com)

Один сервер (Contabo Cloud VPS 8, Ubuntu 24.04, `167.86.85.23`): Docker Compose из `infra/production` — Caddy (HTTPS Let's Encrypt, сайт, `/app`, `/owner`), бэкенд, планировщик, обработчики очередей, PostgreSQL 18 и Redis без открытых наружу портов. Доступ — только по SSH-ключу (`~/.ssh/business_watchdog_ed25519`, в `~/.ssh/config` алиас `bw-prod`), файрвол ufw (22, 80, 443), fail2ban, автообновления безопасности.

**На сервере работаем только через git.** Рабочая копия — `/opt/business-watchdog/repo` (клон GitHub), секреты — только `/opt/business-watchdog/.env` (права 600, в git не попадает; сохраните копию `APP_KEY` и `WATCHDOG_KEYRING_V1` офлайн — без них не расшифровать данные). Руками на сервере файлы не правятся.

```sh
git push                                # выкладывается только запушенный коммит
infra/production/deploy.sh              # текущий HEAD: git checkout на сервере, сборка, миграции, перезапуск
infra/production/deploy.sh <commit>     # конкретный коммит (откат — то же самое с прошлым коммитом)
infra/production/deploy.sh init         # первый запуск на новом сервере: клон репозитория и .env с новыми секретами
```

Команды artisan на сервере:

```sh
ssh -t bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose exec -u www-data app php artisan <команда>'
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose logs -f --tail 100 app web scheduler'
```

**Бэкапы базы** — systemd-таймер `bw-backup.timer` (ставит `deploy.sh`), каждый день в 03:15 по Берлину: `pg_dump` в `/opt/business-watchdog/backups/db-<время UTC>.dump`, затем дамп восстанавливается во временный PostgreSQL — без успешного восстановления копия не считается; итог — в `backups/last-success`. Хранение (решение владельца): все копии текущей недели; от прошлых недель текущего месяца — последняя копия каждой недели; от прошлых месяцев — последняя копия каждого месяца, за `BW_BACKUP_KEEP_MONTHS` месяцев (12). Кроме того, каждая проверенная копия шифруется ключом `age` и уходит во внешнее хранилище — Contabo Object Storage (`https://eu2.contabostorage.com`, бакет `business-watchdog-backups`; доступ — `BW_BACKUP_S3_*` в `.env` сервера), там хранится по тому же правилу; итог — `backups/last-offsite`. На сервере только **открытый** ключ (`BW_BACKUP_AGE_RECIPIENT`); закрытый — у владельца офлайн (менеджер паролей + запасная копия). Без него внешние копии не расшифровать.

Восстановление внешней копии на любом компьютере:

```sh
rclone copy offsite:business-watchdog-backups/db-<время>.dump.age .     # или скачать в панели Contabo
age -d -i business-watchdog-backup.agekey -o db.dump db-<время>.dump.age
pg_restore --list db.dump                                               # проверить, затем pg_restore в новую базу
```

```sh
ssh bw-prod 'systemctl start bw-backup.service && journalctl -u bw-backup.service -n 5'   # бэкап сейчас
ssh bw-prod 'cat /opt/business-watchdog/backups/last-success; ls -lh /opt/business-watchdog/backups'
scp bw-prod:/opt/business-watchdog/backups/db-<время>.dump .                                # копия себе
# восстановление в работающую базу (остановить приложение, затем):
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose stop app scheduler sync-worker reconciliation-worker web \
  && docker compose exec -T postgres pg_restore -U bw_app -d business_watchdog --clean --if-exists --no-owner < /opt/business-watchdog/backups/db-<время>.dump \
  && docker compose up -d'
```

DNS у NIC.UA: `A` и `AAAA` для `@` и `www` на адреса сервера. Скриншоты, выгрузки и файлы сайта хранятся на диске сервера (том `storage`, `WATCHDOG_*_DISK=local`). Почта пока только пишется в лог (`MAIL_MAILER=log`) — до подключения SMTP-провайдера письма не уходят. Браузерный воркер запускается, когда в `.env` задан `BW_WORKER_TOKEN` (`php artisan browser:worker-create worker-1`).

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
| `php artisan platform:audit-purge` | Внеочередное удаление записей аудита платформы старше `WATCHDOG_PLATFORM_AUDIT_RETENTION_DAYS` (обычно делает планировщик) |
| `php artisan service:alerts` | Внеочередная проверка состояния «Сервиса»: письмо ответственному уходит только при переходе в «проблему» или при восстановлении (обычно делает планировщик) |
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
make shopware-app-update-e2e        # обновление App у подключённого магазина на настоящем Shopware 6.6/6.7 (Docker, своя база bw_app_update_e2e)
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
