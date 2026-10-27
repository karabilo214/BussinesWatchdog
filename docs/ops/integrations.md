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

Одобрение SHA-256 владельцем сервиса для выдачи из кабинета и проверка каталога WordPress.org — разные процедуры. Git commit/push и выкладка кода SaaS не обновляют заявку WordPress.org и не меняют `WATCHDOG_WOO_APPROVED_SHA256` автоматически. Одобренный ZIP кабинета — 0.4.0 (владелец, 2026-10-27, Step 285; тот же файл, что в каталоге WordPress.org; на сервере с 2026-10-27, 0.3.2 удалён по решению владельца), до него — 0.3.2 (Step 205). Кабинет рядом с кнопкой скачивания даёт ссылку на страницу плагина в каталоге WordPress.org.

Выдача нового ZIP в production: положить точный файл в каталог плагинов на томе `storage` и записать его хеш в `.env`, затем обычный deploy:

```sh
scp business-watchdog-X.Y.Z.zip bw-prod:/tmp/
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose cp /tmp/business-watchdog-X.Y.Z.zip app:/var/www/html/storage/app/plugins/ && docker compose exec -T app sha256sum storage/app/plugins/business-watchdog-X.Y.Z.zip'
ssh bw-prod "sed -i 's|^WATCHDOG_WOO_APPROVED_SHA256=.*|WATCHDOG_WOO_APPROVED_SHA256=<sha256>|' /opt/business-watchdog/.env"
```

Прежние архивы с другим хешем кабинет не выдаёт, даже если они остались в каталоге.

Перед выдачей плагина 0.3.4 сначала выложить backend, принимающий необязательное поле `order.snapshot.data.gateway_name` (Step 271, основной event-контракт и runtime-копия). Старые плагины продолжают отправлять события без этого поля. Затем выполнить проверки выпуска нового ZIP и отдельно согласовать его SHA-256 для кабинета. Прежний одобренный файл сохранять; не заменять его новой сборкой с тем же именем.

Shopware App — **один архив для всех магазинов** (ADR 0076, с версии 1.5.0): кабинет отдаёт его по `GET /api/v1/downloads/shopware-app`. Клиент устанавливает App, открывает его в Shopware (Extensions → My extensions → «…» у Business Watchdog → Open extension) и вставляет код подключения из кабинета; магазин привязывается, только если код действителен (15 минут), домен магазина в Shopware совпадает с адресом магазина в кабинете и сервис смог войти в Shopware ключом App. Секрет App выводится из `APP_KEY` — отдельной настройки нет; **смена `APP_KEY` ломает повторную регистрацию всех установленных App**. Непривязанные установки удаляются через 7 дней (`shopware:app-installations-purge`, по расписанию). Магазины со старым архивом под магазин (до 1.5.0, например Shopware-Test) продолжают работать по прежним адресам; чтобы перейти на универсальный App, старый удаляют и ставят новый. Владелец одобряет **версию**, а не магазин (ADR 0074): в production создание и обновление App закрыты до совпадения `WATCHDOG_SHOPWARE_APP_APPROVED_SHA256` с отпечатком текущего кода упаковщика, версии, permissions, событий и файлов `resources/shopware-app`. После проверки версии отпечаток выводит `php artisan tinker --execute="echo app(App\\Support\\Providers\\Shopware\\ShopwareApp::class)->releaseFingerprint();"`; его записывают в production `.env` и выкладывают через обычный deploy — с этого момента App этой версии скачивают все клиенты в кабинете. Локально (`APP_ENV=local`) одобрение не нужно: кабинет всегда выдаёт App текущей версии из кода, чтобы её можно было проверить на своём Shopware; `WATCHDOG_CONNECTOR_RELEASE_GATE` локально не задавать. Изменение любого файла App или кода упаковщика снова закрывает выдачу до нового одобрения. `WATCHDOG_CONNECTOR_RELEASE_GATE` включён по умолчанию при `APP_ENV=production`; отключать его для production нельзя.

Начиная с версии App 1.4.0 (включая текущую 1.5.0; ADR 0075) скрипт читает страницы и селекторы оформления заказа из настроек магазина: скрипт запрашивает их у самого магазина (`GET /storefront/script/bw-observe?config=1`), сервис записывает их в сущность App при синхронизации. После изменения настроек в кабинете скрипт видит их через ≤ 5 минут синхронизации и ≤ 5 минут HTTP-кеша Shopware. Проверка на настоящем Shopware — `plugins/shopware-app/tests/observation/observation-e2e.sh` (6.6.10.6 и 6.7.2.2). Версия App 1.3.1 (и 1.3.2 — только добавлена немецкая подпись `label`, без которой `bin/console app:install` отказывается ставить App) не расширяет Twig-шаблоны checkout и не исполняет page-loaded App Scripts. Счётчик страницы без JavaScript больше недоступен; отчёты браузера уходят только в endpoint самого магазина. App на Shopware 6.6.10.29 по матрице совместимости не выдавать: установка завершается ошибкой Shopware, использовать ключ интеграции. Проверки на копии магазина перед выдачей нет (ADR 0074); несовместимые версии Shopware названы в кабинете у шага загрузки App.

