# DTO и дополнительные контракты API

Этот каталог является нормативным дополнением к OpenAPI core. Перед реализацией соответствующих endpoints разработчик переносит DTO в `openapi.yaml` и JSON Schemas без изменения смысла. Все маршруты ниже имеют `/api/v1`, кроме WordPress и SaaS billing webhooks. Schema для ограниченного JSON config должна появиться в contracts до первого использования, а не оставаться произвольным `object` в рабочем API.

Версия1.1: billing DTO и flow этого каталога уточнены в `platform-billing-api.yaml` и `../panels/PUBLIC-SUBSCRIPTION-FRONTEND.md`; при расхождении они имеют приоритет для SaaS billing. Название invoice/payments в platform API относится к оплате нашей услуги, а в monitoring API — к данным магазина. Platform staff guard не принимает customer session.

## 1 Общие соглашения

- Response item возвращает DTO напрямую; list: `{data: DTO[], next_cursor: string|null}`. Request ID возвращается в `X-Request-ID` response header. Ошибки и ingestion ACK дополнительно содержат request_id в JSON, как в OpenAPI core. Framework automatic resource wrapper `data` для single item отключается.
- Входные unknown properties отвергаются, кроме явно documented metadata namespaces. Не отдавать внутренние поля Eloquent автоматически.
- `id`, `store_id`, `integration_id` — UUID; внешние IDs — string. Сумма — minor decimal string, currency+exponent обязательны.
- Не доверять входному tenant_id. Tenant выбирается из активного session membership; switch endpoint проверяет membership. Plugin integration выбирается по credential.
- Изменения конфигурации требуют `If-Match: "<version>"`, получают новый ETag. Concurrent update409. Response ETag обязателен для GET/PATCH versioned resources.
- Mutations создания с повторяемыми side effects требуют `Idempotency-Key`; TTL24ч. HTTP203/207 не применяются к user CRUD; multi-status только ingress.
- Email normalization — trim+lower для matching; письмо отправлять по сохранённому verified адресу. Пароль12+ символов, maximum128, Unicode допустим, не обрезать молча. Password breach check SHOULD; login generic failure не раскрывает существование email.
- Перечисленные rate limits — пилотные defaults в config: login5/мин/IP+email, reset3/час/account, pairing5 попыток/code и20/час/IP, signup3/час/IP. Actual IP только transient protection; не хранить в domain events.

## 2 Аутентификация и членство

| Маршрут | Request | Response и side effect |
|---|---|---|
| POST /auth/register | email, password, password_confirmation, name1..100, locale ru/en/de | UserDTO, verification отправлена, tenant автоматически создаётся только с organization_name |
| POST /auth/login | email, password, remember boolean | UserDTO + secure HttpOnly same-origin session; MFA challenge вместо session если нужно |
| POST /auth/logout | пусто | 204, session revoked |
| GET /auth/me | — | UserDTO, active_tenant_id, MembershipDTO[] |
| POST /auth/password-reset/request | email | 202 одинаковый ответ для existing/nonexisting |
| POST /auth/password-reset/complete | token, email, password, confirmation | 204, token one-time и session invalidation policy |
| POST /auth/email-verification/resend | пусто | 202, rate-limited |
| GET /auth/email-verification/{id}/{hash} | signed expiring URL | Redirect в кабинет, verified flag |
| POST /tenants | name1..100, timezone IANA, locale | TenantDTO, owner membership |
| POST /tenants/{id}/activate | пусто | New session active tenant при наличии membership |
| GET /memberships | — | MembershipDTO[] только active tenant |
| POST /invitations | email, role admin/operator/viewer | InvitationDTO без token; отправка invitation email |
| POST /invitations/accept | token | MembershipDTO, consumes token, user email verified match |
| PATCH /memberships/{user_id} | role, expected_version если применяется | MembershipDTO, защита last owner |
| DELETE /memberships/{user_id} | — | 204, auth context invalidated, last owner guard |
| POST /ownership-transfer | target_user_id, current_password/MFA | 204, atomic role transfer and audit |

UserDTO: id, name, email, locale, email_verified boolean, mfa_enabled boolean; без password_hash/mfa_secret. MembershipDTO: user_id, tenant_id, role, created_at. TenantDTO: id, name, timezone, locale, status, config_version. InvitationDTO: id, email, role, expires_at, state; token выдаётся только в invitation URL нужному получателю, не в GET списке.

MFA endpoints и implementation выбираются D1 ADR через штатный механизм Laravel; backup codes hashed, enrollment требует confirmation OTP. Системный operator enrollment обязателен до production.

## 3 Stores и domain verification

`POST /stores`: name, base_url HTTPS, timezone IANA, locale, default_currency. Response201 StoreDTO. URL normalized origin и path сохранены по policy; credentials in URL, fragments и private destinations rejected. Не выполнять unrestricted network request ради validation.

