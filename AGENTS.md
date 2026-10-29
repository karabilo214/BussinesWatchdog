# Business Watchdog Implementation Rules

This repository contains the specification package (`spec/`) and the implemented Laravel backend (`apps/backend`), Vue customer and platform frontends (`apps/frontend`), WooCommerce and Shopware connectors (`plugins/`), and Node/Playwright browser worker (`apps/browser-worker`). See `PROJECT_STATE.md` for the current status and `docs/progress.md` for the step history.

Before a task, read `PROJECT_STATE.md`, then:

1. `spec/Business-Watchdog-TZ.md`, especially sections 2, 9-14, 18-20, 25-28 and 36-39.
2. `spec/database/schema.sql`, `contracts/` (the only copy of `openapi.yaml`, `platform-billing-api.yaml` and `event.schema.json`), and `spec/ACCEPTANCE.md`.
3. For customer panels and SaaS billing: `spec/panels`, `spec/database/platform-billing-extension.sql`, `contracts/platform-billing-api.yaml`, and `spec/PANELS-ACCEPTANCE.md`.

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
- Migrations must work with the code of the previous release (expand/contract, ADR 0080): no rename or drop of anything the previous release still uses.

First implementation path:

1. D0 compatibility spike and ADRs.
2. D1 bootstrap with repo structure, containers, CI, auth, tenancy and store basics.
3. Durable ingestion slice before broad module generation.

Every task report should include changed behavior, migrations/contracts touched, tests actually run, remaining gaps and traceability to spec/acceptance IDs.

Actions only on the owner's explicit command (agents run without approval prompts on this machine):

- Deploying to production (`infra/production/deploy.sh`, `rollout.sh`) or any change on the production server, including its `.env`.
- Uploading the WooCommerce plugin to the WordPress.org SVN, or approving a plugin/App version for the cabinet.
- Changing the owner's legal pages; sending anything to customers, merchants or external services on the owner's behalf.

Working rules (shared with `CLAUDE.md`):

- Talk to the owner in Russian; no code comments unless asked.
- Backend tests with PHP 8.4 from Homebrew: `cd apps/backend && /opt/homebrew/opt/php@8.4/bin/php artisan test` (the system `php` may be older).
- Commit and push after a task only when all tests are green; stage explicit paths (another agent may be editing the same tree) and recheck the step number in `docs/progress.md` before adding a step.
- After a task update `PROJECT_STATE.md` (short) and the area file in `docs/progress/`; owner tasks and their checkboxes live in `docs/backlog.md`; every command, env variable or manual step needed to run or release goes into `docs/ops/`.
- New financial or business assumptions go into an ADR in `docs/adr/` and are shown to the owner.
