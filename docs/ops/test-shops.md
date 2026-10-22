# Тестовые магазины на боевом сервере

Два настоящих магазина для проверки плагина WooCommerce и Shopware App против боевого сервиса: `https://woo-test.business-watchdog.com` и `https://shopware-test.business-watchdog.com`. Работают на том же сервере отдельным compose-проектом `bw-test-shops` (`infra/test-shops`), без опубликованных портов: боевой Caddy проксирует к ним два поддомена по сети `bw-test-shops`, отдаёт `X-Robots-Tag: noindex` и закрывает служебные пути образа dockware. Лимиты: WooCommerce 1 ГБ / 1,5 CPU (+ база 768 МБ), Shopware 3 ГБ / 2 CPU. Подключены к организации владельца в боевом сервисе. Оплаты — только Stripe test mode и PayPal Sandbox.

| | WooCommerce | Shopware |
|---|---|---|
| Версии | WordPress 7.1.3 / PHP 8.3, WooCommerce 11.2.0 (HPOS), Storefront | Shopware 6.7.15.1 (`bw-shopware:6.7.15.1`, собран из dockware 6.7.2.2), режим `prod` |
| Оплата | Stripe Gateway 11.0.1 (test), PayPal Payments 4.1.3 (sandbox), банковский перевод | Stripe Payment 1.17.4 (test, Step 227), SwagPayPal 10.8.5 (sandbox), предоплата, счёт, наличные |
| Товары | Testkaffee 250 g (12,90 €, доставка 4,90 €), Testgutschein 10 € (виртуальный), Testtasse (вариант Weiß/Schwarz) | демо-каталог dockware |
| Админка | `/wp-admin`, пользователь `bwadmin` | `/admin`, пользователь `admin` |

Пароли (администраторы и база WooCommerce) — только на сервере, `/opt/business-watchdog/test-shops.env` (права 600):

```sh
ssh bw-prod 'grep ADMIN_PASSWORD /opt/business-watchdog/test-shops.env'
```

## Создать заново

Нужен выложенный релиз с маршрутами Caddy (сеть `bw-test-shops` создаёт боевой compose). На сервере, из `/opt/business-watchdog/repo`:

```sh
cp infra/test-shops/env.example /opt/business-watchdog/test-shops.env   # заменить GENERATE случайными паролями, chmod 600
sh plugins/shopware-app/tests/build-image.sh 6.7.15.1 dockware/play:6.7.2.2      # 10–20 минут, образ ~8 ГБ
cd infra/test-shops
docker compose --env-file /opt/business-watchdog/test-shops.env up -d
```

Сразу после запуска WordPress доступен установщик — запускать настройку без паузы. Ключи Stripe (test) и PayPal Sandbox передаются с машины владельца строкой PHP через stdin и сохраняются только в базе магазина:

```sh
# с машины владельца, из корня репозитория; ключи — из apps/backend/.env (WATCHDOG_DEV_*)
printf '<?php $bwKeys = %s;\n' "<json с stripe_pk, stripe_sk, paypal_id, paypal_secret>" | ssh bw-prod 'cd /opt/business-watchdog/repo/infra/test-shops && sh woo-setup.sh'
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/test-shops && sh shopware-setup.sh'
# PayPal для Shopware: shopware-paypal.py на сервере с SW_BASE, SW_ADMIN_PASSWORD, BW_PAYPAL_ID, BW_PAYPAL_SECRET
```

## Важно

- Контейнер Shopware (образ dockware) хранит базу и файлы **внутри себя**: `docker compose up` без изменений его не пересоздаёт, но `down`, смена образа или настроек пересоздаст его с чистым магазином. Перед такими действиями — `docker commit bw-test-shopware bw-shopware-test:<дата>`.
- WooCommerce хранит данные в томах `bw-test-shops_woo-db` и `bw-test-shops_woo-html`.
- Почта из магазинов не отправляется (у WordPress нет sendmail, у Shopware `MAILER_DSN=null://null`).
- Остановить: `cd infra/test-shops && docker compose --env-file /opt/business-watchdog/test-shops.env stop` (поддомены ответят 502).
- Обновление PHP (security-релизы): WooCommerce — `docker pull wordpress:<тег>` и `docker compose --env-file /opt/business-watchdog/test-shops.env up -d woo` (данные в томах); Shopware — внутри контейнера `apt-get install --only-upgrade` пакетов `php8.3-*` из PPA ondrej и `service php8.3-fpm restart` (обновление живёт до пересоздания контейнера; надёжно — пересобрать `bw-shopware` на новом dockware).
- Stripe в Shopware-Test: плагин «Stripe Payment» 1.17.4 (`custom/plugins/StripeShopwarePayment`, хранится только внутри контейнера), тестовые ключи введены владельцем в «Настройки → Stripe» (вебхук зарегистрирован плагином), Card и SEPA подключены к каналу Storefront. Покупка скриптом: `ssh bw-prod "docker run --rm -i --security-opt seccomp=/opt/business-watchdog/repo/infra/production/seccomp/bw-browser-worker.json -e BW_SHOP=https://shopware-test.business-watchdog.com -e BW_PAY=stripe -w /app --entrypoint node business-watchdog/browser-worker:<релиз> --input-type=module -" < infra/test-shops/sw-shopper.mjs` (`BW_PAY`: `stripe`, `stripe_3ds`, `stripe_3ds_fail`, `stripe_decline`, `stripe_sepa`, а также `prepayment`, `invoice`, `cash`, `paypal`).
