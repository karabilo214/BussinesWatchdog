# Shopware Store: публикация App

Подготовлено 2026-10-27 (Step 287). App в Store — тот же универсальный App 1.5.0 (ADR 0076): те же адреса регистрации, вебхуков и модуля, те же права «только чтение» и привязка к магазину кодом из кабинета и доменом. Отличия версии для Store: техническое имя с префиксом партнёра, в манифесте нет секрета (регистрацию подписывает Shopware своим секретом), есть иконка, ссылка на политику конфиденциальности и совместимость `~6.6.0 || ~6.7.0`. Выдача универсального архива из кабинета и его одобренный отпечаток (ADR 0074) не меняются.

Пакет проверен `xmllint` по `manifest-2.0.xsd` и официальным `shopware-cli extension validate` — 0 замечаний. Установить его в настоящий Shopware локально нельзя: без секрета в манифесте Shopware берёт секрет из аккаунта Store. Регистрация с секретом Store проверена тестом (`ShopwareStoreAppTest`).

## Что решить или выяснить до регистрации

1. **Кто может стать Extension Partner.** Раздел Extension Partner открывается только после того, как владелец принял договор Extension Partner и Shopware его одобрил ([документация](https://docs.shopware.com/en/account-en/extension-partner/extensions)). Нужна ли зарегистрированная компания (раньше в проекте предполагалось «да» — по опыту с аккаунтом Shopware для плагина Stripe, ADR 0025), в документации Shopware не сказано. Проверить при регистрации аккаунта; если форма требует компанию или VAT ID — задача ждёт регистрации компании.
2. **Договор Technology Partner (STP) нужен** — по тексту договора Extension Partner (SEP, версия 5.6, прочитан 2026-10-27 из аккаунта владельца). Толкование для владельца, не юридическая оценка — окончательно смотрит юрист:
   - п. 1.4.1: если у клиента в связи с расширением могут возникнуть «Folgekosten, Transaktionsgebühren oder Servicegebühren außerhalb des shopware Stores» (вне Store и вне In-App-покупок Shopware), нужен отдельный STP-договор, заключённый **до** размещения. Подписка Business Watchdog через Paddle — именно такая плата вне Store;
   - п. 1.4.2: без STP запрещено из Store, расширения или Shopware указывать или ссылаться на получение/пробу вне Store. Наши тексты страницы («Create an account at business-watchdog.com…, a trial is available»), инструкция для проверяющих и модуль App («In your Business Watchdog account…») это делают;
   - п. 1.4.3: без STP нельзя своей проверкой лицензии — только проверка лицензий Shopware (или их In-App-интерфейсы). Наш доступ по подписке — своя проверка;
   - п. 1.3.13: реклама и ссылки на контакты вне тикет-системы Shopware запрещены, кроме как с письменного согласия Shopware.

   Цена STP в договоре не названа (страница Shopware: годовой взнос и доля выручки). Варианты — решение владельца:
   - **A. Заключить STP** — запросить у Shopware условия и цену; при STP ограничения 1.4.2/1.4.3 не действуют, тексты страницы остаются как есть;
   - **B. Продавать доступ для Shopware-магазинов через Shopware** (платное расширение или In-App-покупка, Shopware берёт 25 %, п. 2.1.2): тогда STP не нужен, но нужна разработка — признавать лицензию Shopware вместо подписки Paddle для таких магазинов, отдельная цена, тексты без ссылок на свой сайт;
   - **C. Не публиковать в Store** — App и дальше выдаётся из кабинета (уже работает, ADR 0076).

   Другие пункты, важные при A или B: Shopware может бесплатно ставить расширение в свои демо-магазины на 30–120 дней (п. 1.3.10a) — без кабинета App там ничего не делает; по запросу Shopware в течение недели открыть внешнюю логику (п. 1.3.8; репозиторий и так публичный); поддерживать совместимость со всеми версиями Shopware, которые Shopware ещё поддерживает (п. 1.3.9); договор до конца календарного года с автопродлением, расторжение — за 30 дней (п. 4); приложение AVV — Shopware становится заказчиком обработки, список субподрядчиков в машиночитаемом виде (п. 1.3.5, приложение) — юристу.
3. **Субподрядчики (Art. 28 DSGVO).** App передаёт данные заказов сервису; Store требует указать в аккаунте обработчика и субподрядчиков. Список — у юриста владельца (юридические тексты ведёт он).

## Шаги владельца

Серверные шаги (3, 5) может выполнить Claude по просьбе владельца — нужны только техническое имя и секрет из аккаунта. Названия разделов аккаунта Shopware взяты из документации Shopware, не из живого аккаунта: если в аккаунте раздел называется иначе — искать по смыслу.

**1. Решить вариант A/B/C выше.** Для A — запросить у Shopware условия и цену STP (письмо ниже нужно переписать в запрос цены: договор уже отвечает, что STP нужен). Шаги 2–7 — для варианта A; при B сначала разработка.

Прежний вопрос для Shopware (до чтения договора): В аккаунте — поддержка, или письмом на qa@shopware.com:

> Hello, we would like to publish a free app in the Shopware Store. The app connects the shop read-only to our own SaaS service (business-watchdog.com), which the merchant pays directly as a monthly subscription; we take no commission on the shop's sales and nothing is billed through Shopware. Do we need the Shopware Technology Partner agreement for this, or is the Extension Partner agreement enough?

Если ответ «нужен Technology Partner» — решить, стоит ли он своих денег (годовой взнос и доля выручки), до продолжения.

**2. Аккаунт и профиль производителя** — https://account.shopware.com:
- зарегистрироваться (или войти), раздел **Extension Partner** → принять договор Extension Partner и дождаться, что Shopware его подтвердит. Если форма требует компанию или VAT ID — остановиться: публикация ждёт регистрации компании;
- **Extension Partner → Profile**: название «Business Watchdog», логотип (`plugins/woocommerce-watchdog/.wordpress-org/icon-256x256.png`), описание на английском и немецком (можно первый абзац описания ниже), сайт `https://business-watchdog.com`. Без iframe, скриптов, трекинга;
- там же виден **префикс разработчика** (несколько латинских букв) — записать.

**3. Техническое имя на сервер** (`<Префикс>BusinessWatchdog`, например `AbcdBusinessWatchdog`) и сборка пакета:

```sh
ssh bw-prod "echo 'WATCHDOG_SHOPWARE_STORE_APP_NAME=<Префикс>BusinessWatchdog' >> /opt/business-watchdog/.env"
infra/production/deploy.sh
ssh bw-prod 'cd /opt/business-watchdog/repo/infra/production && docker compose exec -T -u www-data app php artisan shopware:app-store-package /tmp && docker compose cp app:/tmp/<Префикс>BusinessWatchdog-1.5.0.zip /tmp/'
scp bw-prod:/tmp/<Префикс>BusinessWatchdog-1.5.0.zip .
```

Собирать на сервере: адреса в манифесте берутся из `APP_URL`. Локально — только с `APP_URL=https://business-watchdog.com`.

**4. Расширение в аккаунте** — Extension Partner → **Extensions → Create new extension**:
- **Master data**: техническое имя — ровно то, что в п. 3 (после публикации не меняется); лицензия — **proprietary** (как в манифесте; позже не меняется); магазины — международный (обязательно) и немецкий; цена — бесплатно; одна категория из списка аккаунта — ближайшая по смыслу (мониторинг, аналитика или администрирование);
- **Description & images**: языки English и Deutsch; для каждого — краткое описание, описание (HTML), highlights и keywords из раздела «Тексты страницы»; картинки из `plugins/shopware-app/store-assets/en/` для английской страницы и `…/de/` для немецкой, с подписями из раздела «Картинки»; иконка — `apps/backend/resources/shopware-store/plugin.png`;
- **субподрядчики** (Art. 28 DSGVO) — список от юриста;
- **Versions → upload**: ZIP из п. 3, совместимость — Shopware 6.6 и 6.7.

**5. Секрет App на сервер — сразу после загрузки ZIP.** Аккаунт покажет секрет App (App secret) у расширения. Без него установки проверяющих не зарегистрируются (`shopware_app_signature_invalid`):

```sh
ssh bw-prod "echo 'WATCHDOG_SHOPWARE_STORE_APP_SECRET=<секрет из аккаунта>' >> /opt/business-watchdog/.env"
infra/production/deploy.sh
```

**6. Инструкция для проверяющих** (поле для тестировщиков / notes for the reviewer):

> The app needs a Business Watchdog account. Create a free trial at https://business-watchdog.com/en/signup (confirm the e-mail), add a Shopware store with the address of your test shop and open the store page. Install and activate the app in your test shop, open it (Extensions → My extensions → «…» next to Business Watchdog → Open extension). In Business Watchdog choose «Additional checkout observation with Shopware App», click «Create connection code» and paste the code into the app. The app connects only when your test shop runs at the address you entered. After that orders of the test shop appear in Business Watchdog within minutes.

Пробный период привязан к домену магазина. Если проверяющие попросят готовый доступ — завести организацию вручную и отдать им e-mail и пароль (не боевые данные клиентов).

**7. Отправить на проверку.** Статусы: Code review → Pending approval → Approved / Rejected → In store. Замечания приходят письмом и видны у версии; исправленную версию загружают туда же (в течение трёх дней версию можно отозвать). После «Approved» — в Master data включить **Publish extensions**.

Новые версии: поднять `ShopwareApp::APP_VERSION`, пройти обычные проверки App, собрать пакет той же командой и загрузить в Versions.

## Требования Store, которые уже выполнены

- Имя без слов «plugin» и «shopware»; описание в манифесте 150–185 символов (en 159, de 152).
- Иконка `Resources/config/plugin.png` 256×256, меньше 30 КБ.
- Нет пунктов в главном меню администрирования: только модуль App в «Мои расширения».
- Скрипт витрины не ставит cookies и ничего не хранит на устройстве, отправляет данные только на адрес самого магазина (ADR 0062); по ответу юриста согласие и запись в cookie-баннере не нужны.
- Установка и удаление: App не загружает внешние файлы при установке; данных вне своих сущностей (`custom_entity_bw_*`: отчёты наблюдения и настройка) App в магазине не создаёт; событие `app.deleted` отзывает подключение на стороне сервиса. При проверке удаления «с удалением данных» убедиться, что Shopware удалил таблицы этих сущностей.
- Модуль App на английском и немецком (английский — запасной язык) и называет причину, если подключение не удалось: код не найден, истёк или использован, адрес магазина не совпадает, App ещё не зарегистрировался, сервис не смог войти в магазин ключом App.

## Тексты страницы

Разрешённые теги: `<a> <b> <i> <em> <strong> <ul> <ol> <li> <p> <br> <h2> <h3> <h4>`; без контактов, рекламы своих услуг и ссылок «напишите нам». Highlights — до 5, features — до 15, keywords — до 5, видео YouTube — до 2.

### English

Short description (167):

> Business Watchdog compares your orders with what Stripe and PayPal really received and refunded and warns you about paid orders without a capture and broken checkouts.

Description:

```html
<p>Business Watchdog watches the money side of your shop. It compares the orders of your Shopware shop with what your payment providers really received and refunded, and warns you when they disagree: an order marked as paid without a capture, a capture without a paid order, a refund missing on one side, payments that keep failing, a checkout where customers stop being able to pay.</p>
<p>The app connects your shop to the Business Watchdog service. It reads orders and their payment states and never changes orders, customers or settings. Stripe and PayPal are connected in the Business Watchdog dashboard with read-only access; without a payment provider the dashboard shows orders and checkout problems, and the money comparison stays «unknown» instead of «all fine».</p>
<h3>What the app adds to your shop</h3>
<ul>
<li>Read-only access to orders, transactions, refund states, currencies, payment methods and order states.</li>
<li>Notifications about order changes, so the dashboard is up to date within minutes.</li>
<li>A small checkout script that counts whether the confirm and finish pages work. It sends nothing to third parties, stores nothing on the visitor's device and collects no form values; reports go to your own shop and the service collects and deletes them.</li>
</ul>
<h3>Setup</h3>
<ol>
<li>Create an account at business-watchdog.com and add your shop with its storefront address.</li>
<li>Install and activate the app, then open it: Extensions → My extensions, click «…» next to Business Watchdog and choose Open extension.</li>
<li>On the store page in the Business Watchdog dashboard create a connection code and paste it into the app. The app connects only when the code is valid and your shop runs at the address of the store in the dashboard.</li>
<li>Optionally connect Stripe or PayPal in the dashboard for the money comparison.</li>
</ol>
<h3>External service and data</h3>
<p>The app transfers order and payment data of your shop to the external Business Watchdog service (business-watchdog.com), which processes it as a processor under Art. 28 GDPR. A Business Watchdog subscription is required; a trial is available.</p>
```

Highlights:

- Read-only: never changes orders
- Orders compared with Stripe and PayPal
- Paid orders without a capture, missing refunds
- Checkout problems noticed without test purchases
- No cookies, nothing stored on the visitor's device

Keywords: `payment monitoring`, `reconciliation`, `stripe`, `paypal`, `checkout`.

### Deutsch

Kurzbeschreibung (175):

> Business Watchdog vergleicht Ihre Bestellungen mit dem, was Stripe und PayPal wirklich erhalten und erstattet haben, und warnt bei Zahlungsabweichungen und Checkout-Störungen.

Beschreibung:

```html
<p>Business Watchdog überwacht die Geldseite Ihres Shops. Die App vergleicht die Bestellungen Ihres Shopware-Shops mit dem, was Ihre Zahlungsanbieter wirklich erhalten und erstattet haben, und warnt bei Abweichungen: eine als bezahlt markierte Bestellung ohne Zahlungseingang, ein Zahlungseingang ohne bezahlte Bestellung, eine Erstattung, die auf einer Seite fehlt, Zahlungen, die immer wieder scheitern, ein Checkout, in dem Kunden nicht mehr bezahlen können.</p>
<p>Die App verbindet Ihren Shop mit dem Dienst Business Watchdog. Sie liest Bestellungen und deren Zahlungsstatus und ändert niemals Bestellungen, Kunden oder Einstellungen. Stripe und PayPal werden im Business-Watchdog-Dashboard mit Lesezugriff verbunden; ohne Zahlungsanbieter zeigt das Dashboard Bestellungen und Checkout-Probleme, der Zahlungsabgleich bleibt „unbekannt“ statt „alles in Ordnung“.</p>
<h3>Was die App in Ihrem Shop ergänzt</h3>
<ul>
<li>Lesezugriff auf Bestellungen, Transaktionen, Erstattungsstatus, Währungen, Zahlungsarten und Bestellstatus.</li>
<li>Benachrichtigungen über Bestelländerungen, damit das Dashboard innerhalb weniger Minuten aktuell ist.</li>
<li>Ein kleines Checkout-Skript, das zählt, ob die Bestätigungs- und Abschlussseite funktionieren. Es sendet nichts an Dritte, speichert nichts auf dem Gerät der Besucher und erfasst keine Formularwerte; Meldungen gehen an Ihren eigenen Shop, der Dienst holt sie ab und löscht sie.</li>
</ul>
<h3>Einrichtung</h3>
<ol>
<li>Erstellen Sie ein Konto auf business-watchdog.com und legen Sie Ihren Shop mit der Adresse der Storefront an.</li>
<li>Installieren und aktivieren Sie die App und öffnen Sie sie: Erweiterungen → Meine Erweiterungen, bei Business Watchdog auf „…“ klicken und „Erweiterung öffnen“ wählen.</li>
<li>Erstellen Sie auf der Shop-Seite im Business-Watchdog-Dashboard einen Verbindungscode und fügen Sie ihn in der App ein. Die App verbindet sich nur, wenn der Code gültig ist und Ihr Shop unter der Adresse des Shops im Dashboard läuft.</li>
<li>Verbinden Sie optional Stripe oder PayPal im Dashboard für den Zahlungsabgleich.</li>
</ol>
<h3>Externer Dienst und Daten</h3>
<p>Die App überträgt Bestell- und Zahlungsdaten Ihres Shops an den externen Dienst Business Watchdog (business-watchdog.com), der sie als Auftragsverarbeiter nach Art. 28 DSGVO verarbeitet. Ein Business-Watchdog-Abonnement ist erforderlich; ein Testzeitraum ist verfügbar.</p>
```

Highlights:

- Nur lesend: ändert keine Bestellungen
- Bestellungen mit Stripe und PayPal abgeglichen
- Bezahlte Bestellungen ohne Zahlungseingang, fehlende Erstattungen
- Checkout-Störungen ohne Testkäufe erkennen
- Keine Cookies, nichts auf dem Gerät der Besucher gespeichert

Keywords: `Zahlungsüberwachung`, `Zahlungsabgleich`, `Stripe`, `PayPal`, `Checkout`.

## Картинки

Готовый английский набор — `plugins/shopware-app/store-assets/en/` (каждая меньше 1 МБ, только демо-данные). Сняты 2026-10-27 на локальном Shopware 6.7.15.1 (тот же образ, что у Shopware-Test) с App 1.5.0, подключённым к магазину «Kaffeerösterei Lindner» (демо-адрес `kaffee-demo.example.test`) кодом из кабинета, как это делает продавец; заказы оформлены в магазине и пришли через вебхуки.

| Файл | Что | Подпись (en) |
|---|---|---|
| `1-admin-enter-code.png` | Администрирование: модуль App до подключения | Open the app in Extensions and paste the connection code from your Business Watchdog account. |
| `2-admin-connected.png` | Администрирование: App подключён | Connected: Business Watchdog reads orders and payment states, read-only. |
| `3-storefront-confirm.png` | Витрина: страница подтверждения заказа | The checkout stays unchanged: the app adds nothing visible and reports only to your own shop. |
| `4-dashboard-store.png` | Кабинет: магазин Shopware после подключения | What the service sees for your shop: orders received, scheduled sync, payment attempts. |
| `5-dashboard-incident.png` | Кабинет: инцидент «оплачен без списания» | An order marked as paid without a capture at the provider, with the amounts and what to check. |
| `6-dashboard-incidents.png` | Кабинет: список инцидентов | Open incidents across your shops. |

5 и 6 — те же, что в каталоге WordPress.org: на них нет ничего от платформы, только название магазина.

Немецкий набор — `plugins/shopware-app/store-assets/de/` (снят тем же путём: администрирование Shopware на немецком, витрина по адресу `/de`, кабинет на немецком):

| Файл | Подпись (de) |
|---|---|
| `1-admin-code-eingeben.png` | Öffnen Sie die App unter Erweiterungen und fügen Sie den Verbindungscode aus Ihrem Business-Watchdog-Konto ein. |
| `2-admin-verbunden.png` | Verbunden: Business Watchdog liest Bestellungen und Zahlungsstatus, nur lesend. |
| `3-storefront-bestellung-abschliessen.png` | Der Checkout bleibt unverändert: Die App fügt nichts Sichtbares hinzu und meldet nur an Ihren eigenen Shop. |
| `4-dashboard-shop.png` | Was der Dienst für Ihren Shop sieht: empfangene Bestellungen, geplante Synchronisierung, Zahlungsversuche. |
| `5-dashboard-vorfall.png` | Eine als bezahlt markierte Bestellung ohne Einzug beim Zahlungsanbieter, mit Betrag und Erklärung. |

Списка инцидентов в немецком наборе нет: на локальном стенде в нём видны инциденты WooCommerce-плагина («Das Shop-Plugin meldet sich nicht»), на странице Shopware App они сбивали бы с толку.

Иконка: `apps/backend/resources/shopware-store/plugin.png` (256×256, 23 КБ). Логотип производителя: тот же знак или `plugins/woocommerce-watchdog/.wordpress-org/icon-256x256.png`.
