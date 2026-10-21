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

Shopware App — **один архив для всех магазинов** (ADR 0076, с версии 1.5.0): кабинет отдаёт его по `GET /api/v1/downloads/shopware-app`. Клиент устанавливает App, открывает его в Shopware (Extensions → My extensions → Business Watchdog → Open app) и вставляет код подключения из кабинета; магазин привязывается, только если код действителен (15 минут), домен магазина в Shopware совпадает с адресом магазина в кабинете и сервис смог войти в Shopware ключом App. Секрет App выводится из `APP_KEY` — отдельной настройки нет; **смена `APP_KEY` ломает повторную регистрацию всех установленных App**. Непривязанные установки удаляются через 7 дней (`shopware:app-installations-purge`, по расписанию). Магазины со старым архивом под магазин (до 1.5.0, например Shopware-Test) продолжают работать по прежним адресам; чтобы перейти на универсальный App, старый удаляют и ставят новый. Владелец одобряет **версию**, а не магазин (ADR 0074): в production создание и обновление App закрыты до совпадения `WATCHDOG_SHOPWARE_APP_APPROVED_SHA256` с отпечатком текущего кода упаковщика, версии, permissions, событий и файлов `resources/shopware-app`. После проверки версии отпечаток выводит `php artisan tinker --execute="echo app(App\\Support\\Providers\\Shopware\\ShopwareApp::class)->releaseFingerprint();"`; его записывают в production `.env` и выкладывают через обычный deploy — с этого момента App этой версии скачивают все клиенты в кабинете. Локально (`APP_ENV=local`) одобрение не нужно: кабинет всегда выдаёт App текущей версии из кода, чтобы её можно было проверить на своём Shopware; `WATCHDOG_CONNECTOR_RELEASE_GATE` локально не задавать. Изменение любого файла App или кода упаковщика снова закрывает выдачу до нового одобрения. `WATCHDOG_CONNECTOR_RELEASE_GATE` включён по умолчанию при `APP_ENV=production`; отключать его для production нельзя.

Версия App 1.4.0 (ADR 0075) читает страницы и селекторы оформления заказа из настроек магазина: скрипт запрашивает их у самого магазина (`GET /storefront/script/bw-observe?config=1`), сервис записывает их в сущность App при синхронизации. После изменения настроек в кабинете скрипт видит их через ≤ 5 минут синхронизации и ≤ 5 минут HTTP-кеша Shopware. Проверка на настоящем Shopware — `plugins/shopware-app/tests/observation/observation-e2e.sh` (6.6.10.6 и 6.7.2.2). Версия App 1.3.1 (и 1.3.2 — только добавлена немецкая подпись `label`, без которой `bin/console app:install` отказывается ставить App) не расширяет Twig-шаблоны checkout и не исполняет page-loaded App Scripts. Счётчик страницы без JavaScript больше недоступен; отчёты браузера уходят только в endpoint самого магазина. App на Shopware 6.6.10.29 по матрице совместимости не выдавать: установка завершается ошибкой Shopware, использовать ключ интеграции. Проверки на копии магазина перед выдачей нет (ADR 0074); несовместимые версии Shopware названы в кабинете у шага загрузки App.

## Тексты о скрипте наблюдения для магазинов (Step 214)

В кабинете у плагина WooCommerce и у Shopware App есть блок «Тексты для политики конфиденциальности вашего магазина»: абзац для политики конфиденциальности магазина и шаблон оценки законного интереса (LIA) на немецком и английском (ответ юриста, ADR 0062). Поставщик в текстах берётся из `.env`:

```sh
WATCHDOG_PROVIDER_NAME="Имя Фамилия латиницей"      # как в Impressum
WATCHDOG_PROVIDER_ADDRESS="Улица дом, индекс Город, Страна"
```

Без `WATCHDOG_PROVIDER_NAME` в текстах стоит заполнитель в квадратных скобках, и кабинет предупреждает об этом. После правки `.env` — пересоздать контейнеры (`docs/ops/production-server.md`). Сами тексты — `apps/backend/resources/merchant-texts/{de,en}-{privacy,lia}.txt` (`:provider` заменяется).
