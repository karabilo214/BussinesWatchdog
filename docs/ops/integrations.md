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

ZIP кладётся в каталог `WATCHDOG_PLUGIN_DIR` (по умолчанию `apps/backend/storage/app/plugins`; в Docker — тот же каталог внутри контейнера бэкенда, он должен быть на постоянном томе). В production кабинет выдаёт архив только при совпадении его SHA-256 с `WATCHDOG_WOO_APPROVED_SHA256`. До прохождения проверок переменная остаётся пустой и скачивание закрыто. При загрузке нового, ещё не одобренного ZIP прежний одобренный архив остаётся доступным. Хеш считать с **точного файла после сборки и проверки** (`shasum -a 256 business-watchdog-X.Y.Z.zip`); любое изменение файла закрывает его выдачу до нового одобрения.

Shopware App отдельно не собирается: архив индивидуален для магазина. В production создание и обновление App закрыты до совпадения `WATCHDOG_SHOPWARE_APP_APPROVED_SHA256` с отпечатком текущего кода упаковщика, версии, permissions, событий и файлов `resources/shopware-app` **и** включения UUID магазина в `WATCHDOG_SHOPWARE_APP_APPROVED_STORE_IDS` (через запятую). Сначала проверяют установку или обновление и checkout на копии именно этого магазина, затем добавляют его UUID. После сквозной проверки отпечаток выводит `php artisan tinker --execute="echo app(App\\Support\\Providers\\Shopware\\ShopwareApp::class)->releaseFingerprint();"`; его записывают в production env и выкладывают через обычный deploy. Изменение любого файла App или кода упаковщика снова закрывает выдачу. `WATCHDOG_CONNECTOR_RELEASE_GATE` включён по умолчанию при `APP_ENV=production`; отключать его для production нельзя.

Версия App 1.3.1 не расширяет Twig-шаблоны checkout и не исполняет page-loaded App Scripts. Счётчик страницы без JavaScript больше недоступен; отчёты браузера уходят только в endpoint самого магазина. App на Shopware 6.6.10.29 по матрице совместимости не выдавать: установка завершается ошибкой Shopware, использовать ключ интеграции. Пока версия магазина до установки App не определяется надёжным способом, перед выдачей архива требуется проверка на копии этого магазина; один одобренный отпечаток архива сам по себе не расширяет матрицу совместимости.
