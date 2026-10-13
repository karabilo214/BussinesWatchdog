# Приёмка панелей и SaaS billing

Версия 1.1. Обновлено: 2026-10-13, Step 119.

Это добавочные критерии к `ACCEPTANCE.md`. Матрица теперь показывает текущее evidence. Большая часть SaaS billing/platform панели всё ещё находится на уровне ТЗ и контрактов; customer cabinet реализован существенно дальше.

Статусы:

- **done** — реализовано и есть automated/e2e evidence.
- **partial** — закрыта часть customer/backend поведения, но сквозной EXT-сценарий ещё не принят.
- **not_done** — реализации пока нет или она только в спецификации.

| ID | Статус | Сценарий | Evidence | Осталось / ограничение |
|---|---|---|---|---|
| EXT-01 | not_done | Tenant_owner пробует platform API | — | Platform API/staff guard not implemented |
| EXT-02 | not_done | Platform staff имеет customer membership | — | Separate staff/customer sessions not implemented |
| EXT-03 | partial | Owner без MFA/step-up | Customer MFA and ownership-transfer step-up: `MfaApiTest`, `TeamApiTest`, frontend Step 69 smoke | Platform sensitive mutations and billing step-up not implemented |
| EXT-04 | not_done | Удаление последнего platform owner | — | Platform staff management not implemented |
| EXT-05 | not_done | Support без customer grant | — | Support grant system not implemented |
| EXT-06 | not_done | Grant revoked/expired | — | Support grants/artifact grant checks not implemented |
| EXT-07 | not_done | Published price изменён | — | Plan catalog and immutable price versions not implemented |
| EXT-08 | not_done | Forged price/amount/currency from browser | — | SaaS billing checkout not implemented |
| EXT-09 | not_done | Concurrent two checkout requests | — | SaaS billing checkout reservation not implemented |
| EXT-10 | not_done | Lost provider response | — | SaaS billing provider action/idempotency not implemented |
| EXT-11 | not_done | Browser return без webhook | — | SaaS billing fulfillment not implemented |
| EXT-12 | not_done | Verified webhook без browser return | — | SaaS billing webhook projection not implemented |
| EXT-13 | partial | Duplicates и out-of-order events | Core monitoring ingestion has idempotency/revision evidence: `IngestEventsApiTest`, provider connector tests | SaaS billing event inbox not implemented |
| EXT-14 | partial | LIVE/TEST и чужой account | Store Stripe/PayPal connectors isolate mode/account; Steps 65-72 | Commercial SaaS billing account isolation not implemented |
| EXT-15 | not_done | Failed renewal и grace expiry | — | Subscription lifecycle/access projection not implemented |
| EXT-16 | partial | Scheduled cancellation | `PlanChangeTest` (cancel at period end, repeat is a no-op, resume with expected version, audit), `billing.test.ts`; live Paddle sandbox cancel + resume, Step 108 (ADR 0037) | Contract end after the period and a new subscription after it (EXT-21) not exercised live |
| EXT-17 | partial | Downgrade превышает store limit | `PlanChangeTest` (retained stores required, pause only at the period end, nothing deleted, `capacity_choice_required` pauses scheduled and manual browser checks, owner re-chooses), `billing.test.ts`; Step 108 (ADR 0037) | Downgrade with a store choice not run live; trial/expiry-driven limit drops do not ask for a choice yet |
| EXT-18 | partial | Temporary override истёк | `TenantAdminTest` (owner grants plan access until a date ≤ 180 days with reason + authenticator code, one at a time, revoke, preview of access before/after, platform audit), `EntitlementCalculator` honours `ends_at`; control `tenants.test.ts`; Step 109 (ADR 0038) | Expiry not exercised live; no notification to the customer when an override ends |
| EXT-19 | done | Refund request billing staff | `RefundTest` (billing staff request, billing role cannot approve, owner approves with reason + authenticator code, wrong code denied and audited, Paddle adjustment body with items, events, dashboard refunds recorded, reject frees the amount, cancel at period end on request); control `payments.test.ts`; Step 120 (ADR 0044); Paddle sandbox partial refund 1.00 € accepted (`pending_approval`, tax inside the item amount) | Approval of the refund by Paddle's own review not observed live (stayed `pending_approval` in the sandbox) |
| EXT-20 | partial | Refund concurrency | `PaddleRefunds::request` reserves under a lock of the payment row (requested + sending + pending + refunded + unknown ≤ total), `refund_exceeds_payment` with the amount left, `refund_already_decided` on a second decision; `RefundTest` | Parallel requests tested sequentially only (row lock not exercised by concurrent processes) |
| EXT-21 | not_done | New subscription после cancellation | — | Subscription contract history not implemented |
| EXT-22 | partial | KPI MRR/cash/refunds | Step 119 (ADR 0043): owner panel «Payments» — every provider transaction and the month per currency (charged, tax, Paddle fee, earnings), never across currencies; `BillingTransactionsTest`, `payments.test.ts`; real Paddle sandbox transactions read back | Step 130 (ADR 0050): «Overview» — paying/trial/past-due/cancel counts, contracted MRR per currency as an exact fraction (list price, before discounts — owner decision pending), collected/refunds/net per currency with the previous period, paid churn; `BusinessOverviewTest`, `overview.test.ts`. Not yet: MRR after discounts, trial conversion, document-level drill-down |
| EXT-23 | partial | Technical replay | Core monitoring replay/idempotency exists; outbox/reconciliation tests | Platform replay tooling and billing replay not implemented |
| EXT-24 | partial | Tenant_admin billing/delete/owner role action | Customer role policies, team management, ownership transfer tests; Step 64/69 | Billing/delete organization not implemented |
| EXT-25 | partial | Workspace switch | Active tenant session exists; tenant activation tests; customer cache keys include tenant in API patterns | Multi-workspace switcher UI and complete cache isolation acceptance not implemented |
| EXT-26 | not_done | Customer deletion с recurring contract | — | Privacy deletion + recurring cancellation workflow not implemented |
| EXT-27 | done | Legal/policy/price version changed | Step 111 (ADR 0039) versioned legal pages; Step 118 (ADR 0042): pages marked as terms / privacy / subscription terms, consent bound to the published version at sign-up, trial, purchase and plan change, a newer version refuses the action with the current documents; `PolicyAcceptanceTest`, `billing.test.ts`, live `run-live.sh signup` | — |
| EXT-28 | not_done | Provider unavailable | — | SaaS paid grant and previous paid_until preservation not implemented |
| EXT-29 | partial | Portal locale coverage ru/en/de | Customer app, public site (server-rendered pages, pricing, contact form — Step 111) and control panel, notification/account templates, findings/incidents/transactions export UI labels and many backend problem codes have ru/en/de tests | Owner/admin/billing/private export localization not implemented |

Требуются также 16 BILL fixture outcomes, проверка no-card storage/secret redaction, CSRF и stored XSS (для HTML страниц сайта — санитайзер и тест Step 111), изоляция guard cookies, private invoice export/download, provider sandbox upgrade/proration/cancel/refund и наблюдаемость webhook lag. Tests of actual provider behavior нельзя заменить JSON fixtures.
