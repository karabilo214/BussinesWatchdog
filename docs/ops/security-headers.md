# Заголовки безопасности (Steps 308 и 313, ADR 0085)

Реализованы локально; на боевой сервер ещё не выложены. Бэклог целиком не закрыт. В Step 313 снято исключение TT для Paddle после проверки настоящего SDK, checkout и PayPal popup: [протокол](../reports/security-paddle-2026-10-29.json).

| Поверхность | CSP для скриптов | Trusted Types |
|---|---|---|
| Публичные страницы, формы, результаты бесплатной проверки | nonce + strict-dynamic | enforce |
| `/owner/` и редактор | хеш входного бандла + SRI + strict-dynamic | enforce, очищающая DOMPurify policy |
| `/app/` | хеш входного бандла + SRI + strict-dynamic | enforce, узкая Paddle policy |
| `/{locale}/pay` | nonce + strict-dynamic | enforce, bootstrap до SDK |
| Вложения/media | прежняя отдельная sandbox CSP | без ослабления |
| Редиректы и ошибки Caddy | object/base/frame/form baseline | без исполняемого интерфейса |

COOP `same-origin-allow-popups`, XFO `SAMEORIGIN`, `frame-ancestors 'self'`, nosniff и Referrer-Policy присутствуют на SaaS ответах и редиректах основного/www/mail имён. Политика SaaS не навязывается страницам тестовых WooCommerce/Shopware. CSS допускает inline для готового встроенного stylesheet, Vue и Tiptap; JavaScript inline без nonce/hash и eval запрещены.

## Внедрение

1. Перед выкладкой проверить HTTPS всех имён из Caddyfile и DNS-зоны (сертификаты не игнорировать). Известные имена 2026-10-29: основной домен, www, mail, woo-test, shopware-test; HTTP-запросы не заменяют эту проверку. `includeSubDomains` распространяется и на будущие имена.
2. Для наблюдения CSP выставить в production `.env` одновременно `SECURITY_CSP_REPORT_ONLY=true` (Blade) и `CSP_HEADER=Content-Security-Policy-Report-Only` (статические SPA). Пройти сценарии ниже с обработчиком `securitypolicyviolation`/DevTools. Отчёты никуда не отправляются автоматически: не сохранять полный URL/query, HTML, cookies или тела запросов с PII. Для временного сбора серверных отчётов нужен отдельный санитизированный endpoint.
3. После чистого прохода: `SECURITY_CSP_REPORT_ONLY=false`, `CSP_HEADER=Content-Security-Policy`, обычный git deploy. На локальном стенде уже пройдены оба режима. С Step 313 TT включён также для Paddle: исключений Report-Only нет. Отдельный общий staging-переключатель по-прежнему действует.
4. `HSTS_POLICY="max-age=300; includeSubDomains"` — первый короткий этап. После суток наблюдения HTTPS используемых поддоменов — `max-age=86400; includeSubDomains`, затем после устойчивой недели — `max-age=31536000; includeSubDomains`. Изменения вступают в силу через обычный rollout: `web-static stage/switch` + `caddy reload`, без пересоздания Caddy. Допустим также прежний `max-age=31536000` для возврата к политике без поддоменов; ранее закешированный includeSubDomains немедленно не отменяется. HSTS на HTTP не действует.
5. `preload` и подача домена в preload list — отдельное решение после полного инвентаря DNS/HTTPS и обсуждения длительного отката. Publisher намеренно отклоняет произвольное значение HSTS и preload.
6. Backend, фронтенды, `publish.sh` и Caddyfile выпускаются вместе. Для SPA publisher записывает immutable root рядом с CSP: переключение current до reload не смешивает HTML и политику разных релизов. Не заменять это импортом хешей в конфигурацию со статическим root на current.

## Повторяемая проверка

После сборки фронтендов:

```sh
node apps/frontend/scripts/security-smoke.mjs
# URL задаётся BW_SECURITY_URL. Для локального тестового сертификата:
BW_SECURITY_URL=https://localhost:8443 BW_SECURITY_LOCAL_TLS=1 node apps/frontend/scripts/security-smoke.mjs
# Только на стенде с обеими политиками в Report-Only:
BW_SECURITY_REPORT_ONLY=1 node apps/frontend/scripts/security-smoke.mjs
```

Нужны Playwright из `apps/browser-worker` и его Chromium; по умолчанию URL — локальный Caddy `http://127.0.0.1:8088`. На production не задавать `BW_SECURITY_REPORT_ONLY=1` или игнорирование TLS. Скрипт читает публичные страницы/формы входа и ошибки, затем использует отдельный browser context с **синтетическими API fixtures** для пароля/MFA, Tiptap, CodeMirror и sandboxed srcdoc. Это проверка DOM/CSP собранного frontend, не утверждение о настоящем MFA или платёжном SDK. Инъекции выполняются только в изолированном browser context, данные сайта не записываются.

Дополнительно проверить HTTP/HTTPS root и www/mail редиректы, `/app`, `/app/`, `/owner/`, 403/404/413/500, immutable-кеш JS/CSS и недоступность `security.caddy`; несколько обновлений страницы должны давать разные nonce, соответствующие атрибутам script. HTML с nonce должен быть `private, no-store`, SPA HTML — `no-cache`. Backend-тест сохраняет sandbox CSP файлов.

