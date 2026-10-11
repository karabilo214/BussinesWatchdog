# Приёмка панелей и SaaS billing

Версия 1.1. Обновлено: 2026-10-11, Step 87.

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
| EXT-16 | not_done | Scheduled cancellation | — | Billing cancellation lifecycle not implemented |
| EXT-17 | not_done | Downgrade превышает store limit | — | Entitlements/quota downgrade flow not implemented |
| EXT-18 | not_done | Temporary override истёк | — | Platform overrides not implemented |
| EXT-19 | not_done | Refund request billing staff | — | SaaS refund approval workflow not implemented |
| EXT-20 | not_done | Refund concurrency | — | SaaS refund reservations not implemented |
| EXT-21 | not_done | New subscription после cancellation | — | Subscription contract history not implemented |
| EXT-22 | not_done | KPI MRR/cash/refunds | — | Owner billing KPIs not implemented |
| EXT-23 | partial | Technical replay | Core monitoring replay/idempotency exists; outbox/reconciliation tests | Platform replay tooling and billing replay not implemented |
| EXT-24 | partial | Tenant_admin billing/delete/owner role action | Customer role policies, team management, ownership transfer tests; Step 64/69 | Billing/delete organization not implemented |
| EXT-25 | partial | Workspace switch | Active tenant session exists; tenant activation tests; customer cache keys include tenant in API patterns | Multi-workspace switcher UI and complete cache isolation acceptance not implemented |
| EXT-26 | not_done | Customer deletion с recurring contract | — | Privacy deletion + recurring cancellation workflow not implemented |
| EXT-27 | not_done | Legal/policy/price version changed | — | Legal acceptance/versioned public purchase flow not implemented |
| EXT-28 | not_done | Provider unavailable | — | SaaS paid grant and previous paid_until preservation not implemented |
| EXT-29 | partial | Portal locale coverage ru/en/de | Customer app, public/control skeleton, notification/account templates, findings/incidents/transactions export UI labels and many backend problem codes have ru/en/de tests | Owner/admin/billing/private export localization not implemented |

Требуются также 16 BILL fixture outcomes, проверка no-card storage/secret redaction, CSRF и stored XSS, изоляция guard cookies, private invoice export/download, provider sandbox upgrade/proration/cancel/refund и наблюдаемость webhook lag. Tests of actual provider behavior нельзя заменить JSON fixtures.
