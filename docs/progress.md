# Business Watchdog Progress

## 2026-10-08

### Step 01: Local Infrastructure Bootstrap

Status: complete for local infrastructure bootstrap

Added:

- `.env.example` with local infrastructure defaults.
- `docker-compose.yml` for PostgreSQL, Redis, MinIO, and Mailpit.
- `Makefile` helpers for setup, up/down, logs, and reset.
- `docs/implementation-step-01-checklist.md`.

Verification:

- Docker CLI found inside `/Applications/Docker.app/Contents/Resources/bin/docker`.
- Docker version: 27.5.1.
- Docker Compose version: v2.32.4-desktop.1.
- Docker Desktop was started, but this Codex session cannot access `/Users/karabin/.docker/run/docker.sock` due to sandbox permissions. Run `make up` from a normal terminal.
- First terminal run reached image pull, then failed because `docker-credential-desktop` was not in `PATH`. `Makefile` now prepends Docker Desktop's bundled bin directory.
- Next terminal run reached image pull and failed on `minio/minio` pull access denied. LocalStack latest then required license activation. Local S3 was switched to Adobe S3Mock for bootstrap continuity; this needs a D0 infra ADR before production-like environments.
- PostgreSQL 18 rejected the old `/var/lib/postgresql/data` mount layout. Compose now mounts the named volume at `/var/lib/postgresql`.

Remaining:

- Confirm S3Mock responds on `http://localhost:9090`.
- Confirm Mailpit UI opens on `http://localhost:8025`.
- Pin production image digests during D0/D1 compatibility work.
- Add Laravel backend, migrations, health endpoints, and CI in later D1 slices.

Terminal evidence:

- `make ps` showed `business-watchdog-mailpit-1`, `business-watchdog-postgres-1`, `business-watchdog-redis-1`, and `business-watchdog-s3-1` running.
- Follow-up `make ps` showed Mailpit, PostgreSQL, and Redis healthy; S3Mock was running.
- Published ports: PostgreSQL `5432`, Redis `6379`, S3Mock `9090`, Mailpit SMTP `1025`, Mailpit UI `8025`.

### Step 02: Repository Skeleton

Status: complete

Added:

- Application placeholders under `apps/backend`, `apps/frontend`, and `apps/browser-worker`.
- WooCommerce plugin placeholder under `plugins/woocommerce-watchdog`.
- Working contract copies under `contracts`.
- Reference SQL copies under `database/reference`.
- Synthetic fixture copies under `tests/fixtures`.
- ADR, operations, compatibility, and checklist docs under `docs`.

Verification:

- Reference files copied without manual transformation.
- No application code generated yet.

Remaining:

- Generate backend Laravel application in `apps/backend`.
- Generate frontend Vue/Vite application in `apps/frontend`.
- Generate browser worker package in `apps/browser-worker`.
- Add CI and real health endpoints.

### Step 03: Backend Runtime Bootstrap

Status: complete for backend runtime and health bootstrap

Added:

- PHP 8.4 CLI/FPM Dockerfile under `infra/docker/backend`.
- nginx config for Laravel under `infra/nginx/backend.conf`.
- backend Compose services behind `tools` and `app` profiles.
- Makefile targets: `backend-build`, `backend-create`, `backend-shell`, `backend-up`, `backend-logs`.
- `infra/scripts/create-backend.sh` to create Laravel from inside the PHP 8.4 container.
- `docs/implementation-step-03-checklist.md`.

Verification:

- Host PHP is 8.1.31, so backend generation must run inside Docker PHP 8.4, not host Composer.
- Docker socket remains unavailable from Codex sandbox, so `make backend-build` and `make backend-create` must be run from the normal terminal.

Remaining:

- Add D1 auth/tenant/store migrations and tests.

Notes:

- `infra/scripts/create-backend.sh` now preserves Laravel's own `.env`, applies local infrastructure settings, and generates `APP_KEY` if missing.
- If Laravel files already exist from a partial first run, `make backend-create` repairs `apps/backend/.env` instead of exiting.
- `make backend-create` completed successfully in the user's terminal and generated `APP_KEY`.
- Health endpoints were added after scaffold generation.
- First backend HTTP run hit missing `sessions` table because the generated local `.env` still used database sessions. Bootstrap repair now sets `SESSION_DRIVER=file` until auth/session migrations are deliberately introduced.
- User verified `/health/live` returned `status=ok`.
- User verified `/health/ready` returned `status=ok` with database, Redis, and cache checks passing.

### Step 04: Auth, Tenancy, and Store Schema Foundation

Status: complete for schema foundation

Added:

- UUID-based users migration aligned with the reference schema.
- Tenants, memberships, invitations, and stores migration.
- CHECK constraints for role/state/currency/base URL/config version invariants.
- Models and relationships for User, Tenant, Membership, Invitation, and Store.
- User factory updated for `password_hash`.
- `docs/implementation-step-04-checklist.md`.

Verification:

- PHP syntax checks passed for migrations, models, and factory.
- User ran `php artisan migrate:fresh`; migrations completed.
- User ran `php artisan test`; 2 tests passed.
- `/health/ready` remained green after migrations.

Remaining:

- Add auth/session foundation and TenantContext in the next slices.

### Step 05: TenantContext Foundation

Status: complete for TenantContext foundation

Added:

- `TenantContext` scoped service.
- `MissingTenantContext` exception.
- Laravel container scoped binding.
- Unit tests for fail-closed behavior and scoped restoration.
- Feature test for container binding.
- `docs/implementation-step-05-checklist.md`.

Verification:

- PHP syntax checks passed for TenantContext, exception, provider, and tests.
- User ran `php artisan test`; 7 tests passed with 14 assertions.

Remaining:

- Add tenant resolution middleware after auth/session foundation.
- Enforce TenantContext in repositories/services/jobs as they are introduced.

### Step 06: Auth Session Foundation

Status: complete for auth session foundation

Added:

- Register/login/logout/me auth endpoints under `/api/v1/auth`.
- Register request validation and email normalization.
- Login request validation and email normalization.
- Transactional user + tenant + owner membership registration.
- Session `active_tenant_id` assignment after register/login.
- Feature tests for register, login/me, unauthenticated me, and logout.
- `docs/implementation-step-06-checklist.md`.

Verification:

- PHP syntax checks passed for auth controller, requests, routes, and tests.
- User ran `php artisan test`; 11 tests passed with 32 assertions.

Remaining:

- Add email verification, password reset, MFA, tenant switch, invitations, and policies in later slices.

### Step 07: Tenant Switch And Session Context

Status: complete for tenant switch and session context foundation

Added:

- ADR for bootstrap deviations from specification.
- `POST /api/v1/tenants/{tenant}/activate`.
- Session-backed TenantContext middleware.
- Diagnostic `GET /api/v1/tenants/context`.
- Feature tests for own tenant activation, foreign tenant rejection, and auth requirement.
- `docs/implementation-step-07-checklist.md`.

