# ТЗ backend административных панелей и биллинга Business Watchdog

Версия1.1. Нормативное дополнение к модульному монолиту и базовой схеме1.0. Это backend нашей услуги и system staff API. **Не использовать** Woo integrations, `payments`, `financial_transactions` и магазинные Stripe keys для оплаты нашей подписки. ИИ отсутствует.

## 1 Модули и routes

Добавить PlatformIdentity, PlatformAccess, PlatformOperations, PlatformReports, SaaSBilling, PlanCatalog и PublicContent. Identity/Tenancy/Stores/Incidents остаются customer/domain modules. Использовать общий PostgreSQL instance, но role/connection и policies для tenant data и platform control разделить. Не создавать отдельные микросервисы только из-за двух панелей.

Routes customer `/api/v1`, public catalog `/api/v1/public`, staff `/platform-api/v1`, billing webhook `/webhooks/saas-billing/{provider}/{mode}`. Это **другой** route/secret/account по сравнению с `/api/v1/webhooks/stripe/{integration_id}` магазина. Internal browser routes unchanged.

Staff cookie и CSRF guard отдельны. Platform JWT в localStorage не применять. Customer session не принимается platform middleware даже при одинаковом user UUID. Staff database role не получает unrestricted tenant CRUD: diagnostic read service проверяет support grant и явно scoped tenant transaction, включая RLS если включена.

Сервисы: `PlatformAuthorization`, `SupportGrantService`, `PlanPublisher`, `BillingCheckoutService`, `BillingEventProcessor`, `BillingProjector`, `EntitlementCalculator`, `BillingSyncService`, `PlatformActionService`, `PlatformReportService`, `StaffAuditWriter`. HTTP controller не устанавливает `subscriptions.status` напрямую.

## 2 Источники правды

- Provider подтверждает customer/subscription/invoice/payment/refund state на **нашем commercial account**.
- `billing_subscription_contracts` хранит историю договоров; `subscriptions` остаётся одной текущей projection на tenant. Новая provider subscription после cancellation не стирает старые invoices.
- `billing_invoices` и `billing_payment_attempts` хранят разные сущности; несколько попыток оплаты одного invoice не создают несколько оплаченных счетов.
- `billing_checkout_sessions` описывает purchase workflow, а не поступление денег.
- `entitlement_overrides` даёт временный service access отдельно от paid evidence.
- `usage_entries` — idempotent ledger изменений quota, `usage_buckets` — агрегат.
- `platform_audit_log` фиксирует staff operations и не требует действующего tenant для глобальной настройки.

Domain entities tenant data могут удаляться согласно privacy policy; billing documents имеют отдельную утверждённую retention policy. Не объявлять любой retention срок юридически обязательным. История provider IDs и dedupe сохраняется достаточно долго для retries и backfill.

## 3 Billing adapter contract

Interface: createHostedCheckout, getCheckout, getCustomer, getSubscription, getInvoice, listChanges, createPortalSession, previewPlanChange, applyPlanChange, cancelAtPeriodEnd, resumeCancellation, requestRefund, getRefund, verifyWebhook. Payment mutations доступны только этому модулю с соответствующей authorization, step-up и idempotency. Connector мониторинга магазина read-only и не реализует эти capabilities.

Config: provider code, commercial account ID, live/test credentials, pinned API version, webhook secret, price mappings, allowed hosted origins, permitted methods, tax/document policy reference. Secrets через deployment keyring/env manager, не public admin GET. Owner UI показывает лишь masked fingerprint/connection health. Ввод/rotation secret через secure ops procedure, не обычный staff form с reveal.

Adapter unavailable в P0 → published purchase buttons disabled или trial/manual pilot only. В P1 хотя бы один adapter проверен sandbox и real provisioning issuer; конкретный provider выбирает владелец. Stripe пример: hosted subscription checkout и verified invoice/subscription state; document/event fields брать из выбранной API version, не из этого generic описания.

## 4 Billing lifecycle и entitlements

