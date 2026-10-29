# ADR 0085: Browser security headers

Дата: 2026-10-29. Статус: реализовано локально, Steps 308 и 313; production rollout остаётся открытым, Paddle TT проверен и включён локально.

Связь: ТЗ §19 (безопасные проверки), §31, §35.5; дополнительный security checklist `spec/ACCEPTANCE.md`; PageSpeed backlog.

## Решения

- Публичные Blade-страницы получают случайный 256-битный nonce на каждый ответ и `script-src 'nonce-…' 'strict-dynamic'`. Nonce добавляется только явным атрибутом доверенных шаблонов, не обходом/переписыванием готового HTML. HTML с nonce получает `private, no-store`: прежний общий кеш 60 с повторно использовал бы nonce. CSS по-прежнему встроен; CSS/JS/шрифты с хешированными именами сохраняют immutable-кеш.
- Кабинеты остаются статическими: сборка добавляет SHA-384 SRI атрибут входному module script и создаёт соответствующую CSP. Заголовок не использует общий фиксированный nonce. Динамические module imports разрешаются через `strict-dynamic`.
- `web-static stage` привязывает `root` и CSP к одному `releases/<release>`, а не к изменяемой ссылке `current`. При `switch` и до `caddy reload` Caddy продолжает отдавать прежние HTML и CSP; reload переключает их вместе. Сохраняется ADR 0080. Режим CSP и HSTS также записываются в файлы релиза: изменение настроек не пересоздаёт Caddy.
- `object-src 'none'`, `base-uri 'none'`, `form-action 'self'`, `frame-ancestors 'self'`; дополнительно XFO `SAMEORIGIN`. Предпросмотр редактора остаётся `sandbox=""`. Встроенный CSS и стили Vue/Tiptap разрешены только в `style-src`; `unsafe-inline`/`unsafe-eval` для скриптов отсутствуют. HTTPS-картинки сохраняют прежнюю возможность редактора (`PageSanitizer` разрешает HTTPS media).
- COOP — `same-origin-allow-popups` для совместимости с платёжными окнами. COEP не включается: изоляция SharedArrayBuffer продукту не требуется.
- Trusted Types принудительно применяются на всех публичных страницах и в обоих кабинетах (Paddle добавлен в Step 313). Опрос бесплатной проверки использует закрытую политику только для HTML ответа известного same-origin Blade endpoint; URL проверяется, redirects запрещены. В owner default HTML policy очищает legacy sinks Tiptap/ProseMirror и `srcdoc` через DOMPurify 3.4.17. Полный документ со стилями допускается только для `HTMLIFrameElement srcdoc`; обычные фрагменты не допускают style/script/iframe/object/embed/base. Преобразования Script и ScriptURL не создаются. Vue использует собственную политику для compiled templates и серверного MFA SVG; серверная очистка контента сохраняется.
- Step 313: на клиентском кабинете и `/pay` TT также **enforce**, после реального SDK smoke. Политика `default` разрешает только два проверенных CSS-текста Paddle и пустые clears, фиксированный SDK URL и Retain bootstrap URL с одним auth-параметром. Даже произвольный JS/plain text отвергается — это не общий pass-through. Public checkout импортирует маленький security bootstrap до вызова SDK; клиент использует общий `@bw/security/paddle`. Исправлена обнаруженная реальным browser run блокировка live/sandbox stylesheet: разрешены точные URL Paddle CSS. Sandbox checkout в обоих интерфейсах дошёл до пустой карточной формы, PayPal sandbox popup — до входа; production Initialize с публичным live token загрузил Retain/ProfitWell без создания live-транзакции. 3DS/авторизация/оплата не выполнялись. При изменении CSS upstream whitelist пересматривается с повторным smoke.
- Caddy задаёт базовую защиту для статических файлов, HTTP/HTTPS редиректов и собственных ошибок. Middleware сохраняет более строгий CSP `sandbox` вложений/media. На HTTP HSTS не отправляется.
- HTTPS всех используемых имён проверен без отключения проверки сертификата: основной домен, www, mail, woo-test и shopware-test. Первый этап HSTS — `max-age=300; includeSubDomains`, затем сутки и год после наблюдения. Короткий этап намеренно ещё вызывает замечание Lighthouse. `preload` и подача в список не включаются без отдельного решения владельца домена.

## Проверка и ограничения

Процедура и evidence — `docs/ops/security-headers.md`, `docs/reports/security-headers-2026-10-29.json`. Локальная проверка не заменяет production PageSpeed и живой платёжный smoke. Миграций, изменений схемы/денег/tenant scope и API-контрактов нет.

## Источники

- [Chrome: strict CSP audit](https://developer.chrome.com/docs/lighthouse/best-practices/csp-xss/).
- [Chrome: Trusted Types audit](https://developer.chrome.com/docs/lighthouse/best-practices/trusted-types-xss).
- [MDN: COOP и платёжные окна](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Cross-Origin-Opener-Policy).
- [Caddy: deferred/default response headers](https://caddyserver.com/docs/caddyfile/directives/header).
- [DOMPurify: Trusted Types policies](https://github.com/cure53/DOMPurify#what-about-dompurify-and-trusted-types).

- [Paddle Initialize / Retain](https://developer.paddle.com/paddle-js/methods/paddle-initialize/).
