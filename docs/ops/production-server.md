# Боевой сервер (business-watchdog.com)

Один сервер (Contabo Cloud VPS 8, Ubuntu 24.04, `167.86.85.23`): Docker Compose из `infra/production` — Caddy (HTTPS Let's Encrypt, сайт, `/app`, `/owner`), бэкенд, планировщик, обработчики очередей, PostgreSQL 18 и Redis без открытых наружу портов. Доступ — только по SSH-ключу (`~/.ssh/business_watchdog_ed25519`, в `~/.ssh/config` алиас `bw-prod`), файрвол ufw (22, 80, 443), fail2ban, автообновления безопасности.

**На сервере работаем только через git.** Рабочая копия — `/opt/business-watchdog/repo` (клон GitHub), секреты — только `/opt/business-watchdog/.env` (права 600, в git не попадает; сохраните копию `APP_KEY` и `WATCHDOG_KEYRING_V1` офлайн — без них не расшифровать данные). Руками на сервере файлы не правятся.

Последние зафиксированные выпуски: `9efa51e` (2026-10-28, публичный сайт/SEO до Step 306) и `dd23ead` (2026-10-29, Step 307, passkeys сотрудников — владелец подтвердил вход). WooCommerce 0.4.2 опубликован в WordPress.org и одобрен для выдачи из кабинета 2026-10-29; точный ZIP и SHA-256 — `integrations.md`. Steps 308–318 не выложены. Для Step 317 в production `.env` уже записано `BW_BROWSER_WORKERS=3`, применится при следующей выкладке. Эти сведения сверены 2026-10-29 по журналам проекта, без нового доступа к серверу.

История перехода на выкладку без простоя (Step 275): `7be8672` — однократный переход со старой схемы с короткой недоступностью; `b6c1690` — первая выкладка в новой схеме, 0 ответов кроме 200. Далее использовалась та же схема; подробные результаты — `../progress/core.md`. Git push не выкладывает SaaS. Deploy, rollout и любые изменения production выполняются только по явной команде владельца (AGENTS.md).

```sh
git push                                # отправляет коммит в git и запускает CI; сам по себе не выкладывает SaaS
infra/production/deploy.sh              # текущий HEAD: git checkout на сервере и rollout.sh — сборка, миграции, замена без простоя
infra/production/deploy.sh <commit>     # конкретный коммит (откат — то же самое с прошлым коммитом)
infra/production/deploy.sh init         # первый запуск на новом сервере: клон репозитория и .env с новыми секретами
```

Версия PHP в образе бэкенда закреплена точным тегом (`FROM php:8.4.27-cli` / `php:8.4.27-fpm` в `infra/docker/backend/Dockerfile`), а `deploy.sh` собирает с `docker compose build --pull` — иначе Docker берёт закэшированный на сервере старый базовый образ. При выходе патча PHP (особенно security): поменять версию в обеих строках `FROM`, проверить, что тег уже есть на Docker Hub (`curl -s -o /dev/null -w '%{http_code}' https://hub.docker.com/v2/namespaces/library/repositories/php/tags/<версия>-fpm` → 200), выложить и проверить `docker compose exec app php -v`.

Расширение PHP `redis` собирается из релиза phpredis на GitHub, а не через PECL: падение pecl.php.net 2026-10-21 остановило выкладку. Версия и SHA-256 архива — `PHPREDIS_VERSION` и `PHPREDIS_SHA256` в `infra/docker/backend/Dockerfile` (в обеих стадиях). Для обновления: `curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/<версия>.tar.gz | shasum -a 256`, вписать версию и хеш, выложить.

**После правки `.env`** изменения не применяются сами: контейнеры читают `.env` только при создании. Пересоздать их без простоя (данные и очереди сохраняются; `deploy.sh` делает это тоже):

```sh
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && sh rollout.sh'
```

Не использовать для этого `docker compose up -d` без списка сервисов: он пересоздаёт оба PHP-FPM (`app` и `app-b`) одновременно, и сайт на несколько секунд отвечает 502.

## Выкладка без простоя (ADR 0080, Step 275)

`infra/production/rollout.sh` (его запускает `deploy.sh` на сервере) выполняет по порядку:
1. собирает образы;
2. применяет миграции, пока работает прежний код;
3. выкладывает статику в том `web-root` (`web-static stage`);
4. по очереди заменяет `app-b` и `app`: Caddy отправляет запросы на тот, что работает, и повторяет запрос, если не смог соединиться;
5. переключает статику (`web-static switch`) и делает `caddy reload`;
6. выполняет `site:seo-pages --if-owner --publish=…`: публикация девяти SEO-инструкций на ru/en/de; повторный релиз сохраняет редакторские изменения и обновляет только страницы, которые владелец не правил (Steps 304 и 306, `site.md`);
7. перезапускает обработчики очередей и планировщик: текущее задание дорабатывает до 120 с, а прерванное остаётся в Redis и выполняется повторно.

Caddy — официальный образ `caddy:2.10.2-alpine`, конфигурация — `infra/production/caddy/Caddyfile`. Правка Caddyfile применяется выкладкой через `caddy reload`, без пересоздания контейнера. Новая версия Caddy — поменять тег в `docker-compose.yml`. Эта выкладка один раз пересоздаёт `web` (секунды недоступности), поэтому её лучше делать в тихое время.

**Миграции — только совместимые с предыдущей версией кода (expand/contract):**
- новые колонки — nullable или со значением по умолчанию;
- переименование и удаление колонки или таблицы — в два релиза: сначала код перестаёт её использовать, в следующем релизе она удаляется;
- индексы на больших таблицах — `CREATE INDEX CONCURRENTLY`;
- массовое заполнение — отдельной командой или заданием, а не в миграции.

Несовместимое изменение — только плановым окном с простоем, по согласованию с владельцем.

Проверка во время выкладки (в отдельном терминале, затем `deploy.sh`; каждые 100 мс, любой ответ кроме 200 — ошибка):

```sh
infra/production/probe.sh https://business-watchdog.com 300 /health/ready /app/ /en
```

Первая выкладка этой схемы (переход со старого `web`-образа) один раз пересоздаёт `web` и `app`: несколько секунд простоя ожидаемы. Проверять probe-скриптом нужно со следующей выкладки.

Команды artisan на сервере:

```sh
ssh -t bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose exec -u www-data app php artisan <команда>'
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose logs -f --tail 100 app web scheduler'
```

DNS у NIC.UA: `A` и `AAAA` для `@` и `www` на адреса сервера. Скриншоты, выгрузки и файлы сайта хранятся на диске сервера (том `storage`, `WATCHDOG_*_DISK=local`). Браузерный воркер (`worker-1`) запускается, когда в `.env` задан `BW_WORKER_TOKEN`. Токен создаётся и сразу записывается в `.env`, не показываясь на экране: `ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && T=$(docker compose exec -T -u www-data app php artisan browser:worker-create worker-1 | grep -oE "bwwk_[0-9a-f]{64}") && sed -i "s|^BW_WORKER_TOKEN=.*|BW_WORKER_TOKEN=$T|" /opt/business-watchdog/.env'`, затем `deploy.sh` (для смены токена — `--rotate`). Песочница Chromium включена (ADR 0011); на Ubuntu 24.04 ей нужен свой seccomp-профиль `infra/production/seccomp/bw-browser-worker.json` — стандартный профиль Docker, в котором разрешены только `unshare`, `clone`, `setns`, `chroot` для namespace-песочницы. Проверка: `docker compose --profile browser logs browser-worker` — строка `worker_started` с `"sandbox":true`.

Бэкапы — [backups.md](backups.md), почта — [mail.md](mail.md).

**fail2ban и предупреждение `allowipv6`.** Пакет fail2ban 1.0.2 в Ubuntu 24.04 при каждом `fail2ban-client reload` пишет `WARNING 'allowipv6' not defined in 'Definition'. Using default one: 'auto'` — это не ошибка выкладки, значение по умолчанию то же. `deploy.sh` ставит `infra/production/fail2ban/fail2ban.d/bw.local` с `allowipv6 = auto`, и предупреждение пропадает.

Заголовки браузерной безопасности (Step 308, локально, ожидает выкладки): [security-headers.md](security-headers.md). CSP кабинета и immutable root переключаются одним Caddy reload; HSTS и режим CSP генерируются на этапе stage, поэтому для смены их значений Caddy не нужно пересоздавать. Preload требует отдельного решения. TT Paddle включён после настоящего sandbox checkout/PayPal popup и production startup (Step 313); после выкладки повторить smoke на боевом origin.