Verification:

- PHP syntax checks passed for middleware, controller, routes, and tests.
- User ran `php artisan test`; 14 tests passed with 40 assertions.

Remaining:

- Replace diagnostic context endpoint with real tenant DTO endpoints as the API matures.
- Add store CRUD endpoints with TenantContext enforcement.

### Step 08: Store CRUD Foundation

Status: complete for store CRUD foundation

Added:

- `POST /api/v1/stores`.
- `GET /api/v1/stores`.
- `GET /api/v1/stores/{store}`.
- Store create request validation.
- Store API controller scoped by `TenantContext`.
- Feature tests for create/list/detail isolation and HTTPS validation.
- `docs/implementation-step-08-checklist.md`.

Verification:

- PHP syntax checks passed for store controller, request, routes, and tests.
- User ran `php artisan test`; 19 tests passed with 55 assertions.

Remaining:

- Add PATCH store and optimistic versioning.
- Add domain verification and pairing flows later.

### Step 09: Store Update With Optimistic Versioning

Status: complete for store update foundation

Added:

- `PATCH /api/v1/stores/{store}`.
- `UpdateStoreRequest`.
- `If-Match`/`config_version` optimistic concurrency.
- 409 `version_conflict` response for stale updates.
- Base URL change resets `verified_at` and disables browser checks.
- Feature tests for update success, stale version, missing header, base URL reset, and foreign tenant rejection.
- `docs/implementation-step-09-checklist.md`.

Verification:

- PHP syntax checks passed for update controller, request, routes, and tests.
- User ran `php artisan test`; 24 tests passed with 71 assertions.

Remaining:

- Add role policy checks.
- Add store verification and pairing flows later.

### Step 10: Tenant Role Policy Foundation

Status: complete for tenant role policy foundation

Added:

- Tenant role constants.
- `tenant.role` route middleware.
- Store read/manage route role checks.
- Feature tests for viewer/operator/admin store permissions.
- `docs/implementation-step-10-checklist.md`.

Verification:

- PHP syntax checks passed for middleware, role helper, routes, and tests.
- User ran `php artisan test`; 27 tests passed with 78 assertions.

Remaining:

- Expand permission checks to incidents/checks/integrations/billing in future slices.

### Step 11: Store Verification Challenge Foundation

Status: complete for store verification challenge foundation

Added:

- `store_verifications` migration.
- `StoreVerification` model.
- `POST /api/v1/stores/{store}/verify` for DNS and platform-neutral plugin challenge creation.
- `GET /api/v1/stores/{store}/verification` for latest verification state.
- Tenant scoping and store role checks for verification endpoints.
- Feature tests for challenge creation, method validation, role denial, tenant isolation, latest state read, and pending expiration.
- `docs/implementation-step-11-checklist.md`.

Verification:

- PHP syntax checks passed for migration, model, request, controller, routes, and tests.
- User ran `php artisan test`; 34 tests passed with 105 assertions.

Remaining:

- Add external DNS/plugin challenge checks.
- Update store `verified_at` only after successful external ownership proof.
- Add pairing codes and integration credential bootstrap later.

### Step 12: Connector Pairing Exchange Foundation

Status: complete for connector pairing exchange foundation

Added:

- `integrations`, `integration_credentials`, and `pairing_codes` migrations.
- `Integration`, `IntegrationCredential`, and `PairingCode` models.
- `POST /api/v1/stores/{store}/pairing-codes` for owner/admin code creation.
- Stateless `POST /api/v1/pairing/exchange` for connector HMAC bootstrap.
- Platform-neutral `connector_code` instead of WordPress-only pairing.
- At-most-once pairing exchange with `consumed_at`.
- Base URL binding during exchange.
- 32-byte HMAC secret issue with base64 response and encrypted storage.
- Feature tests for code creation, role denial, foreign store isolation, exchange success, consumed/expired rejection, base URL mismatch, and connector code validation.
- `docs/implementation-step-12-checklist.md`.

Verification:

- PHP syntax checks passed for migrations, models, requests, controllers, routes, and tests.
- User ran `php artisan test`; 42 tests passed with 133 assertions.

Remaining:

- Add HMAC middleware, nonce replay protection, integration list/revoke/rotate, and audit log.

### Step 13: Integration HMAC Authentication Foundation

Status: complete for integration HMAC authentication foundation

Added:

- `integration.hmac` middleware.
- HMAC header validation for `X-BW-Key-Id`, `X-BW-Timestamp`, `X-BW-Nonce`, `X-BW-Signature`, and `X-BW-Signature-Version`.
- Canonical raw-body signature verification.
- Timestamp tolerance and query-string rejection.
- Cache-backed nonce replay protection.
- Credential status enforcement.
- Protected `POST /api/v1/ingest/heartbeat`.
- Feature tests for signed heartbeat, missing headers, altered body, nonce replay, stale timestamp, revoked credential, and query string rejection.
- `docs/implementation-step-13-checklist.md`.

Verification:

- PHP syntax checks passed for middleware, controller, bootstrap, routes, and tests.
- User ran `php artisan test`; 49 tests passed with 150 assertions.

Remaining:

- Add durable `POST /api/v1/ingest/events`, event schema validation, throttling, audit log, and rotated-key draining support.

### Step 14: Durable Event Ingress Inbox Foundation

Status: complete for durable event ingress inbox foundation

Added:

- `event_inbox` migration.
- `EventInbox` model.
- HMAC-protected `POST /api/v1/ingest/events`.
- Tenant/store/integration derivation from verified credential context.
- Batch envelope validation.
- Event envelope validation for required fields, supported schema version, known event/aggregate types, timestamps, and future clock skew.
- Canonical payload hashing for duplicate/conflict detection.
- Duplicate handling for identical event retries.
- Conflict handling for same event ID with different payload hash.
- `202` all accepted/duplicates and `207` mixed per-record results.
- Feature tests for accept, duplicate, conflict, mixed invalid, bad envelope, and unsupported schema version.
- `docs/implementation-step-14-checklist.md`.

Verification:

- PHP syntax checks passed for migration, model, controller, routes, and tests.
- User ran `php artisan test`; 58 tests passed with 185 assertions.

Remaining:

- Add full JSON Schema validation, projection processing, request size limits, throttling, quarantine persistence, and audit log.

### Step 15: Domain Outbox Foundation

Status: complete for domain outbox foundation

Added:

- `domain_outbox` migration.
- `DomainOutbox` model.
- `event_inbox.received` outbox topic.
- Pending outbox message creation in the same transaction as newly accepted inbox events.
- Dedupe by accepted `event_inbox.id`.
- Tests for accepted event outbox creation, duplicate retry no-op, and mixed batch outbox count.
- `docs/implementation-step-15-checklist.md`.

