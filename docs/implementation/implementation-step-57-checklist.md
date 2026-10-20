# Implementation Step 57 Checklist: Frontend Workspaces

Stage: P0 dashboard groundwork (spec/panels: CLIENT-PANELS, OWNER-PANEL, ADMIN-PANEL, PUBLIC-SUBSCRIPTION-FRONTEND; ADR 0017)

## Scope

- [x] `apps/frontend` turned into an npm workspace; Step 56 project moved to `customer/` without behaviour changes.
- [x] Shared packages `design`, `ui`, `i18n`, `api-client`; palette paths updated in ADR 0015, `docs/design/palette.md`, Makefile.
- [x] `control/` app for `/owner` and `/admin` (placeholder sections, own port and `/platform-api` proxy).
- [x] `public/` site prerendered with vite-ssg in ru/en/de with language chooser, per-locale `lang`/title/description; prerender check script.
- [x] Makefile: `frontend-dev-control`, `frontend-dev-public`; smoke script moved to `customer/tests/e2e`.
- [x] Root scripts call nested npm via `$npm_execpath` (fixes runaway recursion through an ancestor npm 5 that froze the machine).

## Verification

- [x] `make frontend-check`: palette ok; customer 11/11, control 2/2, public 3/3 tests; all builds ok; prerender ok.
- [x] Customer smoke 8/8 in Chromium against the real backend (run against Docker PostgreSQL by LAN address because a local Postgres.app occupied 127.0.0.1:5432 after the reboot).
- [x] Backend: SQLite 309 passed + 4 skipped.
