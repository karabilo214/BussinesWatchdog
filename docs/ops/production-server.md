# Боевой сервер (business-watchdog.com)

Один сервер (Contabo Cloud VPS 8, Ubuntu 24.04, `167.86.85.23`): Docker Compose из `infra/production` — Caddy (HTTPS Let's Encrypt, сайт, `/app`, `/owner`), бэкенд, планировщик, обработчики очередей, PostgreSQL 18 и Redis без открытых наружу портов. Доступ — только по SSH-ключу (`~/.ssh/business_watchdog_ed25519`, в `~/.ssh/config` алиас `bw-prod`), файрвол ufw (22, 80, 443), fail2ban, автообновления безопасности.

**На сервере работаем только через git.** Рабочая копия — `/opt/business-watchdog/repo` (клон GitHub), секреты — только `/opt/business-watchdog/.env` (права 600, в git не попадает; сохраните копию `APP_KEY` и `WATCHDOG_KEYRING_V1` офлайн — без них не расшифровать данные). Руками на сервере файлы не правятся.

```sh
git push                                # выкладывается только запушенный коммит
infra/production/deploy.sh              # текущий HEAD: git checkout на сервере, сборка, миграции, перезапуск
infra/production/deploy.sh <commit>     # конкретный коммит (откат — то же самое с прошлым коммитом)
infra/production/deploy.sh init         # первый запуск на новом сервере: клон репозитория и .env с новыми секретами
```

**После правки `.env`** изменения не применяются сами: контейнеры читают `.env` только при создании. Пересоздать их (несколько секунд, данные и очереди сохраняются; `deploy.sh` делает это тоже):

```sh
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose up -d && docker compose --profile browser up -d'
```

Команды artisan на сервере:

```sh
ssh -t bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose exec -u www-data app php artisan <команда>'
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose logs -f --tail 100 app web scheduler'
```

DNS у NIC.UA: `A` и `AAAA` для `@` и `www` на адреса сервера. Скриншоты, выгрузки и файлы сайта хранятся на диске сервера (том `storage`, `WATCHDOG_*_DISK=local`). Браузерный воркер (`worker-1`) запускается, когда в `.env` задан `BW_WORKER_TOKEN`. Токен создаётся и сразу записывается в `.env`, не показываясь на экране: `ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && T=$(docker compose exec -T -u www-data app php artisan browser:worker-create worker-1 | grep -oE "bwwk_[0-9a-f]{64}") && sed -i "s|^BW_WORKER_TOKEN=.*|BW_WORKER_TOKEN=$T|" /opt/business-watchdog/.env'`, затем `deploy.sh` (для смены токена — `--rotate`). Песочница Chromium включена (ADR 0011); на Ubuntu 24.04 ей нужен свой seccomp-профиль `infra/production/seccomp/bw-browser-worker.json` — стандартный профиль Docker, в котором разрешены только `unshare`, `clone`, `setns`, `chroot` для namespace-песочницы. Проверка: `docker compose --profile browser logs browser-worker` — строка `worker_started` с `"sandbox":true`.

Бэкапы — [backups.md](backups.md), почта — [mail.md](mail.md).