Для проверки после выкладки: настоящая регистрация/подтверждение почты, вход/MFA/passkey, редактор с сохранением/публикацией/preview, бесплатная проверка с поллингом результата и контроль Paddle на боевом origin. Локальная проверка настоящего Paddle выполнена в Step 313: `/pay` и production bundle кабинета дошли до пустой карточной формы реального sandbox checkout; окно PayPal sandbox открылось до формы входа и было закрыто без ввода аккаунта. Production Initialize использовал публичный live client-side token с публичной страницы, без live-транзакции: SDK, CSS и Retain/ProfitWell загрузились HTTP 200. У нашей CSP/TT нет нарушений; произвольные HTML, JS и script URL блокируются.

В ходе проверки исправлена блокировка stylesheet Paddle: разрешены только точные live/sandbox URL `/paddle/v2/assets/css/paddle.css`. Общий `@bw/security/paddle` разрешает в default HTML policy только два проверенных CSS-текста SDK и пустую строку, а ScriptURL — только фиксированный SDK URL и bootstrap Retain с единственным допустимым `auth`. Произвольный текст JavaScript, markup и другие URL отвергаются; преобразования TrustedScript нет. Обновление CSS-текстов upstream SDK требует пересмотра whitelist и повторного smoke; не ослаблять политику до пропуска любых строк.

Paddle sandbox выдаёт собственное **Report-Only** предупреждение о frame-ancestors для нестандартного localhost-порта; оно записано отдельно и не относится к enforced policy нашего документа. Прерванные telemetry-запросы при закрытии iframe тоже сохранены, без заявления об успешной телеметрии. Внешние iframe имеют собственные политики. 3DS, авторизация PayPal и завершение платежа не выполнялись.

Повторить PageSpeed на боевом URL после выкладки и сохранить дату/URL/версию, конкретные security findings. Локальный Lighthouse не является подтверждением production и не означает, что все аудиты прошли: в 13.6.0 часть имеет `notApplicable`, а короткий HSTS этап и отсутствие preload дают ожидаемые замечания. Оценка категории сама по себе не критерий закрытия.

Локальные результаты — [отчёт](../reports/security-headers-2026-10-29.json). Миграции и HTTP API contracts не менялись. Трассировка: ТЗ §19, §31, §35.5, security checklist `spec/ACCEPTANCE.md`; панели — `spec/PANELS-ACCEPTANCE.md`.

## Настоящий Paddle smoke без списания

Использовать sandbox-настройки локального backend. Helper отказывается работать с production environment/token, создаёт только draft-транзакции без покупателя/платёжного средства и не создаёт локальные подписки:

```sh
docker compose run --rm -T --no-deps backend-cli php ../frontend/scripts/prepare-paddle-security.php > /private/tmp/bw-paddle-context.json
chmod 600 /private/tmp/bw-paddle-context.json
BW_PADDLE_CONTEXT=/private/tmp/bw-paddle-context.json BW_PADDLE_SCENARIOS=public-pay,customer-checkout BW_PADDLE_POPUPS=1 BW_PADDLE_REPORT=/private/tmp/bw-paddle-report.json node apps/frontend/scripts/security-paddle-smoke.mjs
docker compose run --rm -T --no-deps backend-cli php ../frontend/scripts/prepare-paddle-security.php --cancel < /private/tmp/bw-paddle-context.json
```

URL локального Caddy задаёт `BW_SECURITY_URL` (по умолчанию https://localhost:8443); тестовый сертификат игнорируется только в изолированном browser context. Browser helper допускает только localhost/loopback для такого сертификата. `public-pay` читает client token из локальной конфигурации backend; `customer-checkout` подставляет **наши API-ответы**, но использует настоящий bundle, настоящий Paddle CDN/API/iframe и независимую sandbox-транзакцию. Карта и PayPal credentials не заполняются; кнопка Subscribe now не нажимается. После закрытия helper `--cancel` читает созданные транзакции и различает payment attempts и capture: отказывается от authorized/captured/unknown, проверяет отсутствие subscription, затем отменяет только собственные draft/ready транзакции с меткой `security_headers_no_charge_smoke`.

Для отдельного `production-startup` в приватный context добавляется только **публичный** `live_client_token`, прочитанный с публичной `/en/pay` через проверенный HTTPS; API key в браузер/отчёт не передаётся. Этот сценарий вызывает только Initialize, не открывает/не создаёт live checkout. Список сценариев по умолчанию включает все три. Без публичного live token запускать только первые два, как в команде выше. Протокол не содержит query string, credentials, token или значений полей.

Evidence Step 313: [настоящий SDK/Paddle/PayPal](../reports/security-paddle-2026-10-29.json). [Paddle: Initialize и загрузка Retain только в live](https://developer.paddle.com/paddle-js/methods/paddle-initialize/).

После фактического smoke API подтвердил 0 authorized/captured attempts и 0 subscriptions. Три PayPal attempts имели `action_required` (незавершённая авторизация); все три созданные sandbox-транзакции отменены. Непустой `payments` не считается списанием: [Paddle transaction payment attempt semantics](https://developer.paddle.com/api-reference/transactions/get-transaction/).
