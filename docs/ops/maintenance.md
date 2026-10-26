# Обслуживание

Все команды `php artisan …` ниже выполняются в `apps/backend` (локально — PHP 8.4: `/opt/homebrew/opt/php@8.4/bin/php artisan …`; в Docker — `docker compose exec backend php artisan …`).

| Команда | Когда |
|---|---|
| `php artisan security:reencrypt-secrets` | После добавления нового ключа шифрования (`WATCHDOG_KEYRING_KEYS="2:base64:…"`, затем `WATCHDOG_KEYRING_CURRENT=2`): перешифровать ключи интеграций, адреса уведомлений и секреты аутентификаторов. Старый ключ убирать только после неё |
| `php artisan platform:create-owner <email>` | Добавить ещё одного владельца сервиса или восстановить доступ |
| `php artisan platform:reset-mfa <email> --reason="…"` | Сбросить приложение-аутентификатор сотрудника сервиса (потерян телефон): старый код перестаёт работать, все сессии завершаются, сразу привязывается новый; записывается в аудит. Остальных сотрудников приглашают и отключают в админке, раздел «Сотрудники» |
| Админка → «Сотрудники» → «Профиль» → «Отправить ссылку для смены пароля» (Step 268) | Сотрудник забыл пароль: за него пароль не задаётся, ему уходит одноразовая ссылка на `/owner/reset-password` (60 минут); действие подтверждается своим кодом, после смены все его сеансы завершаются. Свой пароль — «Мой профиль» (ссылка с именем в шапке). Фото сотрудников хранятся на диске `WATCHDOG_SITE_MEDIA_DISK` (`staff-photos/`); камера для селфи разрешена заголовком `Permissions-Policy` в `infra/production/Caddyfile` (применяется при выкладке, образ собирается заново) |
| `php artisan browser:worker-create <name> --rotate` | Сменить токен воркера |
| `php artisan telegram:webhook info\|set\|delete` | Проверить или перерегистрировать webhook Telegram |
| `php artisan billing:paddle-events` / `billing:process-events` | Вручную дочитать события Paddle (обычно делает планировщик) |
| `php artisan billing:paddle-transactions` (`--tenant=<id>`) | Дозагрузить из Paddle все транзакции (счета и оплаты) подписок текущего режима — после первого выпуска этой версии или если события потерялись |
| `php artisan billing:history-purge` | Внеочередное удаление истории, вышедшей за срок хранения пакета более 30 дней назад (обычно делает планировщик) |
| `php artisan billing:order-volume` | Внеочередной пересчёт объёма заказов магазинов и проверка лимита «заказов в месяц» пакетов (обычно делает планировщик) |
| `php artisan billing:plan-discounts` | Внеочередной пересчёт скидок следующего периода подписок (обычно делает планировщик; повторяет неудачные попытки) |
| `php artisan stripe:sync`, `paypal:sync`, `woocommerce-api:sync`, `shopware:sync` (`--audit`) | Внеочередная синхронизация: ставит задания в очередь для обработчиков; `--inline` — выполнить прямо в этом процессе, по очереди, без обработчиков |
| `php artisan platform:audit-purge` | Внеочередное удаление записей аудита платформы старше `WATCHDOG_PLATFORM_AUDIT_RETENTION_DAYS` (обычно делает планировщик) |
| `php artisan service:alerts` | Внеочередная проверка состояния «Сервиса»: письмо ответственному уходит только при переходе в «проблему» или при восстановлении (обычно делает планировщик) |
| `php artisan queue:failed` / `queue:retry all` | Посмотреть и повторить упавшие задания синхронизации |
| `php artisan reconciliation:work --stop-when-empty` | Разобрать очередь сверки до конца и выйти (например, после восстановления) |
| `php artisan reconciliation:process-dirty` / `reconciliation:nightly-sweep` | Внеочередной пересчёт сверки |
| `php artisan privacy:process-deletions` | Внеочередной шаг удаления организаций, у которых прошли 30 дней (ADR 0069; обычно делает планировщик каждые 5 минут). Каждый запуск продолжает с места остановки. Заявка в статусе `failed` (5 неудачных попыток) — посмотреть `error_code` в `data_deletion_requests` и лог `privacy.deletion_failed`, исправить причину, вернуть `status` на этап из `cursor->failed_stage` и `attempts = 0` |
| `php artisan privacy:deletion-journal` | Вывести журнал удалённых организаций и магазинов (JSON lines); его же пишет ночной бэкап |
| `php artisan privacy:replay-deletions <файл>` / `-` | После восстановления дампа заново удалить организации и магазины из самого свежего журнала (`docs/ops/backups.md`) |
| `php artisan reconciliation:recheck-open-incidents` | Поставить на пересчёт все заказы с открытыми денежными инцидентами по текущим правилам. **Один раз после выкладки, где менялись правила сверки** (Step 201, ADR 0068); пересчёт делают обработчики сверки |
| `php artisan shopware:sync --audit` | **Один раз после выкладки Step 211** (ADR 0073): перечитать заказы Shopware, чтобы у них появился ID транзакции оплаты для связи с платежами SwagPayPal. Признаки у старых платежей PayPal появятся сами при ежедневном полном проходе |
| `php artisan shopware:sync --audit` и `php artisan stripe:sync --audit` | После подключения Stripe к Shopware или пропуска синхронизации: перечитать заказы и платежи для связи PaymentIntent с транзакцией Shopware. Первый проход после Step 227 на Shopware-Test уже выполнен 2026-10-22; повторять его ради выкладки не требуется |
| `php artisan artifacts:purge` / `exports:purge` | Внеочередная очистка просроченных файлов |
| `php artisan checkout:observation-evaluate` | Внеочередная проверка правил наблюдения за оформлением заказа Shopware и WooCommerce: скрипт молчит, отправки заказа без заказов (обычно делает планировщик) |
| `php artisan checkout:payment-attempts-stuck` | Внеочередная отметка попыток оплаты Shopware и WooCommerce REST API без результата 30 минут (обычно делает планировщик) |
| `php artisan site:contact-notify` / `site:contact-purge` | Вручную отправить письма о новых обращениях с сайта / удалить закрытые обращения старше срока хранения (обычно делает планировщик) |
| `php artisan site:public-checks-purge` | Внеочередное удаление публичных диагностик магазина и их скриншотов старше 5 минут (обычно делает планировщик каждую минуту) |