`PATCH /stores/{id}`: optional name/timezone/locale/default_currency/status paused|active/browser_enabled/telemetry_enabled; version обязателен. Изменение base_url — отдельный reverify flow: flags browser_enabled reset, pending check jobs cancelled. Currency change не конвертирует исторические суммы. Delete — soft deleted только при inactive integration/explicit revocation workflow.

StoreDTO: id, name, base_url, platform, timezone, locale, default_currency, status, verified_at|null, browser_enabled, telemetry_enabled, config_version, coverage summary, last successful check time, active_incident_count. Coverage fields не принимаются от frontend.

`POST /stores/{id}/verify` input method plugin_challenge|dns. Response202: verification_id, method, public challenge инструкции, expires_at. Challenge secret не является integration credential. `plugin_challenge` является платформенно-нейтральным способом проверки через installed connector/plugin/app и не привязан только к WordPress/WooCommerce. `GET /stores/{id}/verification`: state, verified_origin, expires_at, reason_code. DNS verification bound exact hostname; wildcard ownership без явной проверки запрещено.

`POST /stores/{id}/pairing-codes`: input пусто, admin. Response201: pairing_code одноразово, expires_at, saas_endpoint. Code не логировать. WordPress pairing HTTP endpoint должен быть доступен только admin `manage_woocommerce` + WP nonce; публичный REST endpoint не должен создавать credentials.

`POST /pairing/exchange`: pairing_code, install_id UUID, plugin_version, base_url. Response201: integration_id, key_id, secret, secret_encoding=base64, signature_version=1. Secret exactly32 random bytes; HMAC uses decoded bytes, не ASCII base64. At-most-once exchange: потерянный response требует новый pairing code/reconnect, а не повторное раскрытие секрета.

## 4 Интеграции и sync

`GET /stores/{id}/integrations` — IntegrationDTO[]: id, provider, mode, external_account_id masked если нужно, status, source_authority, capabilities, connector_version, API version, last_heartbeat_at, last_successful_sync_at, watermarks, health reason codes. Secrets не включены.

`POST /stores/{id}/integrations/stripe`: mode live|test, restricted_api_key, webhook_secret при external provisioning, expected_account_id optional. Request TLS, write-only secret fields; Response201 IntegrationDTO и webhook_endpoint. Backend проверяет account/mode read-only и capability set. Несовпадение account ID422. Как создать webhook в Stripe Dashboard без write API key — инструкция owner; endpoint принимается active только после signing secret verification smoke.

`POST /integrations/{id}/rotate`: kind plugin_hmac|stripe_api|stripe_webhook, new_secret для provider credential; для plugin secret создаёт сервер. Response masked credential metadata, новый secret только для authenticated plugin provisioning route, overlap24ч. Public user response не выдаёт existing secret.

`POST /integrations/{id}/revoke`: reason string5..500; Response204, credentials revoked, sync/check capabilities updated, audit. Нельзя только скрыть integration в UI, оставив active credentials.

`POST /integrations/{id}/sync`: kind delta|backfill|audit|targeted, from/to для backfill, external_id для targeted. Response202 sync_run_id,status. Bounds retention90д по умолчанию, max requested range365д при разрешённом plan, targeted bounded IDs. One active sync/object family, returns existing eligible run либо409; Idempotency-Key required.

`GET /sync-runs/{id}`: kind/status/object_family, window, counters, coverage, started/finished, safe error, progress approximate. Progress процент не заявлять точным при меняющемся общем числе provider objects.

## 5 Findings и matching

`GET /stores/{id}/findings`: from,to,status,rule_code,currency,limit,cursor. DTO: id,run_id,order_id|null,payment_id|null,rule_code,status,reason_code,currency/exponent,expected_minor|null,actual_minor|null,difference_minor|null,G/C/RW/RP|null, evaluated_at, coverage snapshot, evidence_refs. Нулевые значения выводить нулями, null — «Неизвестно».

`GET /orders/{id}`: StoreOrderDTO plus latest finding per rule, capture/refund operations, active allocations, history revisions. Без customer/address data. `GET /payments/{id}` аналогично, mode/account scoped. Source admin link в Woo может выводиться, но SaaS не открывает скрытую admin session.

`GET /stores/{id}/unmatched-payments`: reason,candidates[] with confidence exact_candidate/manual_review, provider refs, amount/currency/mode. Candidate по сумме/времени — suggestion, не confirmed.

`POST /payment-allocations`: order_id, payment_id, capture_transaction_id, amount_minor, currency, reason5..1000. Admin+Idempotency-Key. Transaction locks payment/capture, validates tenant/store/mode/currency and amount, creates manual allocation and audit, emits dirty_order. Response201 AllocationDTO. Race oversubscribe409.

