# Почта

## Настройки приложения

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

## Почтовый сервер на боевом сервере

**Почта** (`mail.business-watchdog.com`) — свой почтовый сервер в контейнерах `mail` (Postfix + Dovecot) и `rspamd` (фильтр спама):

- Письма сервиса приложение передаёт в `mail` по внутреннему порту 2525 (`MAIL_HOST=mail`, `MAIL_PORT=2525`; наружу не открыт) и подписывает DKIM само (`MAIL_DKIM_*`, селектор `bw1`). Письма сотрудников подписывает `rspamd` тем же ключом.
- Входящая почта на `@business-watchdog.com` — порт 25: принимается только для существующих ящиков и псевдонимов, чужую почту сервер не пересылает. `rspamd` отклоняет явный спам (балл ≥ 15), подозрительные письма (≥ 6) кладутся в папку «Junk». Пока `rspamd` недоступен, входящие письма получают временный отказ и отправители повторяют позже.
- Сертификат — Let's Encrypt, его получает Caddy для `mail.business-watchdog.com`; `mail` берёт его из тома `caddy-data` и подхватывает продление сам (проверка раз в 10 минут).
- DNS у NIC.UA: `A mail` → `167.86.85.23`; `MX @` → `mail.business-watchdog.com` (приоритет 10); TXT `@` — `v=spf1 ip4:167.86.85.23 ip6:2a02:c207:2367:8142::1 -all`; TXT `bw1._domainkey` — публичный DKIM-ключ; TXT `_dmarc` — `v=DMARC1; p=quarantine; adkim=r; aspf=r`. В панели Contabo обратная запись (PTR) обоих адресов — `mail.business-watchdog.com`. Проверка — `php artisan mail:auth-check --strict`.

Первая настройка почты (один раз, с Mac; `ivan@` — пример, подставить свой адрес):

1. У NIC.UA добавить запись **MX**: имя `@`, значение `mail.business-watchdog.com`, приоритет `10`. Без неё входящие письма на домен не доходят (запись `A mail` для этого недостаточна).
2. Создать свой ящик (пароль вводится в терминале дважды, не короче 12 символов):
   `ssh -t bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose exec mail mailbox add ivan@business-watchdog.com'`
3. Направить служебные адреса в свой ящик — `hello@` (от него сервис шлёт письма; сюда придут ответы клиентов и возвраты недоставленных писем), `postmaster@` и `abuse@` (их ждут почтовые службы):
   `ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && for a in hello postmaster abuse; do docker compose exec -T mail mailbox alias $a@business-watchdog.com ivan@business-watchdog.com; done'`
4. Подключить ящик в почтовой программе (настройки ниже) и отправить себе письмо с Gmail — оно должно прийти во «Входящие».

Ящики и псевдонимы (данные — том `mail-data`, пароль не короче 12 символов, вводится в терминале):

```sh
ssh -t bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose exec mail mailbox add anna@business-watchdog.com'
docker compose exec mail mailbox list                                   # ящики и псевдонимы
docker compose exec mail mailbox passwd anna@business-watchdog.com      # сменить пароль
docker compose exec mail mailbox del anna@business-watchdog.com         # убрать логин (письма остаются на диске)
docker compose exec mail mailbox alias hello@business-watchdog.com anna@business-watchdog.com   # письма на hello@ — в ящик anna@; anna может писать от hello@
docker compose exec mail mailbox unalias hello@business-watchdog.com
```

Репутация домена в Gmail — Google Postmaster Tools (`postmaster.google.com`, вход аккаунтом владельца; домен подтверждён TXT-записью `google-site-verification=…` у NIC.UA, 2026-10-19). Данные появляются только при заметном потоке писем на Gmail; до этого — «Недостаточно данных».

Подбор паролей к почте блокирует fail2ban (jail `bw-mail`, ставит `deploy.sh`): неверный пароль IMAP/SMTP или попытка входа на порт 25 — 6 раз за 10 минут, и адрес закрывается для портов 25/465/587/993 на час, при повторах дольше (до недели). Правило — в цепочке `DOCKER-USER`: обычный `INPUT` Docker обходит. Логи `mail` идут в journald (`journalctl CONTAINER_TAG=bw-mail`, `docker compose logs mail` тоже работает).

```sh
ssh bw-prod 'fail2ban-client status bw-mail'                     # кто заблокирован
ssh bw-prod 'fail2ban-client set bw-mail unbanip <адрес>'        # снять блокировку (например, свой адрес после ошибок пароля)
```

Настройки почтовой программы: IMAP `mail.business-watchdog.com`, порт 993, SSL/TLS; SMTP `mail.business-watchdog.com`, порт 587 (STARTTLS) или 465 (SSL/TLS); логин — полный адрес. Отправить письмо можно только от своего адреса или от псевдонима, который ведёт в свой ящик.