Verification:

- PHP syntax checks passed for migration, model, ingest controller, and tests.
- User ran `php artisan test`; 58 tests passed with 188 assertions.

Remaining:

- Add outbox leasing/dispatcher, projection worker, dead-letter transitions, retry/backoff, and consumer idempotency tests.

### Step 16: Domain Outbox Leasing Foundation

Status: complete for domain outbox leasing foundation

Added:

- Domain outbox status constants.
- `DomainOutboxLeaser` service.
- Ordered leasing for due pending messages.
- Active lease exclusion.
- Expired lease re-leasing.
- Attempt increment and `lease_until` update.
- Terminal message exclusion.
- Feature tests for due ordering, active lease skip, expired lease retry, and terminal status skip.
- `docs/implementation-step-16-checklist.md`.

Verification:

- PHP syntax checks passed for model, outbox leaser, and tests.
- User ran `php artisan test`; 62 tests passed with 199 assertions.

Remaining:

- Add dispatcher command/worker loop, side-effect execution, publish/dead-letter result handling, backoff policy, and consumer idempotency tests.

### Step 17: Domain Outbox Result Recording Foundation

Status: complete for domain outbox result recording foundation

Added:

- `DomainOutboxResultRecorder`.
- Publish result recording for active leases.
- Retry scheduling for failed active leases before max attempts.
- Dead-letter transition at max attempts.
- Stale lease result rejection.
- Non-leased message result rejection.
- Feature tests for publish, retry, dead-letter, stale lease rejection, and non-leased rejection.
- `docs/implementation-step-17-checklist.md`.

Verification:

- PHP syntax checks passed for outbox result recorder and tests.
- User ran `php artisan test`; 67 tests passed with 218 assertions.

Remaining:

- Add dispatcher command/worker loop, side-effect execution, exponential backoff with jitter, consumer idempotency tests, and manual replay/dead-letter resolution.

### Step 18: Domain Outbox Dispatcher Skeleton

Status: complete for domain outbox dispatcher skeleton

Added:

- `DomainOutboxDispatcher`.
- No-op handler for `event_inbox.received`.
- Unsupported topic failure handling.
- `outbox:dispatch` console command.
- Dispatch counters for leased/published/failed.
- Feature tests for known topic publish, unsupported topic retry, and console command execution.
- `docs/implementation-step-18-checklist.md`.

Verification:

- PHP syntax checks passed for dispatcher, console command, console routes, and tests.
- User ran `php artisan test`; 70 tests passed with 225 assertions.

Remaining:

- Add real projection processing, external side effects, long-running worker loop, exponential backoff with jitter, and consumer idempotency tests.

### Step 19: Event Inbox Processor Skeleton

Status: complete for event inbox processor skeleton

Added:

- `EventInboxProcessor`.
- `event_inbox.received` dispatcher handling now processes the referenced inbox row before publishing the outbox message.
- Idempotent success for already processed inbox rows.
- Retry path for missing or unprocessable inbox references.
- Feature tests for successful inbox processing, processed-row idempotency, missing inbox retry, unsupported topic retry, and console dispatch.
- `docs/implementation-step-19-checklist.md`.

Verification:

- PHP syntax checks passed for inbox processor, event inbox model, dispatcher, and dispatcher tests.
- User ran `php artisan test`; 72 tests passed with 232 assertions.

Remaining:

- Add Woo/order/payment/refund projections, projection-specific idempotency, external side effects, long-running worker loop, exponential backoff with jitter, sweeper, and manual replay/dead-letter tooling.

### Step 20: Order Snapshot Projection Foundation

Status: complete for order snapshot projection foundation

Added:

- `orders` and `order_revisions` migrations.
- `Order` and `OrderRevision` models.
- `OrderSnapshotProjector`.
- `order.snapshot` processing inside `EventInboxProcessor` before marking inbox events processed.
- Current order upsert by integration and external order ID.
- Immutable order revision insertion by order and source revision.
- Stale revision protection for current order state.
- `docs/implementation-step-20-checklist.md`.

Verification:

- PHP syntax checks passed for order projection migration, models, projector, inbox processor, and dispatcher tests.
- User ran `php artisan test`; 74 tests passed with 239 assertions.

Remaining:

- Add `order.deleted`, refund/payment/transaction/allocation projections, same-revision hash conflict handling, full JSON Schema validation, partial PostgreSQL indexes, reconciliation rules, and projection-specific idempotency tests.

### Step 21: Order Deleted Projection Foundation

Status: complete for order deleted projection foundation

Added:

- `OrderDeletedProjector`.
- `order.deleted` processing inside `EventInboxProcessor`.
- Soft-delete projection behavior through `orders.deleted_at`.
- Immutable order revision insertion for delete events.
- Stale delete revision protection for current order state.
- Retry path for delete events referencing unknown orders.
- `docs/implementation-step-21-checklist.md`.

Verification:

- PHP syntax checks passed for order deleted projector, inbox processor, and dispatcher tests.
- User ran `php artisan test`; 77 tests passed with 252 assertions.

Remaining:

- Add refund/payment/transaction/allocation projections, same-revision hash conflict handling, full JSON Schema validation, reconciliation rules, financial cleanup semantics, and projection-specific idempotency tests.

### Step 22: Order Deleted Tests Complete

Status: complete

Added:

- Recorded the completed Docker test run for Step 21.
- `docs/implementation-step-22-checklist.md`.

Verification:

- User ran `php artisan test`; 77 tests passed with 252 assertions.

### Step 23: Refund Snapshot Projection Foundation

Status: complete for refund snapshot projection foundation

Added:

- Financial projection migration with `refunds`, `payments`, and `financial_transactions`.
- `Refund` model.
- `RefundSnapshotProjector`.
- `refund.snapshot` processing inside `EventInboxProcessor`.
- Refund upsert by integration and refund external ID.
- Stale refund revision protection.
- Retry path for refunds referencing unknown orders.
- `docs/implementation-step-23-checklist.md`.

Verification:

- PHP syntax checks passed for financial projection migration, refund model, refund projector, inbox processor, and changed projector contracts.
- User ran `php artisan test`; 77 tests passed with 252 assertions.

Remaining:

- Add payment/transaction projections, refund allocations, reconciliation findings, same-revision hash conflict handling, and full JSON Schema validation.

### Step 24: Payment Snapshot Projection Foundation

Status: complete for payment snapshot projection foundation

Added:

- `Payment` model.
- `PaymentSnapshotProjector`.
- `payment.snapshot` processing inside `EventInboxProcessor`.
- Payment upsert by integration and payment external ID.
- Stale payment snapshot protection through `source_updated_at`.
- `docs/implementation-step-24-checklist.md`.