`POST /refund-allocations`: refund_id, refund_transaction_id, payment_allocation_id, amount_minor,currency,reason. Validate refund→order matches allocation order; transaction kind refund; amount bounds across active links. Response201. `POST /payment-allocations/{id}/revoke` and refund equivalent: reason, Response204, immutable revoke with audit.

`POST /stores/{id}/reconciliations`: order_ids optional≤100 or from/to window, currency optional, dry_run boolean default false. Response202 run_id; admin/operator allowed by policy, Idempotency-Key. Replay historical notifications disabled unless explicitly requested by admin-reviewed operational action.

`POST /stores/{id}/exports`: kind findings|transactions|incidents, date/filter config, format csv. Response202 export_id. Poll `/exports/{id}` returns state/download URL after role authorization. Export files are separate private artifacts category, can use generalized export storage table at D8; current `artifacts` table is browser-specific and should not be abused by setting fake attempt_id. Framework/job table chosen in ADR.

## 6 Проверки и artifacts

`GET /stores/{id}/scenarios`: ScenarioDTO[]: id,name,mode,version,enabled,adapter_version,product_external_id,interval_seconds,next_due_at,supported_steps,untested_components. Effective adapter DSL не вводится напрямую через обычный frontend.

`POST /stores/{id}/scenarios`: name, product_external_id, interval_seconds≥300, shipping_fixture profile, gateway method. Backend obtains verified URLs from plugin, generates adapter definition. Response201 ScenarioDTO. Validate simple in-stock product; variations LATER.

`PATCH /scenarios/{id}`: enabled, interval_seconds, product_external_id, synthetic_country/postcode fixture IDs, expected gateway ID. If-Match required. Arbitrary selectors/javascript/network origins не принимаются обычным owner form. Reviewed custom adapter config — operational feature, отдельный audit.

`GET /checks/{run_id}`: logical status, scenario version, trigger, scheduled/started/finished, attempts summaries, steps assertions, safe diagnostics, ready artifacts IDs. Lease token/hash и worker secret отсутствуют.

`POST /checks/{run_id}/cancel`: reason. Backend increments fence/revokes active lease; cancelled worker cleanup still allowed but result no longer changes run. Cancellation не является site failure.

`GET /artifacts/{id}/download`: response200 {url,expires_at,content_type,size_bytes}, Cache-Control:no-store, signed URL60с. Authorization at issuance plus private object ACL. Download link forward within TTL remains bearer access; UI пояснение при наличии share функции, generic public share отсутствует.

`POST /internal/v1/browser/attempts/{id}/artifacts`: lease token/fence, kind screenshot|trace|diagnostics, content_type, expected size≤10MiB screenshot/diagnostics, trace≤50MiB, sha256, redaction_version. Response201 artifact_id, upload_url,expires_at. Kind trace требует enabled sanitized trace policy. `POST .../artifacts/{artifact_id}/finalize`: verifies uploaded metadata and content checks; ready/rejected. Final browser result accepts only ready owned artifact IDs.

### Декларативный scenario DSL

```json
{
  "version": 1,
  "adapter_version": "woo-classic-1",
  "mode": "payment_form",
  "store_origin": "https://shop.example.invalid",
  "product_url": "https://shop.example.invalid/product/test-item/",
  "cart_url": "https://shop.example.invalid/cart/",
  "checkout_url": "https://shop.example.invalid/checkout/",
  "steps": [
    {"code":"product","action":"goto","target":"product_url","timeout_ms":20000},
    {"code":"add_to_cart","action":"click_safe","selector":"[data-watchdog='add-to-cart']","allowed_effect":"session_cart","timeout_ms":20000},
    {"code":"cart","action":"assert_cart","product_external_id":"demo-1","quantity":1,"timeout_ms":20000},
    {"code":"checkout","action":"goto","target":"checkout_url","timeout_ms":20000},
    {"code":"payment_form","action":"assert_visible","selector":"[data-watchdog='payment-method']","timeout_ms":20000}
  ]
}
```

`goto.target` enum product_url|cart_url|checkout_url; URL не из arbitrary string. `click_safe.allowed_effect` only session_cart|ui_toggle; `fill_synthetic` value берётся из declared synthetic profile и не допускает card number/cvv. `select` option fixed safe value, `assert_cart` exact product+quantity, `wait_response` reviewed endpoint category and expected status class. Definition JSON Schema с `oneOf` по action MUST добавить в D4 и проверить negative forbidden action fixture. Network policy independently rejects order/payment writes. Пример DSL не гарантирует корректность selector на любой теме.

## 7 Incident actions и suppression

