# Инструкция для реализации Business Watchdog

Этот файл предназначен владельцу и Codex. При создании репозитория перенесите правила в `AGENTS.md`; не запускайте здесь разработку всего SaaS только потому, что файл открыт. Пользователь сейчас заказал спецификацию, а реализация — отдельная задача.

## Порядок чтения

1. Прочитайте `Business-Watchdog-TZ.md`, особенно разделы 2, 9–14, 18–20, 25–28 и 36–39.
2. Прочитайте `database/schema.sql`, `contracts` и `ACCEPTANCE.md`.
   Для customer/panels/billing прочитайте также пять ТЗ в `panels`, `database/platform-billing-extension.sql`, `contracts/platform-billing-api.yaml` и `PANELS-ACCEPTANCE.md`. Это обязательное дополнение1.1, а не optional backlog.
3. Проверьте реальные `AGENTS.md`, code, текущие migrations и `docs/progress.md` репозитория.
4. Определите этап P0/P1/P2. Не включайте P2 автоматически в P0.

## Инварианты

- Денежные значения — minor units bigint/string. Float и JS Number запрещены.
- Woo paid marker не является независимым доказательством capture.
- Charge/PaymentIntent события не суммируются как два платежа.
- Captures, refunds, fees и payouts имеют разные смыслы.
- Unknown/stale данные не являются нулём.
- Tenant scope обязателен в service, repository, job, export и artifact access.
- Browser P0/P1 не создаёт реальную покупку, не списывает и не возвращает деньги.
- Node не читает сериализованные Laravel jobs.
- Дубликаты и перестановка событий должны быть безопасны.
- Trace/screenshot не должны содержать secrets и реальные PII.
- ИИ в текущем проекте не реализуется и зависимости для него не добавляются.

## Что нельзя делать без явной фиксации решения

Нельзя незаметно менять финансовую модель, поддержку gateway, checkout coverage, источники доказательств, grace/tolerance и порядок tenant authorization. Новое business assumption или default записывается в ADR/config и явно показывается владельцу. Не придумывайте отсутствующее provider поле или hook; проверьте документацию и sandbox. Не заменяйте unsupported сценарий притворным successful check.

Документированный proposed default из ТЗ можно внедрять без повторного запроса разрешения, если текущая задача это включает. Для неизвестного API сначала выполняйте доступный read-only research/spike, затем задавайте конкретный вопрос только если решение зависит от владельца. Не блокируйте независимую работу ожиданием несущественного выбора.

## Первый реализуемый шаг

Сначала D0 compatibility spike и D1 bootstrap. Создать ADR с точными versions. Подготовить fixtures Classic/Blocks, HPOS/legacy. Проверить Stripe gateway metadata и side effects открытия checkout. Реализовать tenant auth/store и миграции первыми, затем ingress durable slice. Browser network enforcement проектировать до production check.

Начальная task template:

```text
Задача: [один вертикальный срез]
Этап: P0/P1/P2
Требования: [разделы ТЗ и acceptance IDs]
Входы: [contracts/fixtures/config]
Ожидаемый результат: [конкретное поведение]
Ограничения: [известные capabilities]
Проверки: [команды и сценарии]
Миграции и rollback: [план]
Done: [демо + тесты + docs/progress.md]
```

## Как завершать задачу

Сообщить что изменено, какие tests реально выполнены и результат, какие migrations/contracts затронуты, какие gaps остались. Обновить traceability. Не писать «протестировано со Stripe», если были только JSON fixtures; не писать «checkout полностью работает», если тестировалась только форма оплаты.

Backend/controller, domain rules и UI не должны независимо копировать разные thresholds. Применяйте versioned effective config. Значимые financial/security/concurrency тесты обязательны; тесты обратимой косметической UI правки необязательны.

После успешных релевантных проверок не повторяйте широкий test suite без причины. Не добавляйте необязательные внешние integrations и микросервисы ради абстракции. При обнаружении противоречия spec/code сначала исправляйте смысл и documented contract, затем implementation.