Verification:

- PHP syntax checks passed for payment model, payment projector, inbox processor, and changed projector contracts.
- User ran `php artisan test`; 77 tests passed with 252 assertions.

Remaining:

- Add transaction projection, payment allocations, provider adapter lookups, reconciliation findings, same-revision hash conflict handling, and full JSON Schema validation.

### Step 25: Financial Transaction Projection Foundation

Status: complete for financial transaction projection foundation

Added:

- `FinancialTransaction` model.
- `FinancialTransactionProjector`.
- `transaction.observed` processing inside `EventInboxProcessor`.
- Append-only transaction insert by integration, kind, and external operation ID.
- Optional link to existing payment projection by `payment_external_id`.
- Operation hash conflict rejection for duplicate operation keys with different content.
- `docs/implementation-step-25-checklist.md`.

Verification:

- PHP syntax checks passed for financial transaction model, transaction projector, inbox processor, and changed projector contracts.
- User ran `php artisan test`; 77 tests passed with 252 assertions.

Remaining:

- Add payment/refund allocations, operation correction strategy, reconciliation findings, same-revision hash conflict handling for mutable projections, and full JSON Schema validation.

### Step 26: Projection Failure Reason Codes

Status: complete for projection failure reason codes

Added:

- `EventProjectionResult`.
- Explicit projector failure reason codes.
- `EventInboxProcessor::lastErrorCode()`.
- Outbox retry error propagation from projector failure codes.
- `docs/implementation-step-26-checklist.md`.

Verification:

- PHP syntax checks passed for `EventProjectionResult`, inbox processor, dispatcher, and all projectors.
- User ran `php artisan test`; 77 tests passed with 252 assertions.

Remaining:

- Add admin dead-letter UI, manual replay dry-run summaries, localized operator-facing reason descriptions, payment/refund allocations, reconciliation findings, and full JSON Schema validation.

### Specification Update: Mandatory Three-Language Portal

Status: complete

Added:

- Mandatory `ru`, `en`, `de` locale coverage for public site, customer app, owner panel, admin panel, backend validation/problem messages, notifications, and user-facing exports.
- Updated base TZ, panel specs, UI API catalog, and panels acceptance.

Reason:

- Owner requirement: the whole backend/frontend portal must be multilingual in three languages, and deviations from specification must be documented.

### Step 27: Projection And Ingest Hardening

Status: complete

Added:

- `ProjectionValueNormalizer` for shared projection value normalization.
- Contract-aware event payload validation for supported normalized event types.
- Ingest request handling for malformed JSON and 1 MiB body limit.
- Quarantine persistence for invalid-but-identifiable events without creating projection outbox messages.
- Same-revision/source timestamp conflict detection for order, refund, and payment projections.
- Focused tests for ingest limits, quarantine behavior, and projection conflict failure codes.
- `docs/implementation-step-27-checklist.md`.

Verification:

- PHP syntax checks passed for changed ingest/projection files.
- `php artisan test tests/Feature/Ingest/IngestEventsApiTest.php` passed: 9 tests, 40 assertions.
- `php artisan test tests/Feature/Outbox/DomainOutboxDispatcherTest.php` passed: 13 tests, 44 assertions.
- `php artisan test` passed: 83 tests, 275 assertions.

Remaining:

- Add external schema validator only if future contract complexity justifies another dependency.
- Add admin quarantine/dead-letter UI, manual replay summaries, allocation services, reconciliation, and outbox sweeper in later steps.

### Step 28: Domain Outbox Worker Recovery

Status: complete

Added:

- `DomainOutboxBackoff` with exponential retry delay and jitter.
- `DomainOutboxSweeper` for explicit expired lease recovery.
- Dispatcher sweeper pass before each leasing cycle.
- Bounded loop mode for `outbox:dispatch`.
- Focused tests for retry backoff, expired lease recovery, and loop dispatch.
- `docs/implementation-step-28-checklist.md`.

Verification:

- PHP syntax checks passed for changed outbox command/support/test files.
- `php artisan test tests/Feature/Outbox/DomainOutboxResultRecorderTest.php` passed: 6 tests, 22 assertions.
- `php artisan test tests/Feature/Outbox/DomainOutboxDispatcherTest.php` passed: 14 tests, 46 assertions.
- `php artisan test tests/Feature/Outbox/DomainOutboxSweeperTest.php` passed: 1 test, 3 assertions.
- `php artisan test` passed: 86 tests, 283 assertions.

Remaining:

- Add scheduler/Horizon process integration, manual replay/dead-letter UI, tenant fairness, and event inbox processing leases in later steps.

### Step 29: Integration Lifecycle And Audit

Status: complete

Added:

- `audit_log` bootstrap table and `AuditLog` model.
- Tenant-scoped `GET /api/v1/integrations`.
- Tenant-scoped `GET /api/v1/integrations/{integration}`.
- Tenant-scoped `POST /api/v1/integrations/{integration}/revoke`.
- Integration DTO that returns credential summaries without ciphertext, fingerprint, or secret material.
- Revoke transaction that marks integration and non-revoked credentials revoked.
- Audit rows for pairing exchange and integration revoke.
- Focused tests for list/detail/filter/revoke/tenant isolation/role denial/audit.
- `docs/implementation-step-29-checklist.md`.

Verification:

- PHP syntax checks passed for changed integration/audit files.
- `php artisan test tests/Feature/Integrations/IntegrationApiTest.php` passed: 6 tests, 23 assertions.
- `php artisan test tests/Feature/Pairing/PairingApiTest.php` passed: 8 tests, 29 assertions.
- `php artisan test` passed: 92 tests, 307 assertions.

Remaining:

- Add credential rotation/draining endpoint, audit listing/export, Stripe integration APIs, and staff/platform audit separation later.

### Step 30: Payment And Refund Allocation Foundation

Status: complete

Added:

- `payment_allocations` bootstrap table.
- `refund_allocations` bootstrap table.
- `PaymentAllocation` and `RefundAllocation` models.
- `PaymentAllocationService` for locked capture and refund allocations.
- `AllocationRejected` exception with stable reason codes.
- Focused tests for successful allocation, sum limit rejection, and cross-tenant rejection.
- `docs/implementation-step-30-checklist.md`.

Verification:

- PHP syntax checks passed for allocation service/model/test files.
- `php artisan test tests/Feature/Payments/PaymentAllocationServiceTest.php` passed: 5 tests, 16 assertions.
- `php artisan test` passed: 97 tests, 323 assertions.

Remaining:

- Add manual allocation API, allocation revoke/unlink service, automatic matching, reconciliation runs/findings, and nightly allocation audit in later steps.

### Step 31: Allocation Revoke And Unlink Service

Status: complete for allocation revoke/unlink service

Added:

- `PaymentAllocationService::revokeCaptureAllocation()` and `revokeRefundAllocation()`.
- Reason-required and already-revoked rejections for both revoke paths.
- Rejection of capture allocation revoke while an active refund allocation still references it.
- `audit_log` row per revoke (actor, reason, `revoked_at`) via new `AuditLog` action/entity constants.
- `PaymentAllocation::refundAllocations()` inverse relation.
- Focused tests for revoke success/audit, double-revoke, missing reason, blocked-by-active-refund-allocation, revoke-after-refund-unlinked, and capacity freed for re-allocation.
- `docs/implementation-step-31-checklist.md`.

Verification:

- PHP syntax checks passed (PHP 8.4) for all changed files.
- `php vendor/bin/pint` applied to changed files only; pre-existing lint drift in unrelated files left untouched.
- `php artisan test` (PHP 8.4) passed: 104 tests, 337 assertions.

Remaining:

- Add manual/public allocation API and its `/revoke` HTTP endpoints, reconciliation runs/findings, automatic matcher strategies, and nightly allocation audit in later steps.

### Step 32: Reconciliation Runs And Findings Foundation

Status: complete for per-order reconciliation foundation

Added:

- `reconciliation_runs` and `reconciliation_findings` bootstrap tables (composite tenant/store FKs, pgsql CHECK constraints, `findings_recent_idx`).
- `ReconciliationRun` and `ReconciliationFinding` models.
- `OrderReconciliationService::evaluate()`: locked per-order evaluation producing `MONEY_UNSUPPORTED`, `MONEY_CAPTURE_MISSING`, `MONEY_CAPTURE_AMOUNT`, `MONEY_REFUND_MISSING`, and `MONEY_REFUND_EXTRA` findings from the allocation primitives added in Steps 30–31, with capture/refund grace windows (30/60 minutes) and zero tolerance.
- Focused tests covering pending/mismatch transitions, the no-op case, the unsupported short-circuit, the bookkeeping-only-refund-is-not-a-verified-refund case, and replay preserving finding history across runs.
- `docs/implementation-step-32-checklist.md`.

Verification:

- PHP syntax checks passed (PHP 8.4) for all changed/added files.
- `php vendor/bin/pint --test` on changed/added files: clean.
- `php artisan test` (PHP 8.4) passed: 114 tests, 369 assertions.
- `php artisan migrate --force` against real PostgreSQL 18 in local Docker applied the new migration cleanly, including pgsql-only CHECK constraints.

Remaining:

- Add public reconciliation/findings API, dirty-order coalescing and nightly sweep scheduling, the remaining rule codes (`MONEY_PAYMENT_WITHOUT_ORDER`, `MONEY_MULTIPLE_CAPTURES`, `MONEY_CURRENCY_MISMATCH`, `MONEY_ORDER_CHANGED`), `rule_configs`-backed versioned tolerance/grace, failed-run diagnostics outside the evaluation transaction, and incident engine correlation in later steps.

### Step 33: Remaining Reconciliation Rules

Status: complete for the remaining section-14 rule codes

Added:

- `OrderReconciliationService::activeCaptureAllocations()` shared lookup, reused by the capture-amount, multiple-captures, and order-changed checks.
- `MONEY_MULTIPLE_CAPTURES`, `MONEY_CURRENCY_MISMATCH`, and `MONEY_ORDER_CHANGED` as additional per-order rules — all three turned out to be resolvable from data already scoped to the order, not a store-wide scan.
- `UnmatchedPaymentScanner::scan(Store $store)`: a genuinely store-wide scan for `MONEY_PAYMENT_WITHOUT_ORDER` — succeeded captures older than a 24h orphan grace with no active allocation, capped at 100 findings per run.
- New `ReconciliationFinding` rule-code constants for all four.
- Focused tests for each rule's present/absent cases.
- `docs/implementation-step-33-checklist.md`.

Verification:

- PHP syntax checks passed (PHP 8.4) for all changed/added files.
- `php vendor/bin/pint --test` on changed/added files: clean.
- `php artisan test` (PHP 8.4) passed: 123 tests, 387 assertions.
- No schema change in this step, so no additional PostgreSQL migration verification was needed.

Remaining:

- Public reconciliation/findings API (Step 34), `rule_configs`-backed versioned tolerance/grace, nightly sweep scheduling and dedup, incident engine correlation, and the `GET /stores/{id}/unmatched-payments` candidate-suggestion endpoint.

### Step 34: Public Allocation And Reconciliation API

Status: complete for the scoped allocation + reconciliation HTTP surface

Added:

- `idempotency_keys` table and `EnsureIdempotencyKey` middleware (`idempotency` alias): reserve-then-run mutex via a unique DB constraint, replays the cached response for a repeated key+body, `409` for a repeated key with a different body, `400` for a missing header.
- `POST /payment-allocations`, `POST /payment-allocations/{id}/revoke`, `POST /refund-allocations`, `POST /refund-allocations/{id}/revoke`: tenant-scoped resolution, server-side currency revalidation, `AllocationRejected::httpStatus()` mapping (409 for state conflicts, 422 otherwise), `audit_log` row on every create and revoke.
- `POST /stores/{id}/reconciliations` (order_ids or store-wide unmatched-payment scan, `dry_run` explicitly rejected) and `GET /stores/{id}/findings` (filtered, cursor-paginated by finding `id`).
- Closed a real gap in `PaymentAllocationService::allocateRefund`: it now rejects linking a refund to a payment allocation from a *different* order (`ERROR_ORDER_MISMATCH`).
- `docs/implementation-step-34-checklist.md`.

Verification:

- PHP syntax checks passed (PHP 8.4) for all new/changed files.
- `php vendor/bin/pint --test`: clean.
- `php artisan test` (PHP 8.4) passed: 147 tests, 453 assertions.
- `php artisan migrate --force` against real PostgreSQL 18 in local Docker applied the new migration cleanly.

Remaining:

- Order/payment detail reads and the unmatched-payments candidate-suggestion endpoint, windowed bulk reconciliation triggers, true dry-run, cursor signing, rate limiting, and an idempotency-key expiry cleanup job.

### Step 35: Order And Payment Detail Reads

Status: complete for the three deferred detail/candidate-suggestion endpoints

Added:

- `GET /orders/{id}`: order fields plus latest finding per rule, captures, Woo-side refunds, refund transactions, allocations, and revision history.
- `GET /payments/{id}`: payment fields plus latest finding per rule, transactions, and allocations (both directions).
- `GET /stores/{id}/unmatched-payments`: exact-candidate (by `transaction_ref`) and manual-review (by same currency/amount, ranked by time proximity in PHP, not DB-specific SQL) suggestions for orphaned captures. Read-only — never writes an allocation itself.
- `App\Support\Api\UuidCursor`, extracted out of `ReconciliationController` and reused for the new unmatched-payments cursor.
- New `OrderDto`, `RefundDto`, `OrderRevisionDto`, `PaymentDto`, `FinancialTransactionDto`.
- Focused tests for all three endpoints, including cross-tenant 404s and the already-allocated-is-excluded case.
- `docs/implementation-step-35-checklist.md`.