| Факт | Product projection | Access policy |
|---|---|---|
| Organization создана, no trial/paid | subscriptions absent/onboarding | Setup/history, без scheduled monitoring |
| Approved valid trial | status trial | trial до trial_end |
| Checkout created/completed without confirmed payment | Existing status не подменяется active | Pending new grant; old paid/trial сохраняется до своей даты |
| Confirmed qualifying payment+active contract | status active, paid_until from covered service period | active limits snapshot |
| Renewal failed | status past_due | grace при ранее paid и grace not expired |
| Grace истекла | status suspended | paused_billing, history/payment доступны |
| Manual service suspension | billing state unchanged | paused_manual независимо от paid |
| Cancel scheduled | provider flag, current status не cancelled преждевременно | До verified paid_until |
| Contract ended | status cancelled | expired если нет другого approved grant |
| Provider state unknown | no new paid grant | Previously confirmed period не сокращается без evidence |
| Temporary override active | paid facts unchanged | Explicit override within interval |

У нового клиента до trial/paid `subscriptions` может отсутствовать; GET текущей подписки возвращает204, а кабинет показывает onboarding. Pending checkout хранится в собственной таблице и не требует вымышленного active/trial статуса в subscriptions. Наличие истории или вход в кабинет не является paid grant.

Приоритет: deleted/deleting→no service; security/manual suspension→pause; capacity choice→pause browser jobs; валидный paid период→active; approved override→override limits; valid trial→trial; overdue grace→grace; иначе expired/paused_billing. Override не обходит manual/security suspend. Billing state и `access_state` сохраняются отдельно. All rules config versioned; причины `access_reason_codes` видны owner/tenant_owner.

`paid_until` не вычислять как paid_at+30д. Использовать реальные покрытые periods invoices/contract; annual/monthly timezone и calendar intervals из provider. Partial refund не автоматически пропорциональное сокращение paid_until. Full refund/cancel access policy утверждается commercial rule, action preview shows it. Не менять paid state руками, даже если предоставляется complimentary доступ.

Один active commercial contract per tenant/provider mode по policy. Параллельный checkout или renew для существующего active contract возвращает existing session/409 либо plan-change flow, не создаёт вторую recurring подписку. Trial eligibility under tenant lock; unique trial_consumed marker. Entitlement projection и domain_outbox changes commit вместе.

## 5 Checkout webhook sync

Create checkout transaction: validate customer owner/email/policy acceptance/published price/version; lock subscription/tenant; create pending local checkout record with stable operation key; call provider with idempotency key; persist provider ref/status. Не держать PostgreSQL transaction open during slow external request: use durable platform action with unique reservation; provider create retry queries reference/idempotency before second mutation.

Если provider response потерян после create, local state unknown, backend resolves через provider query; UI pending. Fulfillment не опирается на frontend redirect. Verified webhook: signature over raw bytes→account/mode validation→sanitized durable `billing_event_inbox`→ACK→job fetch current affected resources→project→outbox. Unknown event type stored ignored with reason, не error loop. Duplicate event ID same hash duplicate; different hash conflict.

Event ordering ненадёжно: fetch latest provider state вместо применения по arrival_time; processor coalesces entity fetch, source freshness/checkpoint prevents older read overwriting newer projection. Immutable operation IDs дедуплицируют payment/refund observations. Invoice paid плюс PaymentIntent succeeded не суммируются независимо. `invoice.amount_paid` и transaction records сравниваются для drift, но KPI выбирает один authoritative calculation method.

Webhook outage recovery: delta sync15мин с overlap48ч, daily covered-window audit, separate watermarks per object family. Если provider API не позволяет history by updated time, adapter implements provider-supported scan+cursor и documenting gap limits. stale billing data создаёт platform issue, не автоматическое объявление всех invoices unpaid. Billing outbox notifications имеют собственные templates/dedupe.

Stripe пример mapper включает invoice paid/payment failed/action required, subscription updated/deleted, checkout state, refund/dispute relevant observations. Exact names/behavior tests pinned version. Document source https://docs.stripe.com/billing/subscriptions/webhooks; продуктовые lifecycle rules выше — наши собственные policies.

