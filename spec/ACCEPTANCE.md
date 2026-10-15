# Матрица приёмки Business Watchdog

Обновлено: 2026-10-15, Step 157.

Эта матрица теперь отражает текущее состояние реализации. Evidence указывает на тесты, e2e-скрипты или журнал шагов; это не заменяет финальный release checklist. P1 включает обязательные P0; P2 принимается отдельно.

Статусы:

- **done** — реализовано и есть automated/e2e evidence.
- **partial** — реализована существенная часть, но сценарий ещё не закрыт полностью или не покрыт production-like evidence.
- **not_done** — реализации пока нет или она только в ТЗ.

| ID | Этап | Статус | Сценарий | Evidence | Осталось / ограничение |
|---|---|---|---|---|---|
| ACC-01 | P0 | partial | Fresh setup без secrets | `Makefile`, `docker-compose.yml`, health endpoints, `make frontend-smoke`; README release/operations guide; `docs/progress.md` Steps 01-03, 56-64, 103 | Финальный demo/release checklist и production-like bootstrap ещё не закрыты |
| ACC-02 | P0 | partial | Tenant A знает UUID B | Backend feature tests for stores/incidents/checks/payments/orders/exports/billing; platform/customer guards and route policies; Steps 56-64, 97-120, 126 | Privacy deletion and support-grant surfaces are not complete |
| ACC-03 | P0 | done | Нет tenant context в job | `tests/Unit/Tenancy/TenantContextTest.php`, `tests/Feature/Tenancy/TenantContextContainerTest.php`; fail-closed `TenantContext` | — |
| ACC-04 | P0 | done | Pairing retry/expired/used code | `tests/Feature/Pairing/PairingApiTest.php`; `docs/progress.md` Steps 12-15, 40 | — |
| ACC-05 | P0 | done | HMAC body altered/nonce replay | `tests/Feature/Ingest/IntegrationHmacAuthTest.php`; HMAC middleware and credential rotation tests | — |
| ACC-06 | P0 | done | Одно событие прислано десять раз | `tests/Feature/Ingest/IngestEventsApiTest.php`; event inbox unique constraints | — |
| ACC-07 | P0 | done | Same ID но different hash | `tests/Feature/Ingest/IngestEventsApiTest.php`; conflict handling | — |
| ACC-08 | P0 | done | Revision17 пришла после18 | Projection tests in ingest/order/reconciliation suites; Step 46 matrix e2e | — |
| ACC-09 | P0 | done | Crash after DB commit before queue | Domain outbox tests: leaser/dispatcher/result/sweeper; `docs/progress.md` Steps 24-27 | — |
| ACC-10 | P0 | partial | DB down | Health/readiness tests and ingestion transaction semantics | Need explicit DB-down false-acceptance smoke in release checklist |
| ACC-11 | P0 | partial | Redis down | Outbox is DB-backed; health/readiness covers Redis state | Need explicit Redis-down recovery evidence |
| ACC-12 | P0 | done | HPOS/legacy orders/refunds | WooCommerce matrix e2e across six targets; `docs/compatibility.md`; `docs/progress.md` Steps 45-47, 76-81, 136 | Re-run the full matrix before release after connector changes |
| ACC-13 | P0 | done | Backfill одновременно live events | Plugin outbox/backfill/rescan e2e; revision ordering tests; WooCommerce REST API connector delta/backfill steps | Re-run the full matrix before release after connector changes |
| ACC-14 | P0 | done | WP-Cron остановлен | `tests/Feature/Integrations/ConnectorFreshnessTest.php`; stale/partial connector incidents; Step 50 | — |
| ACC-15 | P0 | partial | Classic happy path | `plugins/woocommerce-watchdog/tests/matrix/browser-e2e.sh`; Shopware 6.6/6.7 browser e2e for payment-method reachability, Step 140; Step 157 product/variant and positive amount assertions, selectable delivery/payment checks, Shopware three-mode and Woo Classic/Blocks local runs | Пройти полный плановый lease подключённого магазина; физическая доставка WooCommerce пока подтверждена Playwright-фикстурой, локальный товар виртуальный |
| ACC-16 | P0 | done | Blocks checkout-draft side effect | Synthetic marker/draft cleanup e2e; Step 53 | — |
| ACC-17 | P0 | done | Real submit/capture attempted | `apps/browser-worker/test/networkPolicy.test.js`; network boundary e2e; WooCommerce and Shopware mutation-blocking policies; Steps 53, 140; Step 156 local Shopware scheduled adapter created zero orders before the separate purchase robot ran | — |
| ACC-18 | P0 | done | PrivateIP/IPv6/redirect/DNS rebind | SSRF-safe fetcher tests, egress proxy checks, `infra/scripts/egress-test.sh`; Steps 42, 53 | Keep in release/security checklist |
| ACC-19 | P0 | done | Cart/checkout relevant500 twice | Browser outcome policy tests and incident confirmation policy; Step 51 | — |
| ACC-20 | P0 | done | Tracking404/favicon missing | Browser check policy classifies non-critical resources; Step 51 | — |
| ACC-21 | P0 | done | WAF/CAPTCHA | Worker/backend classify monitoring blocked/inconclusive, not checkout outage; browser worker tests | — |
| ACC-22 | P0 | done | Product out of stock | Browser adapter/outcome policy distinguishes test product issue; browser e2e | — |
| ACC-23 | P0 | done | Worker killed, lease expired | `tests/Feature/Browser/BrowserChecksTest.php`; lease/fencing protocol; Step 51 | — |
| ACC-24 | P0 | done | Result retry identical/altered | Browser result idempotency/conflict tests; Step 51 | — |
| ACC-25 | P0 | partial | Screenshot/trace содержит token | Redaction in worker, artifact store, private 60s links; Step 54 | Presigned finalize flow and full artifact rejection suite still differ from catalogue |
| ACC-26 | P0 | done | Acknowledge | `tests/Feature/Incidents/IncidentApiTest.php`; dashboard smoke Step 59 | — |
| ACC-27 | P0 | done | Data stale во время recovery | Connector freshness + incident/reconciliation coverage tests; Step 50 | — |
| ACC-28 | P0 | done | Email timeout/retry | Notification channel/delivery tests; `make frontend-smoke` with Mailpit; Step 62; DKIM signing and `mail:auth-check` (SPF/DKIM/DMARC), Step 107 | Real SMTP provider and public DNS records not tested |
| ACC-29 | P1 | partial | FIN-01…FIN-24 | Reconciliation, Stripe, PayPal and allocation tests; Steps 30-36, 65-72; Step 155 real Stripe test-mode capture/decline and reverse capture-state rule; Step 156 real PayPal Sandbox captures, refunds and both Shopware payment divergences | Need formal FIN fixture registry/evidence rollup before P1 sign-off; full §43 PAYMENT_RECORDED obligation engine remains open; SwagPayPal partial-refund amount unavailable from the shop |
| ACC-30 | P1 | done | Intent+charge events об одном capture | Stripe connector tests; Step 65 | — |
| ACC-31 | P1 | done | Два captures distinct IDs | Reconciliation rule tests for multiple captures | — |
| ACC-32 | P1 | done | Manual Woo refund | Reconciliation/provider coverage tests: store claim is not provider refund | — |
| ACC-33 | P1 | done | Authorization without capture | Stripe and PayPal connector tests; Steps 65, 70 | — |
| ACC-34 | P1 | done | Fee/net payout отличается от total | Money semantics exclude fees/payouts from order underpayment; reconciliation tests | — |
| ACC-35 | P1 | done | Two currencies | Multi-currency reconciliation and overview totals tests; Steps 60, 63, 70 | — |
| ACC-36 | P1 | done | Concurrent manual allocation | `PaymentAllocationServiceTest`; row locks and allocation bounds | — |
| ACC-37 | P1 | done | Revoked API key | Provider coverage tests; rejected Stripe/PayPal credentials degrade to unknown | — |
| ACC-38 | P1 | done | Telegram binding чужому chat | Telegram pending channel, secret-protected bot bind and native update endpoints, masked destination, sender success/failure, webhook registration command, binding collision tests and live Telegram bot binding/test delivery smoke through Cloudflare tunnel; Steps 88-94 | Use a stable production tunnel/domain for deployment |
| ACC-39 | P1 | partial | Quiet hours и recovery | Email/Telegram notification preferences and delivery tests; reminder planner/command/UI tests for opt-in open-incident reminders that respect quiet hours; Steps 62, 88, 95 | Daily digest not implemented |
| ACC-40 | P1 | done | Plan quota или suspended | Entitlements and usage enforcement, billing access states, store-capacity pause, manual suspension/temporary override and order-volume pause: `EntitlementsTest`, `UsageTest`, `PlanChangeTest`, `TenantAdminTest`, `OrderVolumeTest`; Steps 97-102, 108-110, 114-117 | Event-budget overage remains a soft warning by owner decision; production Paddle account not connected |
| ACC-41 | P1 | not_done | Backup restore новым host | — | RPO/RTO restore drill missing |
| ACC-42 | P1 | not_done | Tenant deletion с artifacts | — | Privacy deletion workflow not implemented |
| ACC-43 | P1 | not_done | Load/capacity fixture | — | SLO/load report and browser pool sizing missing |
| ACC-44 | P2 | partial | Empty complete vs stale bucket | Store coverage, overview verdict and frontend tests distinguish unknown from healthy | P2 metrics buckets not fully implemented |
| ACC-45 | P2 | not_done | 1 order/day shop | — | P2 anomaly rules not implemented |
| ACC-46 | P2 | not_done | DST fallback/skip | — | P2 metrics bucket tests not implemented |
| ACC-47 | P2 | not_done | MAD0/мало истории | — | P2 anomaly fallback/abstain not implemented |
| ACC-48 | P2 | not_done | Consent/coverage изменились | — | Checkout telemetry layer 2 not implemented |
| ACC-49 | P2 | not_done | Sales drop+browser failure | — | P2 correlation not implemented |
| ACC-50 | P2 | not_done | Deploy рядом со сбоем | — | Deploy correlation/explanation model not implemented |

Дополнительные обязательные security проверки: CSRF, stored XSS, CSV formula injection, permission matrix, last owner, invitation expiration, secret masking и RLS при включении. Текущее evidence: CSRF/Sanctum, separate platform/customer guards, permission matrix, last owner / ownership transfer / platform last-owner guards, invitation expiration, secret masking and CSV formula protection for findings/incidents/transactions/platform-audit exports covered in backend/frontend tests; RLS gate, privacy deletion, private billing-document export policy and full stored-XSS security sweep remain release-blocking backlog.
