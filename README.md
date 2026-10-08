# Business Watchdog

Repository bootstrap for the Business Watchdog project.

The current repository contains the specification package, local infrastructure bootstrap, and an implementation skeleton. It is not a working SaaS implementation yet. Start with:

- [spec/README.md](spec/README.md)
- [spec/Business-Watchdog-TZ.md](spec/Business-Watchdog-TZ.md)
- [AGENTS.md](AGENTS.md)

Primary working areas:

- `apps/backend` — Laravel backend placeholder.
- `apps/frontend` — Vue frontend placeholder.
- `apps/browser-worker` — Node/Playwright worker placeholder.
- `plugins/woocommerce-watchdog` — WooCommerce plugin placeholder.
- `contracts` — working API/event contracts copied from `spec/contracts`.
- `database/reference` — reviewed SQL snapshots copied from `spec/database`.
- `tests/fixtures` — synthetic acceptance fixtures copied from `spec/examples`.
- `docs` — progress, compatibility notes, ADRs, operations notes, and implementation checklists.
- `infra` — local infrastructure support files.
