# Business Watchdog Progress

Step history is split by area. Read only the file you need; add a new step to the end of the matching file and bump «Last step» below.

| File | Area | Steps |
|---|---|---|
| [`progress/core.md`](progress/core.md) | Core platform: infrastructure, repository and runtime, auth and tenancy, stores and pairing, event ingestion, outbox, hardening, API readiness, acceptance and readiness summaries | 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 26, 27, 28, 29, 39, 40, 41, 42, 43, 44, 55, 74, 96, 131, 133, 134, 142, 144, 159, 165, 179, 181, 182, 187, 188, 189, 190, 191, 197, 203, 204, 230, 231, 235, 236, 254, 255, 256, 259, 260, 261, 272, 275 |
| [`progress/reconciliation.md`](progress/reconciliation.md) | Projections, reconciliation and incidents: order/refund/payment/transaction projections, allocations, reconciliation rules and runs, incident engine, scheduler and dirty-order processing, provider coverage, connector freshness, parallel sync and reconciliation at scale | 20, 21, 22, 23, 24, 25, 30, 31, 32, 33, 34, 35, 36, 38, 48, 50, 112, 113, 155, 162, 200, 201, 232, 233 |
| [`progress/connectors.md`](progress/connectors.md) | Connectors: WooCommerce plugin and REST API connector, Stripe, PayPal, Shopware integration and App, compatibility spikes and real-payment chains | 45, 46, 47, 49, 65, 66, 67, 68, 70, 71, 72, 73, 76, 77, 78, 79, 80, 81, 85, 86, 87, 93, 94, 136, 141, 143, 146, 147, 148, 155, 156, 160, 163, 185, 202, 205, 206, 214, 215, 216, 217, 218, 219, 220, 221, 222, 223, 226, 227, 229, 274, 276, 277, 278, 279, 282, 283, 305 |
| [`progress/browser-checks.md`](progress/browser-checks.md) | Browser checks: browser check backend, Playwright worker, synthetic marker, egress proxy, redacted screenshots | 51, 52, 53, 54, 140, 154, 155, 156, 157, 192 |
| [`progress/notifications.md`](progress/notifications.md) | Notifications: email notifications, Telegram binding and webhook, reminders, daily digest, outgoing mail authentication | 37, 88, 89, 90, 91, 92, 94, 95, 107, 137, 198, 273 |
| [`progress/exports.md`](progress/exports.md) | CSV exports: findings, incidents and transaction exports, retention purge, download URLs, reference schema | 75, 77, 81, 82, 83, 84, 87 |
| [`progress/dashboard.md`](progress/dashboard.md) | Customer dashboard: palette, dashboard scaffold and workspaces, store/incident/reconciliation/check/notification screens, overview, account and team, MFA; visual page editor | 56, 57, 58, 59, 60, 61, 62, 63, 64, 69, 135, 161, 164, 257, 258, 262, 263, 264, 265, 266, 267, 271, 280, 281 |
| [`progress/billing.md`](progress/billing.md) | SaaS billing and owner panel: plans and entitlements, Paddle adapter, trials, usage, subscription page, platform staff sign-in, plans/prices/discounts in the owner panel, plan change and cancellation, customers and subscriptions, volume packages, pause, add-ons, retention, invoices and payments, refunds | 97, 98, 99, 100, 101, 102, 103, 104, 105, 106, 108, 109, 110, 114, 115, 116, 117, 119, 120, 125, 126, 127, 130, 132, 166, 167, 168, 194, 195, 196, 234, 268, 269, 270 |
| [`progress/public-site.md`](progress/public-site.md) | Public site: server-rendered public site, content pages, contact form, sign-up and consent to legal documents | 111, 118, 121, 122, 123, 124, 128, 129, 138, 139, 145, 149, 150, 151, 152, 153, 158, 169, 170, 171, 172, 178, 183, 186, 193, 199, 212, 213, 224, 225, 228, 288, 302, 303, 304, 306 |

Last step: 306 (`progress/public-site.md`).

Контекст сверен 2026-10-27 без нового шага реализации: на сервере `017622a` (Step 279), статус WordPress.org — в `ops/integrations.md`; повторная проверка открытых денежных инцидентов — в конце `progress/reconciliation.md`.