`POST /incidents/{id}/comments`: text1..4000 plain text. Response201 activity_id,actor,created_at,text escaped. Comment не обновляет first/last failure times.

`POST /incidents/{id}/snooze`: until RFC3339 future max30д, reason5..1000. Creates suppression, state не меняется. Response201 suppression_id. `POST /suppressions/{id}/revoke` expires now, audit; fresh still-active incident can re-notify once per dedupe transition.

`POST /stores/{id}/maintenance`: starts_at,ends_at≤30д duration,reason, scope browser|financial_notifications|all_notifications. Maintenance не скрывает незавершённые финансовые данные; scheduled checks могут pause, sync продолжает идти если не suspended. Детекторы сохраняют facts с suppressed indication. UI показывает active maintenance.

## 8 Channels rules и billing

`POST /notification-channels`: kind email|telegram,label,preferences. Email input destination_email требует verification code. Telegram response binding_code TTL15мин, instruction; bind через bot message с кодом, server confirms rightful channel. `PATCH /notification-channels/{id}` enabled/preferences,label; change destination re-verifies, old encrypted value заменяется с audit. `POST .../test`: safe test notification per minute, без customer data.

PreferencesDTO: severities array info/warning/critical, quiet_hours {timezone,start_hhmm,end_hhmm}, critical_bypass_quiet_hours false default, daily_digest bool, reminder_hours|null≥24. Email recipients каждый отдельный channel. `GET` возвращает masked destination и verified/enabled/health, не destination ciphertext.

`PATCH /rules/{id}`: parameters matching rule schema, enabled, If-Match. Create immutable new version, effective_at backend now; old config retained. Currency thresholds массив {currency,amount_minor}. Не принимать глобальный financial threshold без currency. Rule detail содержит explanation of defaults и configuration history.

`GET /billing/subscription`: SubscriptionDTO без provider secret: plan,status,period,entitlements,usage,grace_until. `POST /billing/checkout`: plan_code, return_path same-origin allowlist. Response session_url generated trusted billing provider, Idempotency-Key. `POST /billing/cancel`: at_period_end true; owner+reauth. Provider webhook в отдельном integration/module verify подпись/idempotency. Конкретные SDK/webhook event names выбираются только после owner decision; store Stripe key для оплаты подписки использовать нельзя.

`POST /privacy/deletion-requests`: confirmation tenant name + reauth, Response202 deletion_request_id. `GET .../{id}` scoped state. completed journal должен сохраняться отдельно от purged tenant rows, чтобы восстанавливаемые backups не воскресили удалённые данные; production schema этого operational journal добавить в ops ADR. Domain table — tracking текущего workflow, не единственное хранилище deletion tombstone.

## 9 WordPress REST surfaces

Prefix `/wp-json/business-watchdog/v1`. Routes: admin `/pair`, `/disconnect`, `/settings`, `/diagnostics`; public `/challenge/{id}` только returning bound random challenge; signed `/manifest` и `/snapshots` только для scoped connector service по отдельному inbound credential; public telemetry `/observe` только P2 with session token/origin/rate limit.

Inbound SaaS pull snapshot optional: primary P0 plugin pushes events; rescan plugin itself implements. Не создавать публичный endpoint «все заказы» без authentication. WordPress capabilities `manage_woocommerce`, WP nonce и sanitization обязательны для admin routes. Public challenge не возвращает order IDs, версии private plugins и secrets.

ManifestDTO: install_id, schema_version, platform versions, checkout_mode, hpos, cart/checkout origin-validated URLs, configured test product {external_id,url,in_stock,simple,virtual}, supported gateway IDs and adapter capabilities. SaaS treats plugin claim as configuration input, validates origins/network policy independently.

## 10 Error code registry

Обязательные стабильные codes: invalid_json, schema_invalid, schema_unsupported, signature_invalid, nonce_replayed, timestamp_out_of_range, credential_revoked, pairing_expired, pairing_consumed, tenant_forbidden, version_conflict, event_id_conflict, revision_conflict, quota_exceeded, integration_stale, source_incomplete, gateway_unsupported, currency_mismatch, match_ambiguous, allocation_exceeded, lease_expired, stale_fencing_token, result_conflict, forbidden_mutation, unsafe_destination, monitoring_blocked, adapter_unsupported, artifact_redaction_failed, infrastructure_unavailable.

Provider error message не подставляется в UI напрямую. Translation key привязан к stable code, подробности sanitized. HTTP status и machine code имеют разные функции: 409 может быть stale version либо event conflict; клиент использует code.

Все user-facing DTO/message fields, validation errors, notification templates, export headers and public/portal content MUST support `ru`, `en`, `de`. API `code` values remain stable English machine identifiers; localized text is selected by active user/tenant locale or explicit `locale` parameter.
