# Проверка пакета спецификации

Повторно проверено 26 октября 2026 года командой `python3 spec/validate_spec.py` для пакета версии 1.1 с последующими правками. Ниже перечислены проверки файлов, а не тесты рабочего SaaS.

- OpenAPI YAML, internal references, path parameters and unique operation IDs: 283 operations
- Extension OpenAPI: 25 operations, references/paths and positive/negative purchase/action DTO fixtures
- Positive Woo ingestion fixture conforms to the used JSON Schema subset
- Negative event schema fixture checks: 6
- Unique financial case IDs: 24; exact integer arithmetic checked: 22
- SQL static structural checks: 68 tables, 114 FK targets/unique keys, index columns and balanced field lists
- 43 numbered TZ sections, 50 unique acceptance IDs, all listed deliverables present
- Extension: five panel specifications, 16 unique billing cases, 29 additional acceptance scenarios

Ограничения проверки

- Скрипт проверяет SQL статически по структуре, полям, FK и уникальным ключам; он не запускает PostgreSQL и не проверяет применение Laravel migrations. Отдельные прогоны приложения на PostgreSQL 18 зафиксированы в `../docs/progress/`.
- JSON Schema здесь проверена ограниченным валидатором используемых конструкций. Проверка скрипта не заменяет runtime-тесты и полную OpenAPI-валидацию.
- Финансовые классификации fixtures являются ожидаемыми результатами; этот скрипт проверяет арифметику примеров. Реализация и тесты реестра FIN-01…24 зафиксированы отдельно в `../docs/reconciliation-registry.md` и `../docs/progress/reconciliation.md`.
- Совместимость WooCommerce/Shopware, Stripe и PayPal sandbox, безопасность браузерной сети и восстановление копий проверялись отдельно; их evidence и оставшиеся ограничения — в `../docs/readiness.md`, `ACCEPTANCE.md` и `PANELS-ACCEPTANCE.md`. Нагрузочный сценарий ACC-43 и полное восстановление на новом сервере ACC-41 остаются открытыми.
- Нет DOCX/PDF: основной формат Markdown сохраняет схемы и code blocks для работы владельца и Codex.