## 6 Platform actions и audit

Любая mutation с внешним side effect или длительной обработкой возвращает202 `{action_id,status}`. Actions: sync_billing, replay_event, retry_job, suspend_monitoring, resume_monitoring, grant_override, publish_plan, change_plan, cancel_subscription, request_refund, drain_worker_pool. Request includes target IDs, parameters DTO, reason5..2000, expected version, Idempotency-Key. Allowed action-specific fields, arbitrary JSON execution запрещён.

Action stages queued/running/succeeded/failed/unknown/cancelled. `unknown` means provider side effect uncertain; automated second charge/refund запрещён. Action unique(actor/type/key); same key/different payload409. Result immutable after final success; events/activity retained. No raw SQL/shell, no arbitrary force operation bypass.

Staff audit records read of detailed customer evidence, secret rotations metadata, role changes, money action requests/approvals, price publications, suspension, overrides/replays, export/download authorization. Before/after sanitized; passwords/tokens/provider response bodies excluded. Audit write failure блокирует sensitive mutation до durable log reservation, но не checkout покупателя магазина.

## 7 Platform API каталог

Все GET lists cursor50/max100. Request headers session+CSRF mutations, If-Match for versioned resources, Idempotency-Key for commands. Tenant target по explicit parameter и platform permission; обычные customer endpoints не расширять `?tenant_id=any`.

Все user-facing backend messages, validation errors, problem `message`, notification templates, action labels, export headers и public content fields MUST иметь translation keys/records для `ru`, `en`, `de`. Machine `code` остаётся stable и не локализуется; provider/raw exception text не подставляется пользователю напрямую.

| Route | Request/Filters | DTO / действие |
|---|---|---|
| GET /platform-api/v1/me | — | staff_id,role,MFA,capabilities |
| GET /platform-api/v1/overview | from,to,timezone,mode,currency | BusinessKPIs+definitions+freshness |
| GET /platform-api/v1/health | scope | service/queues/lag summary |
| GET /platform-api/v1/tenants | query,state,plan,mode,cursor | TenantSummaryDTO[] |
| GET /platform-api/v1/tenants/{id} | — | summary,allowed_actions,commercial state |
| GET /platform-api/v1/tenants/{id}/stores | support scope check | masked health or granted details |
| GET /platform-api/v1/subscriptions | state,plan,currency,mode | contract/access summaries |
| GET /platform-api/v1/invoices | tenant,currency,state,mode,date | SaaSInvoiceDTO[] |
| GET /platform-api/v1/payments | invoice,tenant,state | SaaSPaymentAttemptDTO[] |
| GET /platform-api/v1/refunds | tenant,state | SaaSRefundDTO[] |
| GET /platform-api/v1/plans | state | plan/version/price catalog |
| POST /platform-api/v1/plans | code,title translations | draft plan |
| POST /platform-api/v1/plans/{id}/versions | entitlements,trial_days,content | immutable version draft |
| POST /platform-api/v1/plan-versions/{id}/prices | amount_minor,currency,interval,provider mapping | draft price |
| POST /platform-api/v1/plan-versions/{id}/publish | expected_version,reason | reviewed publishing action |
| POST /platform-api/v1/actions | type,target,parameters,reason | action_id,state |
| GET /platform-api/v1/actions/{id} | — | safe stage/results, no secrets |
| GET/POST /platform-api/v1/staff | role/invitation details | staff catalog/invite |
| PATCH /platform-api/v1/staff/{id} | role,disabled,version | last owner guard/MFA audit |
| POST /platform-api/v1/support-grants | tenant_id,scopes,expires_at,reason | pending request |
| GET /platform-api/v1/audit | actor/action/target/from/to | safe audit records |
| GET /platform-api/v1/queues/{kind} | state,age | operational metadata scoped by role |
| GET /platform-api/v1/public-content | slug,locale,state | draft/published page versions |
| POST /platform-api/v1/public-content | slug,locale,title,body | sanitized draft |
| POST /platform-api/v1/public-content/{id}/publish | reason/version | content action |