## WooCommerce-плагин в каталоге WordPress.org

Состояние на 2026-10-27:

| Версия | Состояние | SHA-256 точного ZIP |
|---|---|---|
| 0.3.3 | Первая подача в WordPress.org; 2026-10-26 получены замечания, заменён в той же заявке на 0.4.0. Дата подачи и номер заявки в репозитории не записаны. Это ранее переданный владельцу ZIP с `Contributors: karabin214` | `de08405f1dd63088da5e8774e3a0ada4a54223bb7bad291ee7d915682fb5a941` |
| 0.3.4 | Локальный кандидат Step 271 с названиями способов оплаты; в WordPress.org не отправлялся, для выдачи клиентам не одобрен | `859c9bcd317bf9b6f2fa1cff36f7fde16b4a4fa6c0b66987fa4a4525452b48ff` |
| 0.4.0 | Ответ на замечания WordPress.org от 2026-10-26 (Step 274): префикс `business_watchdog_`, уведомления только на экране «Плагины» и своей странице. Включает 0.3.4. Одобрен WordPress.org 2026-10-27, опубликован в каталоге (SVN r3768832, `trunk/` и `tags/0.4.0/` — содержимое именно этого ZIP). Одобрен владельцем для выдачи из кабинета 2026-10-27 | `f05829f91d0ab9d8d3262a8f0510e510664a014fada624cef4b44f9f04c70c1a` |

Оба точных файла повторно найдены и их хеши сверены 2026-10-26. Локальный кандидат лежит в `/private/tmp/bw-step271-release/business-watchdog-0.3.4.zip`; путь к переданному 0.3.3 зафиксирован в `docs/progress/connectors.md`. Временные каталоги не являются постоянным хранилищем релизов. Исходники 0.3.4 не заменяют уже загруженный WordPress.org ZIP 0.3.3. Не пересобирать отправленный ZIP из текущих исходников и не приписывать новой сборке одобрение прежнего файла.

Перед подачей и перед каждой новой версией:

```sh
plugins/woocommerce-watchdog/bin/build-release.sh plugins/woocommerce-watchdog /private/tmp/bw-wporg-candidate
plugins/woocommerce-watchdog/tests/matrix/run.sh floor wc9                 # интеграционные тесты плагина
# Plugin Check на собранном ZIP: распаковать в wp-content/plugins/bw-release матрицы, затем
wp plugin install plugin-check --activate
wp plugin check bw-release --slug=business-watchdog                        # ошибок должно быть 0
```

Подача: аккаунт на wordpress.org → https://wordpress.org/plugins/developers/add/ → загрузить ZIP; адрес `business-watchdog`. В `readme.txt` `Contributors: karabin214` — имя пользователя WordPress.org владельца. После одобрения код кладётся в SVN (`trunk/` — содержимое ZIP, `tags/<версия>/`), картинки каталога — в `assets/` SVN (icon-128x128.png, icon-256x256.png, banner-772x250.png, banner-1544x500.png, screenshot-N.png); они лежат в `plugins/woocommerce-watchdog/.wordpress-org/` (в релизный ZIP не попадают), подписи к скриншотам — в разделе `== Screenshots ==` `readme.txt`. `Stable tag` в `readme.txt` — версия из заголовка плагина. Раздел «External services» обновлять при любом изменении того, что плагин отправляет.