Verification:

- PHP syntax checks passed (PHP 8.4) for all new/changed files.
- `php vendor/bin/pint --test`: clean.
- `php artisan test` (PHP 8.4) passed: 156 tests, 498 assertions.
- No schema change in this step, so no additional PostgreSQL migration verification was needed.

Remaining:

- Windowed bulk reconciliation triggers, true dry-run, cursor signing, rate limiting, idempotency-key expiry cleanup, automatic matcher, nightly allocation audit, and the incident engine.

### Step 36: Incident Engine Foundation

Status: complete for the money-family incident engine

Added:

- `signals`, `incidents`, `incident_signals`, `incident_activity`, `suppressions` tables and models.
- `MoneyIncidentCorrelator`: opens/attaches incidents from mismatch findings, auto-resolves on a fresh ok finding, reopens within 24h of resolution or creates a separate incident after. Groups rule codes into a coarser `component` (`capture`, `refund`, ...) for fingerprinting after discovering that fixing a capture-missing problem changes the triggering rule code to capture-amount, which would otherwise never match back to the original incident.
- `IncidentLifecycleService`: acknowledge, resolve, comment, snooze, revoke-suppression, each with its own rejection rules (idempotent acknowledge, no double-resolve, no double-revoke, 30-day snooze cap).
- Wired `MoneyIncidentCorrelator::correlate()` into `ReconciliationController::store()` so every trigger call also updates the incident state.
- Public API: `GET /incidents`, `GET /incidents/{id}`, `POST /incidents/{id}/acknowledge|resolve|comments|snooze`, `POST /suppressions/{id}/revoke`.
- `docs/implementation-step-36-checklist.md`.

Verification:

- PHP syntax checks passed (PHP 8.4) for all new/changed files.
- `php vendor/bin/pint --test`: clean.
- `php artisan test` (PHP 8.4) passed: 181 tests, 583 assertions.
- `php artisan migrate --force` against real PostgreSQL 18 in local Docker applied the new migration cleanly, including the partial-unique active-fingerprint index.

Remaining:

- Checkout/sales-drop incident families (need the browser worker and metrics pipeline), critical severity escalation, notifications on incident transitions, dirty-order coalescing/scheduler-driven re-correlation, automatic matcher, nightly allocation audit.

### Step 37: Email Notifications On Incident Transitions

Status: complete for the P0 email slice of section 23

Added:

- `notification_channels`, `notification_deliveries` (per `spec/database/schema.sql`) and `notification_channel_verifications` (schema addition, ADR 0002).
- Transactional request: `MoneyIncidentCorrelator` writes an `incident.notification_requested` outbox row in the same transaction as open/reopen/auto-resolve. `DomainOutboxDispatcher` fans it out via `IncidentNotificationPlanner` into one delivery per enabled+verified channel (store filter, severity threshold, recovery opt-out, quiet hours, suppression → `suppressed` row). Dedupe key `incident:{id}:rev:{revision}:{kind}` + unique `(tenant_id, channel_id, dedupe_key)` make replays safe.
- `NotificationDeliveryWorker` + `php artisan notifications:deliver`: retry 1m/5m/15m/1h/6h with `Retry-After`, dead letter after 24h with channel `health`, timeout → `uncertain` (not retried), stuck `sending` → `uncertain`.
- `NotificationChannelSender` interface, `EmailNotificationSender`, PII-free structured content rendered per locale from `lang/{ru,en,de}/notifications.php`; amounts via string arithmetic (`MinorUnits`).
- API: `GET/POST /notification-channels`, `PATCH /notification-channels/{id}`, `POST .../verify`, `POST .../test`, `GET /notification-deliveries`. Email verification code (hashed, 15 min, 5 attempts), owner-only critical quiet-hours bypass, audit on create/update/verify. Telegram rejected with `channel_kind_not_supported_yet`.
- `docs/adr/0002-notification-delivery-decisions.md` (behaviour choices for owner review), `docs/implementation-step-37-checklist.md`.
- `AGENTS.md` intro no longer claims the repository is specification-only.

Verification:

- PHP syntax checks passed (PHP 8.4) for all new/changed files.
- `php vendor/bin/pint --test` on new/changed files: clean (pre-existing findings in `DomainOutboxDispatcher.php` left as they were at HEAD).
- `php artisan test` (PHP 8.4) passed: 213 tests, 733 assertions.
- `php artisan migrate --force` against real PostgreSQL 18 in local Docker applied the new migration cleanly.
- Email sending exercised only through `Mail::fake` and an in-test sender, not a real SMTP provider.

Remaining:

- Telegram binding (P1), digest and reminders, re-notify after suppression revoke, maintenance windows, manual resend of uncertain/dead-letter, scheduler wiring for `outbox:dispatch`/`notifications:deliver`, resend-verification and channel deletion endpoints.

### Step 38: Scheduler And Dirty-Order Reconciliation

Status: complete for background reconciliation, grace re-checks, nightly sweep and scheduler wiring

Added:

- `reconciliation_dirty_subjects` and `scheduled_job_windows` tables (schema additions, ADR 0003).
- `DirtySubjectMarker` (30 s coalescing, version bump) wired into `EventInboxProcessor` (via `EventDirtyMarker`) and `PaymentAllocationService` create/revoke, inside their existing transactions.
- `DirtySubjectProcessor` / `reconciliation:process-dirty`: leased, tenant-scoped evaluation + incident correlation; delete-or-release by `mark_version`; backoff on failure.
- Grace re-checks via `evidence.grace_deadline_at` and `GraceRecheckScheduler` (processor and API trigger).
- `NightlyReconciliationSweep` / `reconciliation:nightly-sweep`: 90-day lookback, once per UTC day via `ScheduledWindowGuard`.
- Laravel schedule in `routes/console.php` for outbox, dirty processing, notification delivery and nightly sweep; `scheduler` service in Docker Compose.
- ADR 0002 marked accepted by the owner as-is; `docs/adr/0003-scheduler-and-dirty-reconciliation.md`; `docs/implementation-step-38-checklist.md`.

Verification:

- PHP syntax checks passed (PHP 8.4); pint applied to new/changed files.
- `php artisan test` (PHP 8.4) passed: 228 tests, 789 assertions.
- `php artisan migrate --force` against real PostgreSQL 18 in local Docker applied the new migration; all four commands smoke-run against it.
- `schedule:work` not run inside the Docker scheduler container yet.

Remaining:

- Per-tenant fairness and dead letter for dirty subjects, API rate limiting, stale-integration detection, cleanup jobs.

### Step 39: Sanctum SPA Sessions, CSRF And Auth Throttling

Status: complete; closes two ADR 0001 deviations (Sanctum, file sessions)

Added:

- `laravel/sanctum` ^4.3 with `statefulApi()`; removed the `api/*` CSRF exclusion; `auth:sanctum` on user routes; `RequireStatefulSession` middleware; explicit `web` guard in `AuthController`.
- `auth-login` / `auth-signup` rate limiters from the spec's pilot defaults (`config/watchdog.php`).
- `SESSION_DRIVER=database` and Sanctum/cookie settings in `.env.example`.
- `docs/implementation-step-39-checklist.md`; ADR 0001 sections marked resolved.

Verification:

- `php artisan test` (PHP 8.4) passed: 235 tests.
- Migration applied on local PostgreSQL 18; curl smoke of the real CSRF/session flow against `artisan serve` (419 without token, 201 with token, 401 from a foreign origin).

Remaining:

- Email verification, password reset, MFA; remaining ADR 0001 gaps (pairing hardening, JSON Schema ingest validation, store verification, projection composite FKs / PostgreSQL test run, MinIO).

### Step 40: Pairing And Connector Credential Hardening

Status: complete; closes the ADR 0001 pairing/keyring deviation for plugin HMAC credentials

Added:

- Pairing exchange throttle (20/hour/IP) and `integration.pairing_failed` audit.
- `Keyring` versioned encryption for integration secrets and notification destinations; `security:reencrypt-secrets`; keyring readiness check.
- Credential rotation: user request → heartbeat flag → signed plugin provisioning route → old key draining 24 h → revoked on first use of the new key; audit for each transition.
- `docs/implementation-step-40-checklist.md`; ADR 0001 section marked resolved.

Verification:

- `php artisan test` (PHP 8.4) passed: 245 tests.

Remaining:

- Plugin-side rotation client, Stripe credential rotation; remaining ADR 0001 gaps (JSON Schema ingest validation, store verification, projection composite FKs / PostgreSQL test run, MinIO).

### Step 41: Full JSON Schema Validation Of Ingested Events

Status: complete; closes the ADR 0001 ingest-validation deviation

Added:

- `opis/json-schema` 2.6; `EventSchemaValidator` against a backend copy of `contracts/event.schema.json` (sync test).
- Every ingested event is validated against the Draft 2020-12 schema; violations are quarantined as `schema_invalid`. Non-object bodies return 422 instead of 500.
- `docs/implementation-step-41-checklist.md`; ADR 0001 section marked resolved.

Verification:

- `php artisan test` (PHP 8.4) passed: 249 tests.

Remaining:

- ADR 0001: store verification, projection composite FKs / PostgreSQL test run, MinIO.

### Step 42: Store Domain Verification

Status: complete; closes the ADR 0001 store-verification deviation

Added:

- DNS TXT (`_bw-verify.<host>`) and connector challenge verification with HMAC-derived challenges, SSRF-safe fetcher (public IPs only, IP pinning, no redirects, timeout, size cap), on-demand check endpoint and scheduled `stores:check-verifications`.
- Successful verification sets `verified_at`, activates onboarding stores, audits `store.verified`; unverified stores cannot enable browser checks or become active; private/non-standard store URLs are rejected.
- Heartbeat delivers the pending challenge to the connector.
- `docs/implementation-step-42-checklist.md`; ADR 0001 section marked resolved.

Verification:

- `php artisan test` (PHP 8.4) passed: 259 tests. Migration applied on local PostgreSQL 18. Real-network smoke of DNS + safe fetcher (caught and fixed a Guzzle option bug).

Remaining:

- ADR 0001: projection composite FKs / PostgreSQL test run, MinIO; network-level egress proxy for browser checks.

### Step 43: Scoped Projection FKs And PostgreSQL Test Runs

Status: complete; closes two ADR 0001 deviations (SQLite-only tests, simplified projection FKs)

Added:

- Full suite on PostgreSQL 18 via `make backend-test-pgsql`; PG-only constraint tests.
- Scoped composite event FKs with `ON DELETE SET NULL (column)` on `order_revisions` and `financial_transactions`.
- Fix: idempotency reservation no longer aborts the PostgreSQL transaction on a repeated key (was 500 on replay; hidden by SQLite).
- `docs/implementation-step-43-checklist.md`; ADR 0001 sections marked resolved.

Verification:

- SQLite 259 passed + 4 PG-only skipped; PostgreSQL 263 passed.

Remaining:

- ADR 0001: local S3 storage (MinIO vs S3Mock). CI for both databases.

### Step 44: Local Object Storage Decision

Status: complete; formalizes the last ADR 0001 deviation

Added:

- `docs/adr/0004-local-object-storage.md`: MinIO images are no longer publicly pullable (checked Docker Hub and quay.io with a pinned tag); S3Mock 5.2.2 stays the pinned local S3 endpoint; application code limited to portable S3 features; revisit with the artifact feature.
- ADR 0001 status updated: every deviation now has a Resolution note (Steps 39–44).

Verification:

- `docker pull` of pinned MinIO tags from both registries failed (denied/unauthorized). No code change; test suites unchanged (SQLite 259 + 4 skipped, PostgreSQL 263).

### Step 45: WooCommerce Plugin Foundation And Compatibility Matrix

Status: complete for the plugin foundation; owner chose the floor PHP 7.4 / WP 5.9 / WC 6.0

Added:

- `plugins/woocommerce-watchdog`: capability-detecting compat layer, local tables, secret storage, pairing (admin/REST/WP-CLI), signed heartbeat with rotation and suspension, public challenge endpoint, diagnostics, uninstall.
- Docker matrix (6 pinned targets, legacy + HPOS, classic + blocks) with in-WordPress integration tests and an e2e against the backend.
- `docs/compatibility.md` filled with executed combinations; `docs/adr/0005-woocommerce-plugin-compatibility.md`; `docs/implementation-step-45-checklist.md`.

Verification:

- PHP 7.4 lint; integration 8/8 and e2e pass on floor, wc7-legacy, wc7-hpos, wc8, wc9, latest. Backend suite green.

Remaining:

- Order/refund snapshots + revisions + outbox delivery (Step 46), rescan/backfill, capabilities/deployment events.

### Step 46: WooCommerce Order And Refund Snapshots

Status: complete for capture into the local outbox

Added:

- Revisions (schema v2), outbox writes, minor-unit conversion, order/refund snapshot builders, shutdown-time capture from hooks, deletion/trash handling, cache busting.
- The matrix found real version differences (WC 7.9 HPOS fires no hook on refund deletion and keeps stale caches after trash/refund deletion; `checkout-draft` absent in WC 6.0; refund deletion hooks differ between 8.9/9.9/11.2); each is handled and documented in `docs/compatibility.md`.
- `docs/implementation-step-46-checklist.md`.

