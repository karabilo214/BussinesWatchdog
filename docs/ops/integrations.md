# Воркер, Telegram, плагины

## Воркер браузерных проверок

```sh
php artisan browser:worker-create worker-1            # токен выводится один раз
php artisan browser:worker-create worker-1 --rotate   # замена токена
```

Воркеру (`apps/browser-worker`) задаются `BW_WORKER_TOKEN` (токен из команды), `BW_API_URL` (адрес бэкенда) и `BW_EGRESS_PROXY` (egress-прокси `infra/docker/egress`); доступа к БД и Redis у воркера нет.

## Telegram

```sh
php artisan telegram:webhook set     # регистрирует webhook на APP_URL/api/v1/webhooks/telegram/updates
php artisan telegram:webhook info    # проверить
```

## WooCommerce-плагин (ZIP для магазинов)

```sh
plugins/woocommerce-watchdog/bin/build-release.sh plugins/woocommerce-watchdog apps/backend/storage/app/plugins
plugins/woocommerce-watchdog/tests/matrix/release-checks.sh    # проверки выпуска на собранном ZIP
```

ZIP кладётся в каталог `WATCHDOG_PLUGIN_DIR` (по умолчанию `apps/backend/storage/app/plugins`; в Docker — тот же каталог внутри контейнера бэкенда, он должен быть на постоянном томе). Кабинет показывает кнопку «Скачать плагин» с самой новой версией `business-watchdog-X.Y.Z.zip` из этого каталога; старые версии можно оставить или удалить.

Shopware App отдельно не собирается: кабинет выдаёт архив приложения для каждого магазина. Новая версия App (`ShopwareApp::APP_VERSION`, файлы в `apps/backend/resources/shopware-app`) выходит вместе с бэкендом: магазины, где App уже подключено, увидят в кабинете «Скачать обновление приложения» (архив для того же подключения) и загрузят его в Shopware как обновление.
