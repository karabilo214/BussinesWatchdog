# Локальная разработка и тесты

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
