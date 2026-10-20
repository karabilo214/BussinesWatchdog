# Step 02 Checklist: Repository Skeleton

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 4, 5, 6, 25, 26, 34, 36.

This step creates the repository shape expected by the specification. It does not create working Laravel, Vue, Node, or WordPress applications yet.

## Structure

- [x] `apps/backend/`
- [x] `apps/frontend/`
- [x] `apps/browser-worker/`
- [x] `plugins/woocommerce-watchdog/`
- [x] `contracts/`
- [x] `database/reference/`
- [x] `tests/fixtures/`
- [x] `docs/adr/`
- [x] `docs/operations/`
- [x] `infra/`

## Reference Copies

- [x] Copy API and event contracts from `spec/contracts` to `contracts`.
- [x] Copy SQL reference snapshots from `spec/database` to `database/reference`.
- [x] Copy synthetic examples from `spec/examples` to `tests/fixtures`.

## Not Done In This Step

- Laravel application generation.
- Vue/Vite application generation.
- Node/Playwright worker generation.
- WordPress plugin implementation.
- Laravel migrations.
- CI pipeline.

