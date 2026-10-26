# Запуск и выпуск релиза

Что настроить и запустить при выпуске: переменные окружения, база, фоновые задачи, первый владелец, сборка фронтендов. Боевой сервер — [production-server.md](production-server.md).

Все команды `php artisan …` ниже выполняются в `apps/backend` (локально — PHP 8.4: `/opt/homebrew/opt/php@8.4/bin/php artisan …`; в Docker — `docker compose exec backend php artisan …`).

## Переменные окружения (`apps/backend/.env`)

Образец — `apps/backend/.env.example`. Обязательно для боевого запуска:

- **Приложение:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` (HTTPS-адрес сервиса), `APP_KEY` (`php artisan key:generate --show`).
- **База и Redis:** `DB_*` (PostgreSQL 18), `REDIS_*`.
- **Сессии:** `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS` (домен кабинета).
- **Ключи шифрования секретов:** `WATCHDOG_KEYRING_CURRENT=1`, `WATCHDOG_KEYRING_V1=base64:…` (32 случайных байта: `openssl rand -base64 32`, перед значением `base64:`). Ключ нельзя терять — без него не расшифровать ключи интеграций и MFA. Ротация — см. [maintenance.md](maintenance.md).
- **Почта:** `MAIL_*`, `MAIL_FROM_ADDRESS`, DKIM (`MAIL_DKIM_*`). Проверка — `php artisan mail:auth-check --strict`.
- **Хранилище файлов (S3):** `AWS_*` — скриншоты проверок и экспорты хранятся приватно.
- **Telegram:** `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`, при необходимости `TELEGRAM_WEBHOOK_URL`.
- **Оплата подписки (Paddle):** `PADDLE_ENVIRONMENT=production` (иначе sandbox), `PADDLE_API_KEY`, `PADDLE_CLIENT_TOKEN`, `PADDLE_WEBHOOK_SECRET`, `PADDLE_ACCOUNT_REF`.
- **Ограничения тарифов:** `WATCHDOG_BILLING_ENFORCEMENT=enforce` включает лимиты тарифов; `pilot` (по умолчанию) — всё доступно всем.
- **Панель владельца (по желанию):** `WATCHDOG_PLATFORM_IDLE_MINUTES` (30), `WATCHDOG_PLATFORM_ABSOLUTE_HOURS` (8), `WATCHDOG_PLATFORM_LOGIN_PER_MINUTE` (5), `WATCHDOG_PLATFORM_AUDIT_RETENTION_DAYS` (730 — через сколько дней удаляются записи аудита платформы, ADR 0053).
- **Окна перепроверки (по желанию, в днях, по умолчанию 90):** `WATCHDOG_STRIPE_AUDIT_DAYS`, `WATCHDOG_PAYPAL_AUDIT_DAYS`, `WATCHDOG_WOOCOMMERCE_API_AUDIT_DAYS`, `WATCHDOG_SHOPWARE_AUDIT_DAYS` — сколько дней назад ежедневная полная синхронизация (`--audit`) перечитывает источник; `WATCHDOG_STRIPE_DISPUTE_DAYS` (по умолчанию 120) — за сколько дней каждая синхронизация Stripe перечитывает споры (их статус меняется долго после открытия); `WATCHDOG_PAYPAL_DISPUTE_DAYS` (по умолчанию 180) — за сколько дней первая синхронизация PayPal берёт споры, дальше — только изменённые; `WATCHDOG_RECONCILIATION_LOOKBACK_DAYS` — за сколько дней ночная сверка и перезапуск сверки магазина (после восстановления коннектора, подключения провайдера) берут заказы. Новые платежи и возвраты по старым заказам ловит обычный опрос независимо от этих окон.
- **Оповещения «Сервиса» (ADR 0051):** `WATCHDOG_SERVICE_ALERT_EMAIL` — адрес ответственного (несколько — через запятую; пусто — письма не отправляются), `WATCHDOG_SERVICE_ALERT_LOCALE` (`ru`, также `en`/`de`). Письмо приходит, когда общее состояние раздела «Сервис» становится «проблема», и ещё одно — когда оно восстанавливается; повторов на каждой проверке нет, неотправленное письмо повторяется через минуту.
- **Очередь заданий в «Сервисе»:** строка «Задания синхронизации» читает ту очередь, из которой берут задания обработчики (`QUEUE_CONNECTION`, на боевом сервере Redis, очереди `sync` и `default`): ждут, отложены, выполняются, возраст самого старого, упавшие за сутки, обработчики на связи и последнее выполненное задание. Обработчики (`queue:work`) сами пишут отметку жизни в таблицу `queue_worker_heartbeats` (раз в 30 с, при начале и конце задания; строки старше суток удаляются). «Проблема» — самое старое задание ждёт дольше часа, задания ждут больше 5 минут без живых обработчиков или очередь не отвечает. Настройки не нужны.
- **Версия релиза:** `WATCHDOG_RELEASE` — метка выпуска (например, `2026.10.20-1` или хеш коммита); показывается в админке в разделе «Сервис», по умолчанию `dev`.
- **Публичный сайт (по желанию):** `WATCHDOG_SITE_CONTACT_NOTIFY` — куда слать письма о новых обращениях (несколько адресов через запятую; пусто — всем активным владельцам сервиса), `WATCHDOG_SITE_OWNER_URL` (адрес панели для ссылки в письме, по умолчанию `APP_URL/owner`), `WATCHDOG_SITE_CONTACT_RETENTION_DAYS` (365 — через сколько дней после закрытия удалять обращение), `WATCHDOG_SITE_CONTACT_PER_HOUR` (5 сообщений в час с одного IP), файл в форме обратной связи: `WATCHDOG_SITE_CONTACT_ATTACHMENT_TYPES` (`jpeg,png,webp,pdf`; пусто — поле файла скрыто), `WATCHDOG_SITE_CONTACT_ATTACHMENT_MAX_BYTES` (5 МБ), `WATCHDOG_SITE_CONTACT_ATTACHMENT_DISK` (по умолчанию диск скриншотов `WATCHDOG_ARTIFACTS_DISK`; файлы удаляются вместе с обращением командой `site:contact-purge`), капча формы использует `WATCHDOG_PUBLIC_CHECK_CAPTCHA_TTL_MINUTES`; `WATCHDOG_SITE_CACHE_SECONDS` (60 — сколько браузер и прокси держат страницу). Для публичной проверки магазина по URL: `WATCHDOG_PUBLIC_CHECK_CAPTCHA_TTL_MINUTES` (10), `WATCHDOG_PUBLIC_CHECK_HOST_COOLDOWN_DAYS` (30), `WATCHDOG_PUBLIC_CHECK_IP_COOLDOWN_DAYS` (10), `WATCHDOG_PUBLIC_CHECK_SUBMIT_ATTEMPTS_PER_HOUR` (5), `WATCHDOG_PUBLIC_CHECK_MAX_RUNNING` (1), `WATCHDOG_PUBLIC_CHECK_ARTIFACT_TTL_MINUTES` (5). Файлы редактора страниц (ADR 0063): `WATCHDOG_SITE_MEDIA_TYPES` (`jpeg,png,webp`; добавьте `pdf`, чтобы разрешить PDF), `WATCHDOG_SITE_MEDIA_MAX_BYTES` (5 МБ), `WATCHDOG_SITE_MEDIA_MAX_SIDE` (6000 px), `WATCHDOG_SITE_MEDIA_MAX_PIXELS` (16 000 000), сжатие — `WATCHDOG_SITE_MEDIA_RESIZE_MAX_SIDE` (2560 px по длинной стороне, `0` — не уменьшать), `WATCHDOG_SITE_MEDIA_JPEG_QUALITY` и `WATCHDOG_SITE_MEDIA_WEBP_QUALITY` (82), `WATCHDOG_SITE_MEDIA_PNG_COMPRESSION` (9), хранилище — `WATCHDOG_SITE_MEDIA_DISK` (по умолчанию то же приватное S3, что и скриншоты); публичный сайт отдаёт файлы по адресу `/media/…`. Размер файла ограничен также PHP и nginx: в образе бэкенда `upload_max_filesize=32M`, `post_max_size=34M`, `memory_limit=256M` (`infra/docker/backend/php-uploads.ini`), в nginx `client_max_body_size 34m` (`infra/nginx/backend.conf`) — чтобы разрешить больше, поднимите их вместе с `WATCHDOG_SITE_MEDIA_MAX_BYTES`; при больших `MAX_SIDE`/`MAX_PIXELS` нужен и больший `memory_limit` (около 10 байт на пиксель). Образ бэкенда должен содержать расширения PHP `gd` (JPEG, PNG, WebP) и `exif` — в `infra/docker/backend/Dockerfile` они есть; после обновления образы нужно пересобрать (`docker compose build && docker compose up -d`).

## База данных

```sh
php artisan migrate --force
```

## Фоновые задачи

Должен работать планировщик Laravel — сервис `scheduler` в Docker Compose или cron раз в минуту:

```sh
* * * * * cd /path/to/apps/backend && php artisan schedule:run >> /dev/null 2>&1
```

Во время активного окна обслуживания (админка → «Настройки») плановые браузерные проверки, оценка свежести коннекторов, уведомления клиентам, «зависшие» попытки оплаты и наблюдение за оформлением ставятся на паузу автоматически (ADR 0054); биллинг, приём данных и сверка продолжают работать. Он сам запускает: доставку событий (`outbox:dispatch`, 10 с), один проход сверки (`reconciliation:process-dirty`, 30 с; основную работу делают обработчики ниже), уведомления (`notifications:deliver`, 30 с; `notifications:remind`, час; ежедневные сводки каналов в их местное время `notifications:digest`, 15 мин, ADR 0067), удаление организаций, у которых прошли 30 дней (`privacy:process-deletions`, 5 мин, ADR 0069), ночную сверку (02:30: только заказы, результат которых мог измениться, плюс заказы «своей» ночи 30-дневного цикла — каждый заказ перепроверяется раз в 30 дней, ~1/30 окна за ночь), проверку подтверждения магазинов и свежести коннекторов (минута), «зависшие» попытки оплаты Shopware и WooCommerce REST API без результата 30 минут (`checkout:payment-attempts-stuck`, минута), правила наблюдения за страницей оформления Shopware и WooCommerce (`checkout:observation-evaluate`, 5 минут), планирование браузерных проверок (минута), раздачу заданий синхронизации Stripe / PayPal / WooCommerce REST / Shopware (сами синхронизации выполняют обработчики очереди), события Paddle (`billing:paddle-events`, минута; `billing:process-events`, 30 с), скидки следующего периода подписок (`billing:plan-discounts`, 15 мин), удаление истории старше срока хранения пакета + 30 дней (`billing:history-purge`, ежедневно 03:45 UTC), объём заказов магазинов по пакетам (`billing:order-volume`, ежедневно 04:10 UTC: среднее за три полных месяца, отметка и письмо владельцу при превышении лимита пакета), смены тарифа и отмены подписок (`billing:plan-changes`, минута: переход на более дешёвый тариф в начале нового периода, проверка изменений с неизвестным ответом Paddle, пересчёт скидок), очистку старых скриншотов и экспортов (ежедневно), оповещение ответственного о проблеме в «Сервисе» и о восстановлении (`service:alerts`, минута), письма о новых обращениях с сайта (`site:contact-notify`, минута), удаление записей аудита платформы старше срока хранения (`platform:audit-purge`, ежедневно 03:50 UTC), удаление старых закрытых обращений (`site:contact-purge`, ежедневно) и удаление публичных диагностик магазина через 5 минут (`site:public-checks-purge`, минута). Вручную их запускать не нужно.

Кроме планировщика **обязательно** должны работать обработчики (ADR 0040), иначе подключения не синхронизируются:

- **Синхронизация подключений** — очередь Redis (`QUEUE_CONNECTION=redis`), сколько угодно экземпляров:
  `php artisan queue:work redis --queue=sync --sleep=3 --max-time=3600 --timeout=840`
- **Сверка** — сколько угодно экземпляров: `php artisan reconciliation:work --max-time=3600`

Оба выходят сами раз в час (или по SIGTERM) — их перезапускает supervisor/systemd или Docker (`restart: unless-stopped`). В Docker Compose это сервисы `sync-worker` и `reconciliation-worker`; число экземпляров — `BW_SYNC_WORKERS` и `BW_RECONCILIATION_WORKERS` (по 2). Нужны PHP-расширения `pcntl` и `redis` (есть в образе `infra/docker/backend`). `REDIS_QUEUE_RETRY_AFTER` (960) должен быть больше `--timeout`.

## Первый владелец сервиса (вход в `/owner`)

```sh
php artisan platform:create-owner you@example.com --name="Your Name"
```

Команда спросит пароль (скрыто, два раза), покажет ссылку `otpauth://` и секрет для приложения-аутентификатора, попросит код из приложения и один раз выведет 10 резервных кодов — сохраните их офлайн. Если адрес уже зарегистрирован в кабинете клиента, команда предложит сделать эту учётную запись владельцем. Вход: `https://<домен панели>/owner` — e-mail, пароль, код из приложения.

## Фронтенды

```sh
cd apps/frontend
npm ci
npm run build -w @bw/customer   # кабинет клиента → customer/dist (/app)
npm run build -w @bw/control    # панель владельца → control/dist (/owner, отдельный домен по ТЗ)
npm run build -w @bw/public     # стили публичного сайта → apps/backend/public/site
```

Публичный сайт (`/`, `/ru|en|de/...`) отдаёт сам Laravel готовым HTML (SSR, Blade), Node на сервере не нужен. `@bw/public` собирает только его стили в `apps/backend/public/site` (папка не в git — собирать при каждом выпуске до запуска бэкенда). Без сборки сайт работает, но без оформления.

Внутри скриптов npm использовать только `node "$npm_execpath"`, не голый `npm` (см. `docs/progress.md`, Step 57).