Customer support endpoints `/api/v1/support-grants`: list own tenant requests; approve/revoke owner only. Granted scope,expiry validated backend, API never accepts staff asserted «has_grant=true».

### DTO fields

TenantSummary: id,name,created_at,owner_contact mask,plan_code,plan_version_id,billing_state,access_state,access_reason_codes,paid_until,trial_end,grace_until,next_charge_at,currency,active_stores,store_limit,monitoring_health,open_incidents_count. Monetary fields только billing-capable role; detailed findings не входят.

SaaSInvoice: id,tenant_id,contract_id,provider_invoice_ref,number,mode,state,currency,exponent,subtotal_minor,discount_minor,tax_minor,total_minor,paid_minor,due_minor,service_period_start/end,issued_at,due_at,paid_at,document_available. Provider reconciliation drift makes coverage_unknown, not zero amount.

SaaSPaymentAttempt: id,invoice_id,provider_attempt_ref,state,amount_minor,currency,created_at,succeeded_at|null,failure_code|null. Refund: id,payment_attempt_id,amount,currency,state,reason,requested_by,approved_by,provider_ref. Dashboard KPI source uses succeeded amounts once per external payment operation.

BusinessKPIs: interval,timezone,mode,currency,metrics [{code,value/formatted,num/denominator,definition,source_count,as_of,coverage,drilldown_filter}], no secret provider data. Financial totals emitted integer/string or exact rational MRR; percentage uses count ratio, no hidden denominator.

## 8 БД и ограничения

Сначала выполнить reference `database/schema.sql`, затем `database/platform-billing-extension.sql`. Production sources — Laravel migrations в том же порядке. Extension добавляет platform staff/access/audit/actions, immutable plan/prices, customers/contracts, checkout/events/invoices/attempts/refunds, overrides, usage ledger, content/legal acceptances и deletion tombstones. Точный count определяется валидатором пакета; base41 таблица unchanged.

Provider refs unique в `(provider,account,mode,external_ref)`. Все tenant billing links composite `(tenant_id,id)`. Суммы bigint nonnegative; refund total≤successful payment, invoice paid accounting consistency и singleton active contract проверять service locks+nightly audit, а не только CHECK.

Published immutable versions enforce application/DB write policies; случайный update предотвращается tests и permissions. Platform tables не tenant RLS domain: отдельная role и repository allowlist. Tenant billing tables customer auth scoped, staff policies capability-specific; нет wildcard BYPASSRLS для HTTP user connection.

## 9 Retention and tests

Данные карточек не хранить. Billing address/tax IDs при необходимости отдельная encrypted minimal profile, retention/privacy policy; не смешивать с sanitized магазинными events. Defaults для platform audit365д и checkout technical records90д proposed. Financial invoice retention утверждается owner в соответствии с chosen issuer/legal needs, не удаляется молча стандартным event retention job.

Тесты BCK-01…BCK-16: separate guards; separate merchant/billing webhook secrets; lost response create idempotency; webhook event duplicates/order; paid invoice dedupe; mutable projection freshness; no redirect fulfillment; exact calendar paid_until; trial singleton; expiry grace/override; refund sums race; active commercial contract race; queue outage recovery; support grants; history across subscription recreation; deleting tenant leaves tombstone and no undisposed recurring billing.

## 10 Порядок реализации

ADD-1 platform staff guard/MFA, permissions, audit и read-only health. ADD-2 plan catalog/customer billing projection/contracts без реальных charges, fixtures. ADD-3 public pricing/signup/trial и customer cabinets. ADD-4 hosted checkout+verified billing events+sync+entitlements sandbox. ADD-5 owner commercial dashboard и billing operations, approval workflow. ADD-6 support grants/dead letter safe tooling, P1 validation.

Gate перед реальными оплатами: provider account approved, issuer/tax/terms configured, prices mapped, recurring agreement/cancel flow tested, payment failures/renewal/refunds validated, backup and secrets safe. Не считать этот gate выполненным созданием файлов ТЗ. Все panel/data tests добавляются к основному ACCEPTANCE; ИИ ни на одном шаге не требуется.