Пока первая заявка на проверке, ZIP можно обновить в той же заявке; при начавшейся переписке сообщить об изменении в существующей цепочке писем. Создавать новую заявку для 0.3.4 не требуется. После первичного одобрения новые версии обычно публикуются через SVN с увеличенным номером и `Stable tag`, без повторной заявки на регистрацию. Каждый релиз проходит автоматическую проверку безопасности до распространения обновления; блокировка или замечания команды могут потребовать исправлений и переписки. Если включён Release Confirmation, владелец также подтверждает выпуск в кабинете. Это описание процедуры, а не разрешение отправлять или публиковать локальную 0.3.4. Источники, сверенные 2026-10-26: [FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/), [проверка релизов](https://developer.wordpress.org/plugins/wordpress-org/automated-security-review/), [Release Confirmation](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/).

**Замечания WordPress.org от 2026-10-26** (письмо «Review in Progress», ID `AUTOPREREVIEW ❗TRM-OWN business-watchdog/karabin214/26Oct26/T1`). Отвечать только в той же цепочке писем, коротко, без списка изменений:

1. Владение названием «Business Watchdog»: владелец сервиса — тот же человек. Подтверждение — TXT-запись в корне домена `business-watchdog.com`: `@ TXT wordpressorg-karabin214-verification`. Второй вариант — сменить e-mail аккаунта WordPress.org на адрес `@business-watchdog.com`. Название и адрес `business-watchdog` при этом остаются; «WooCommerce» уже стоит после «for», как они требуют.
2. Префиксы — исправлено в 0.4.0. Старые имена `bw_*` (таблицы, опции, расписания) переносятся автоматически при первом запросе админки, cron или WP-CLI после обновления. Метаполя заказов `_bw_payment_mode` и `_bw_synthetic_run` продолжают читаться плагином, а `_bw_payment_mode` ещё и REST-коннектором бэкенда. Откат на 0.3.x после переноса требует нового подключения магазина; релизная проверка для такого отката выводит SKIP.
3. Уведомления админки (Guideline 11) — в 0.4.0 показываются только на экране «Плагины» и на странице плагина.

Сделано владельцем 2026-10-26: ZIP 0.4.0 загружен в ту же заявку под `karabin214`, TXT-запись видна в DNS.

**Каталог WordPress.org (с 2026-10-27).** Плагин одобрен (Review ID `APPROVED business-watchdog/karabin214/26Oct26/T2 27Oct26/4.3`) и опубликован: https://wordpress.org/plugins/business-watchdog/, SVN `https://plugins.svn.wordpress.org/business-watchdog`, пользователь `karabin214`. SVN-пароль отдельный от пароля WordPress.org, задаётся в профиле (Account & Security → SVN password), хранится у владельца; в репозиторий и кэш SVN не попадает. Письма `plugins@wordpress.org` владелец добавил в контакты Gmail — без ответа на их письма плагин могут закрыть.

Выпуск новой версии в каталог (SVN — система выпусков: в `trunk/` только готовые версии). На macOS 14 Homebrew собирает `subversion` из исходников, поэтому SVN запускается в Docker:

```sh
W=/private/tmp/bw-wporg && mkdir -p $W/zip && unzip -q business-watchdog-X.Y.Z.zip -d $W/zip   # точный ZIP после проверок выпуска
docker run --rm -it -v $W:/w -w /w alpine:3.20 sh -c '
  apk add -q subversion &&
  svn co https://plugins.svn.wordpress.org/business-watchdog repo && cd repo &&
  find trunk -mindepth 1 -maxdepth 1 ! -name .svn -exec rm -rf {} + && cp -R /w/zip/business-watchdog/. trunk/ &&
  svn add -q --force trunk && svn status trunk | grep "^!" | cut -c9- | xargs -r svn rm -q &&
  svn cp trunk tags/X.Y.Z &&
  svn status && svn ci -m "Business Watchdog for WooCommerce X.Y.Z" --username karabin214 --no-auth-cache'
```

`Stable tag` в `readme.txt` должен совпадать с новой версией. Картинки каталога (`assets/`, из `plugins/woocommerce-watchdog/.wordpress-org/`) меняются отдельным коммитом без новой версии; у PNG ставить `svn propset svn:mime-type image/png`. Страница обновляется за несколько минут, поиск и профиль — до 72 часов. Описание (`readme.txt`) без новой версии: тот же файл в `trunk/` и в `tags/<Stable tag>/` (каталог берёт описание из тега стабильной версии), один коммит; страница обновляется примерно за минуту. Каждый выпуск проходит автоматическую проверку безопасности WordPress.org. Скриншот 3 переснят 2026-10-27 (r3768857) на локальном стенде: «Demo Store», подключённый плагином 0.4.0 со стенда wc9.

Для 0.4.0 (2026-10-26): интеграционные тесты floor и latest 59/59, `release-checks.sh latest` PASS (обновление с 0.3.4, перенос имён), PHP 7.4 lint ZIP, Plugin Check на ZIP — 0 ошибок (предупреждения о прямых запросах к своим таблицам, как и раньше). `release-checks.sh floor` не работает и до этого шага: `release-probe.php` использует `?->`, которого нет в PHP 7.4.

Для 0.3.4 зафиксированы интеграционные тесты на минимальной и актуальной версиях WooCommerce и PHP 7.4 lint ZIP. Plugin Check с нулём ошибок относится к 0.3.3; перед публикацией 0.3.4 его повторить, вместе с полными проверками совместимости и выпускаемого ZIP. Результаты прежнего архива не переносить на новый автоматически.

## Тексты о скрипте наблюдения для магазинов (Step 214)

В кабинете у плагина WooCommerce и у Shopware App есть блок «Тексты для политики конфиденциальности вашего магазина»: абзац для политики конфиденциальности магазина и шаблон оценки законного интереса (LIA) на немецком и английском (ответ юриста, ADR 0062). Поставщик в текстах берётся из `.env`:

```sh
WATCHDOG_PROVIDER_NAME="Имя Фамилия латиницей"      # как в Impressum
WATCHDOG_PROVIDER_ADDRESS="Улица дом, индекс Город, Страна"
```

Без `WATCHDOG_PROVIDER_NAME` в текстах стоит заполнитель в квадратных скобках, и кабинет предупреждает об этом. После правки `.env` — пересоздать контейнеры (`docs/ops/production-server.md`). Сами тексты — `apps/backend/resources/merchant-texts/{de,en}-{privacy,lia}.txt` (`:provider` заменяется).
