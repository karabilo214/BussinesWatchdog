# Business Watchdog Implementation Rules

This repository contains the specification package (`spec/`) and an in-progress implementation: the Laravel backend in `apps/backend` is built step by step (see `PROJECT_STATE.md` for the current status and `docs/progress.md` for step history). `apps/frontend`, `apps/browser-worker` and `plugins/woocommerce-watchdog` are still placeholders.

Before a task, read `PROJECT_STATE.md`, then:

1. `spec/Business-Watchdog-TZ.md`, especially sections 2, 9-14, 18-20, 25-28 and 36-39.
2. `spec/database/schema.sql`, `spec/contracts`, and `spec/ACCEPTANCE.md`.
3. For customer panels and SaaS billing: `spec/panels`, `spec/database/platform-billing-extension.sql`, `spec/contracts/platform-billing-api.yaml`, and `spec/PANELS-ACCEPTANCE.md`.

Core invariants:

- Money values are minor units as `bigint`/string. Do not use floats or JS Number for monetary sums.
- Store-reported paid markers (from any connector, WooCommerce being the first) are not independent capture evidence.
- Charge, PaymentIntent, refund, fee and payout semantics must not be mixed.
- Unknown, stale or incomplete coverage is not zero and not healthy.
- Tenant scope is mandatory in services, repositories, jobs, exports and artifact access.
- Browser checks in P0/P1 must not create real purchases, captures or refunds.
- Node browser workers must not read serialized Laravel jobs.
- Duplicate and out-of-order events must be safe.
- Screenshots and traces must not contain secrets or real PII.
- AI is not part of the current implementation.

First implementation path:

1. D0 compatibility spike and ADRs.
2. D1 bootstrap with repo structure, containers, CI, auth, tenancy and store basics.
3. Durable ingestion slice before broad module generation.

Every task report should include changed behavior, migrations/contracts touched, tests actually run, remaining gaps and traceability to spec/acceptance IDs.
