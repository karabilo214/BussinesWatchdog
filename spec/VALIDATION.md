# Проверка пакета спецификации

Проверено 8 октября 2026 года для версии1.1. Ниже перечислены фактически выполненные проверки файлов, а не тесты рабочего SaaS.

- OpenAPI YAML, internal references, path parameters and unique operation IDs: 10 operations
- Extension OpenAPI: 20 operations, references/paths and positive/negative purchase/action DTO fixtures
- Positive Woo ingestion fixture conforms to the used JSON Schema subset
- Negative event schema fixture checks: 6
- Unique financial case IDs: 24; exact integer arithmetic checked: 22
- SQL static structural checks: 62 tables, 104 FK targets/unique keys, index columns and balanced field lists
- 42 numbered TZ sections, 50 unique acceptance IDs, all listed deliverables present
- Extension: five panel specifications, 16 unique billing cases, 28 additional acceptance scenarios

Ограничения проверки

- PostgreSQL server и полноценный PostgreSQL parser в текущем окружении недоступны. SQL проверен статически по структуре, полям, FK и уникальным ключам; выполнение DDL в PostgreSQL18 и Laravel migrations обязательно на D1.
- JSON Schema проверен ограниченным валидатором используемых конструкций. В CI реализации требуется стандартный Draft2020-12 validator и полная OpenAPI validation.
- Финансовые классификации fixtures являются ожидаемыми результатами; бизнес-движок пока не написан. Здесь проверена арифметика примеров, а не correctness будущего detector.
- WooCommerce, Stripe sandbox, browser network policy, load/restore/security tests ещё не выполнялись: это gates соответствующих implementation этапов.
- Нет DOCX/PDF: основной формат Markdown сохраняет схемы и code blocks для работы владельца и Codex.
