# Progress: CSV exports

Findings, incidents and transaction exports, retention purge, download URLs, reference schema.

### Step 75: Findings and Incidents CSV Exports

Status: complete for the first export slice

Added:

- Backend `exports` table and `ExportFile` model, separate from browser `artifacts`.
- `POST /api/v1/stores/{store}/exports`, `GET /api/v1/exports/{id}`, `GET /api/v1/exports/{id}/download`.
- CSV exports for `findings` and `incidents`, scoped by tenant/store and current filters. Amounts remain minor-unit strings; null stays empty, not zero.
- Private object storage on the configured export disk, 7-day retention default, idempotency on creation, and CSV formula-injection protection for generated cells.
- Customer app buttons on reconciliation and incident list screens to create/download CSV with the active filters; ru/en/de labels.
- OpenAPI contract updated in both `contracts/` and `spec/contracts/`; UI catalog and acceptance evidence updated.

Verification:

- Backend targeted: `php artisan test tests/Feature/Exports/ExportApiTest.php tests/Feature/Contracts/OpenApiRoutesTest.php` — 5 passed.
- Backend full SQLite suite: `php artisan test` — 364 tests, 360 passed, 4 skipped.
- Customer frontend: `npm run check -w @bw/customer` — 80 tests passed and production build completed.

Remaining:

- Transaction exports, billing/private invoice exports, async export worker/retry, and frontend smoke coverage.
- PostgreSQL test suite not rerun for this slice.

Coordination:

- Claude's WooCommerce REST API spike files under `plugins/woocommerce-watchdog/tests/matrix/rest-api-spike-*` were not touched.

### Step 77: Export Retention Purge

Status: complete for scheduled cleanup of generated CSV exports

Added:

- `CsvExportService::purgeExpired()` deletes the private object first and then marks the `exports` row `deleted`, mirroring the browser artifact retention pattern.
- New `exports:purge` artisan command with `--limit`, scheduled daily at 03:25 UTC with overlap protection.
- Export UI placement corrected to match the existing customer app style: CSV actions live in the page toolbar next to tabs/actions, not inside filter controls.

Verification:

- PHP lint: `PurgeExports.php`, `CsvExportService.php`, `ExportApiTest.php`.
- Backend targeted: `php artisan test tests/Feature/Exports/ExportApiTest.php tests/Feature/Contracts/OpenApiRoutesTest.php` — 7 passed.
- Customer frontend after the style correction: `npm run check -w @bw/customer` from `apps/frontend` — 80 tests passed and production build completed.

Remaining:

- Transaction exports, billing/private invoice exports, async export worker/retry, frontend smoke coverage, and PostgreSQL suite rerun.

Coordination:

- WooCommerce REST API connector/spike work remains Claude-owned; only shared `routes/console.php` was touched to add `exports:purge` next to `artifacts:purge`.

### Step 81: Transaction CSV Exports

Status: complete for the transaction export slice

Added:

- Backend `transactions` export kind on the existing `exports` API and private storage path.
- Tenant/store-scoped CSV over `financial_transactions`, with optional date, currency, transaction kind and transaction status filters.
- Transaction CSV columns keep provider/store semantics explicit: transaction id, payment ids/refs, mode, external operation id, kind, status, currency/exponent, minor-unit amount and source authority.
- Customer reconciliation toolbar action for transaction CSV export, next to the existing findings export, using the same quiet toolbar button style and ru/en/de labels.
- OpenAPI contract updated in both `contracts/` and `spec/contracts/`; UI catalog and acceptance evidence refreshed.

Verification:

- PHP lint: `ExportFile.php`, `CreateExportRequest.php`, `CsvExportService.php`, `ExportApiTest.php`.
- Backend targeted: `php artisan test tests/Feature/Exports/ExportApiTest.php tests/Feature/Contracts/OpenApiRoutesTest.php` — 8 passed.
- Customer frontend: `npm run check -w @bw/customer` from `apps/frontend` — 90 tests passed and production build completed.

Remaining:

- Billing/private exports, async export worker/retry, frontend smoke coverage, and PostgreSQL suite rerun.

Coordination:

- Shopware 6 spike/connector/screen work remains Claude-owned; no Shopware plugin/app/connector files were touched.

### Step 82: PostgreSQL Constraint for Transaction Exports

Status: complete

Fixed:

- Added a PostgreSQL migration that expands `exports_kind_check` from `findings/incidents` to `findings/incidents/transactions`.
- Down migration restores the previous check constraint, matching the pre-transaction export schema.
- Acceptance evidence markers bumped after the production-database compatibility fix.

Verification:

- PHP lint: `2026_10_11_130000_add_transactions_export_kind.php`.
- Backend targeted: `php artisan test tests/Feature/Exports/ExportApiTest.php tests/Feature/Contracts/OpenApiRoutesTest.php` — 8 passed.

Remaining:

- PostgreSQL full suite was not rerun in this step; the migration specifically targets the PostgreSQL check constraint missed by the SQLite export tests.

Coordination:

- Shopware files and ADR 0025 work remain Claude-owned and were not touched.

### Step 83: Expired Export Download URLs

Status: complete

Fixed:

- Export DTOs no longer advertise `download_url` once the private export file is expired.
- The download endpoint now uses the same downloadable predicate as the DTO, so ready/missing/expired handling stays consistent.

Verification:

- PHP lint: `ExportController.php`, `ExportApiTest.php`.
- Backend export targeted: `php artisan test tests/Feature/Exports/ExportApiTest.php` — 7 passed.

Remaining:

- Async export worker/retry and frontend smoke coverage remain future export work.
- Combined export/OpenAPI targeted run was not used as evidence because Claude's unstaged Shopware route adds `POST /api/v1/stores/{}/integrations/shopware` before its contract update.

Coordination:

- No Shopware files were touched; Claude-owned working tree changes were left unstaged.

### Step 84: Export Reference Schema

Status: complete

Updated:

- `spec/database/schema.sql` now includes the implemented `exports` table next to browser `artifacts`.
- The reference schema captures the private export object metadata, tenant/store scope, creator FK, retention fields, ready/deleted states, indexes and `findings/incidents/transactions` kind constraint.
- UI API catalog and acceptance evidence markers were bumped to include the schema snapshot coverage.

Verification:

- Documentation/schema snapshot change only; no runtime tests were needed.

Coordination:

- Shopware connector files and contracts currently being edited by Claude were left unstaged.

### Step 87: Export Download Pending Notice

Status: complete

Updated:

- Customer frontend export helper now returns whether a download URL was actually opened.
- Reconciliation and incident export actions show the existing quiet loading status when an export response has no `download_url` yet, instead of silently doing nothing.

Verification:

- Customer frontend: `npm run check -w @bw/customer` from `apps/frontend` — 87 tests passed and production build completed.

Coordination:

- Claude's Shopware Step 85 files were left unstaged; this step only touched customer frontend export helper/views and this progress entry.