Verification:

- Integration 20/20 and e2e (events validated by the backend) on all six targets; PHP 7.4 lint; backend suite green.

Remaining:

- Outbox delivery, rescan/backfill, capabilities/deployment events (Step 47).

### Step 47: Plugin Delivery, Rescan, Backfill And Environment Events

Status: complete; the WooCommerce plugin now delivers end-to-end to the backend

Added:

- Signed batch delivery with per-event results, dead letter, suspension, Retry-After and jittered backoff; atomic delivery lock.
- 48 h rescan every 15 min, 90-day resumable backfill after pairing and daily, capture gated on connection.
- Capabilities and deployment events; WP-CLI deliver/rescan/backfill; diagnostics.
- `docs/implementation-step-47-checklist.md`.

Verification:

- Integration 29/29 and e2e with real delivery + backend projections on all six WooCommerce targets; PHP 7.4 lint; backend suite green.

Remaining:

- Backlog degradation flag/limits, diagnostics download, browser-check support, telemetry (P2).

### Step 48: Provider Coverage Gating (ADR 0006, item 1)

Status: complete; without a connected independent provider money reconciliation no longer produces false incidents

Added:

- `ProviderCoverage` (active `independent_provider` integration), gating in `OrderReconciliationService` (`MONEY_UNSUPPORTED` / `unknown` / `provider_not_connected`), `UnmatchedPaymentScanner` and the unmatched-payments endpoint (independent captures only).
- Allocation of non-independent capture/refund transactions is rejected (`allocation_source_not_independent`) — store-reported data can never stand in for a provider capture.
- Ingest rejects `independent_provider` evidence from `store_reported` credentials (`source_authority_not_permitted`).
- `StoreReconciliationRequeue` (shared with the nightly sweep); revoking a provider integration requeues the store's 90-day window.
- `docs/implementation-step-48-checklist.md`; ADR 0006 updated.

Verification:

- SQLite 264 passed + 4 skipped; PostgreSQL 18 268 passed.

Remaining:

- Stripe read-only connector (ADR 0006 item 2), which will also requeue on connect.

### Step 49: Payment Attempt Monitoring, Layer 1 (Spec §17.1, ADR 0007)

Status: complete; real customer payment attempts are observed server-side and a payment method that stops working opens an incident

Added:

- Contract `checkout.payment_attempts` (aggregate `checkout`): closed 5-minute windows with per-method outcome counters, `trailing_failures` and failure classes; no customer data.
- Plugin schema v3: `bw_payment_attempts`, `AttemptLog`, `AttemptHooks` (Classic + Store API/Blocks, outcomes from payment completion and status changes), `PaymentAttemptsJob` (60 s; re-check, 30-minute `pending_stuck`, once-per-window reporting in one transaction), WP-CLI `payment-attempts`.
- Plugin fix: delivery re-encoded events through PHP arrays, turning `{}` into `[]` (would have quarantined windows without failures).
- Backend: `payment_attempt_windows`, semantic validation, `PaymentAttemptsProjector`, `PaymentAttemptMonitor` (`CHECKOUT_PAYMENTS_FAILING`, family `checkout_payment`, open/attach/auto-resolve/reopen), family-aware notifications (ru/en/de).
- Matrix e2e now places real HTTP checkouts (Classic and Store API) with a declining test gateway (`tests/matrix/mu-plugins`), an invalid e-mail and bank transfer, then checks the local attempts and the backend windows.
- ADR 0007 records the outcome semantics and thresholds chosen for the owner to confirm; `docs/implementation-step-49-checklist.md`.

Verification:

- Backend SQLite 273 passed + 4 skipped; PostgreSQL 18 277 passed.
- Plugin integration and e2e on the six WooCommerce targets — see `docs/compatibility.md`.

Remaining:

- Order-pay page, statistical success-rate rule, dashboard coverage, entitlement gating, layer 2 checkout script.

### Step 50: Store Connector Freshness (ACC-14, ADR 0009)

Status: complete; a silent or lagging store plugin is now detected instead of looking healthy

Added:

- Heartbeat stores the plugin self-report; `ConnectorFreshness` assesses `warming_up` / `fresh` / `stale` / `partial`, flips the integration `active` ↔ `degraded`, opens/resolves an `integration` incident; `integrations:check-freshness` every minute.
- Money reconciliation and the unmatched-payment scan return unknown while store data is stale; recovery requeues the store window.
- Shared `IncidentRecorder` (used by the payment-attempt monitor and connector freshness); notification texts (ru/en/de) saying data is missing, not that sales stopped.
- `docs/adr/0009-connector-freshness.md`, `docs/implementation-step-50-checklist.md`.

Verification:

- SQLite 282 passed + 4 skipped; PostgreSQL 18 286 passed; plugin e2e on WooCommerce 11.2.

Remaining:

- Plugin backlog flag/limits, deactivation report, dashboard coverage.

### Step 51: Browser Check Backend (Spec §18–20, ADR 0010)

Status: complete for the backend; the worker follows in Step 52

Added:

- Browser check tables, worker credentials, `browser:schedule`, internal lease/heartbeat/result API with fencing and expiry recovery.
- Outcome policy (two site failures confirm; worker problems never do), checkout and coverage incidents with scheduled-pass recovery, notifications (ru/en/de).
- Strict validation of sanitized worker results; user API for the scenario, manual runs and run history; OpenAPI updated.
- `docs/adr/0010-browser-check-backend.md`, `docs/implementation-step-51-checklist.md`.

Verification:

- SQLite 298 passed + 4 skipped; PostgreSQL 18 302 passed.

Remaining:

- Worker (Step 52); artifacts, synthetic marker, Blocks draft cleanup (Step 53).

### Step 52: Browser Worker (Node + Playwright, ADR 0011)

Status: complete; real browser checks reach the payment form on all six WooCommerce targets without placing an order

Added:

- `apps/browser-worker`: lease loop, WooCommerce adapter (Classic + Blocks), network policy (cart-only mutations, order/pay/capture/refund blocked), sanitized diagnostics, Docker image (sandbox on, read-only, no capabilities), Compose service, unit tests.
- `tests/matrix/browser-e2e.sh` (`make worker-e2e`) with the real backend; the matrix mu-plugin can disable its test gateway for the no-payment-methods case.
- `docs/adr/0011-browser-worker.md`, `docs/implementation-step-52-checklist.md`, compatibility table.

Verification:

- Worker unit tests 10/10; browser e2e PASS on all six targets with unchanged order counts; backend suites green.

Remaining:

- Egress proxy, artifacts, synthetic marker, Blocks draft cleanup (Step 53).
