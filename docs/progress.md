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

### Step 53: Synthetic Marker, Blocks Draft Cleanup, Egress Proxy (ADR 0012)

Status: complete

Added:

- Backend synthetic marker in the lease; worker sends it to the store origin only; plugin verifies it, marks orders, flags synthetic events, skips payment-attempt monitoring and cleans only its own old checkout drafts.
- Squid egress proxy (`infra/docker/egress`), Compose isolation of the worker, `make egress-test`; the worker refuses production without the proxy.
- `docs/adr/0012-synthetic-marker-and-egress.md`, `docs/implementation-step-53-checklist.md`.

Verification:

- Backend SQLite 299 + 4 skipped, PostgreSQL 303; worker 13/13; plugin integration and browser e2e on all six targets (marker verified per run, no orders, no payment attempts); egress 7/7.

Remaining:

- Screenshots/artifacts (Step 54).

### Step 54: Check Artifacts — Redacted Screenshots (ADR 0013)

Status: complete; browser checks P0 done (Steps 51–54)

Added:

- `artifacts` table and private S3 disk; lease-bound internal upload with size/signature/SHA-256 checks; 60-second user links; daily purge; OpenAPI.
- Worker: masked viewport screenshot on failures, focused on the failing step.
- `docs/adr/0013-check-artifacts.md`, `docs/implementation-step-54-checklist.md`.

Verification:

- Backend SQLite 302 + 4 skipped, PostgreSQL 306; worker 13/13; browser e2e on six targets with S3Mock; screenshot inspected.

### Step 55: API Readiness for the Dashboard (ADR 0014)

Status: complete; the API the minimal dashboard needs is contract-described and enforced

Added:

- Real store coverage summary (connector, money, payment attempts, browser checks, last passed check, active incidents) instead of placeholders.
- Catalogue routes for scenarios, check runs, cancel, artifact download, store integrations; `If-Match`/`ETag`; `X-Request-ID`.
- OpenAPI for all user endpoints (generated from one description), heartbeat/rotation documented as implemented; `OpenApiRoutesTest`; catalogue §11 with deviations and gaps.

Verification:

- SQLite 309 passed + 4 skipped; PostgreSQL 18 313 passed.

### Design: Corporate Palette (ADR 0015)

Status: complete; palette frozen by owner decision

Added:

- Seven OKLCH-generated 11-step scales (brand, ink, success, warning, danger, info, unknown), every semantic text/background pair WCAG 2.1 AA.
- `apps/frontend/design/palette.json` (source), `apps/frontend/src/styles/palette.css` (Tailwind v4 `@theme`, default colours disabled, semantic tokens), `docs/design/palette.md` (values, contrast, status mapping), `scripts/check-palette.mjs` + `make frontend-palette-check` (fingerprint, CSS drift, defaults disabled).
- Preview page published for the owner.

### Step 56: Dashboard Scaffold (ADR 0016)

Status: complete

Added:

- `apps/frontend`: Vue 3 + TypeScript + Vite 8 + Tailwind 4 (pinned, Node 24 LTS), Sanctum session client, i18n ru/en/de, router with auth guard, layout, login, store overview with coverage cards.
- Tests: contract states ↔ colours/labels, translation parity, API client (CSRF, headers, errors, 401), store card; Chromium smoke against the real backend (`make frontend-smoke`).
- Makefile targets `frontend-install/dev/check/smoke`.

Verification:

- `make frontend-check` green (12 tests, build); smoke 8/8 with screenshots inspected; backend suites green.

### Step 57: Frontend Workspaces (ADR 0017)

Status: complete

Added:

- `apps/frontend` as an npm workspace: `customer/` (`/app`, the Step 56 project), `control/` (`/owner`, `/admin` placeholders, `/platform-api` proxy), `public/` (vite-ssg prerendered site in ru/en/de), shared `packages/design|ui|i18n|api-client`.
- Makefile targets `frontend-dev-control`, `frontend-dev-public`; palette check moved to `packages/design`.
- Root npm scripts use `node "$npm_execpath"`: a bare nested `npm` resolved to an old npm 5 in an ancestor `node_modules/.bin`, which ignored `-w` and recursed until the machine froze (the previous session ended this way).

Verification:

- `make frontend-check` green (customer 11, control 2, public 3 tests, builds, prerender); customer smoke 8/8; backend SQLite 309 + 4 skipped.

### Step 58: Dashboard Store Screens

Status: complete

Added:

- Customer app: "Add store" form (`/app/stores/new`: name, HTTPS address, time zone, currency; locale from the UI) and the store page (`/app/stores/:id`): coverage summary, store plugin panel (connectors with status, last signal and version; one-time connection code with the service URL and WooCommerce steps, list refreshes until the plugin connects; disconnect with an explicit confirmation), domain confirmation (via the plugin — only when a connector exists — or a DNS TXT record; instructions, translated reason codes, polling while pending, "check now"), settings (name, time zone, currency with `If-Match`, a conflict reloads the store) and pause/resume monitoring.
- Management actions are shown only to owner/admin of the active tenant (the backend still enforces roles); server texts are never shown, errors map from status/code to translations (ru/en/de).
- Backend: while a verification is pending, `GET /stores/{id}/verification` and `.../verification/check` also return `instructions`, so DNS values survive a reload; `POST /stores/{id}/pairing-codes` returns `service_url`. OpenAPI and catalogue §11 updated.
- `CoverageList` extracted from `StoreCard`; the store name on the overview links to the store page.

Verification:

- `make frontend-check` green (customer 21 tests, control 2, public 3, builds, palette).
- `make frontend-smoke` 13/13 against the real backend: store page, DNS confirmation start, instructions after reload, manual check reason, connection code, adding a store and saving settings, German mobile layout without horizontal scroll; failed requests are now checked by URL (only the expected 401/422/404 are allowed). Screenshots inspected.
- Backend: SQLite 310 + 4 skipped, PostgreSQL 18 314 passed.
- Not tested: a real plugin pairing through the dashboard and a real DNS record (the smoke store uses a non-existent `.example` domain).

### Step 59: Dashboard Incident Screens

Status: complete

Added:

- Customer app `/app/incidents`: Active (open + acknowledged) / Resolved tabs, store and severity filters kept in the URL, cursor "show more"; the incident badge on the overview and the store page opens the store's incidents.
- `/app/incidents/:id`: severity/state, last good / first bad (with the "somewhere between" note), verified discrepancy only for money incidents, advice per title code, facts (findings with expected/found/difference per order, plain-language signal evidence for payment attempts, plugin connection and browser checks, masked screenshot of the last check on demand), timeline with comments (plain text), actions: acknowledge and resolve with a reason (`If-Match` revision; a conflict reloads), snooze notifications 1 h–7 d with a reason and lift it, recheck (money: reconcile the orders behind the findings or the unmatched-payment scan; checkout: manual run of the failed run's scenario).
- Money is formatted from minor-unit strings with the exponent via `Intl.NumberFormat` on decimal strings, never JS Number (tested beyond 2^53); unknown amounts are shown as unknown, not zero.
- Stable error codes are translated (`errors.<code>`); a test fails if any title code the backend can raise lacks a title or advice.
- Backend: `GET /incidents` newest first, `state` accepts a comma-separated list; `Incident.currency_exponent` from the linked finding; `GET /incidents/{id}` adds `findings` (with order display number) and `active_suppression`. OpenAPI and catalogue §11 updated.

Verification:

- `make frontend-check` green (customer 31 tests).
- `make frontend-smoke` 17/17: badge → store incidents → incident page, comment with HTML shown as text, acknowledge, snooze and lift, resolve with a reason, Resolved tab, German mobile layout; screenshots inspected.
- Backend: SQLite 311 + 4 skipped, PostgreSQL 18 315 passed.
- Not tested in the browser: money incidents with findings and the check screenshot (covered by unit and API tests only; the smoke incident is a seeded payment-attempts incident).

### Step 60: Dashboard Reconciliation Screens

Status: complete

Added:

- Customer app `/app/reconciliation`: store picker, coverage warning when money cannot be reconciled (no provider / store not connected / stale data — results are "unknown", not "fine"), Discrepancies tab (current state by default or full history; filters result, check, currency, date range, all kept in the URL; neutral check names, order links, expected/found/difference) and Payments-without-an-order tab (provider captures with the provider operation ID, candidates with their confidence, manual match of the whole capture with a reason), store-wide "find payments without an order".
- `/app/orders/:id`: order total, captured at provider, refunds in store and at provider kept separate (unknown stays unknown), store "paid" marker shown as a store claim, latest reconciliation with "reconcile order", captures counted for the order with revoke (reason), store and provider refunds, refund links with revoke and a manual refund link (amount = the smaller unlinked remainder, BigInt arithmetic), order revisions.
- Incident evidence reuses the findings table and links orders.
- `newIdempotencyKey` falls back to `crypto.getRandomValues` outside secure contexts (found by the smoke over plain HTTP: every Idempotency-Key mutation failed in the browser before reaching the API).
- Backend: findings newest first (the contract said so, the code returned oldest first) with `order_display_number` and `current=1`; unmatched payments carry `external_operation_id`, `currency_exponent`, candidate `display_number`/`currency_exponent`; order detail findings come from the latest run only and `refund_transactions` include unlinked provider refunds of the payments allocated to the order. OpenAPI and catalogue §11 updated.
- Smoke seeds a provider, a paid order without a capture and an unmatched capture; failed smoke steps now save `failure.png`.

Verification:

- `make frontend-check` green (customer 39 tests).
- `make frontend-smoke` 21/21, run twice: discrepancy list, manual match, order recheck shows the capture and "matches", revoke with a reason, German mobile order page; screenshots inspected.
- Backend: SQLite 313 + 4 skipped, PostgreSQL 18 317 passed.
- Not done: CSV export (no export API yet), per-currency discrepancy totals, partial amounts for manual matches (the UI links whole captures / the full unlinked refund remainder). Tested with seeded data only, not a real Stripe account.

### Step 61: Dashboard Checkout-Check Screens

Status: complete

Added:

- Customer app `/app/checks`: store picker; readiness (domain confirmed, store active, checks enabled for the store with an on/off switch, scenario set up and enabled — scheduled checks run only when all are met); scenario panel (create from the test product page, cart/checkout detected automatically or set, interval 5 min–24 h, enable/disable with the scenario version, advanced: allowed external origins and synthetic shipping country/postcode; what is checked, what is not covered, "nothing is bought"); run history with "run now" (only when ready), auto-refresh while a run is queued or running.
- `/app/checks/runs/:id`: status with a plain-language note (inconclusive/blocked/cancelled are not store failures), times, attempts with location and browser, steps with their status and error, masked screenshot on demand, sanitized technical details, cancel an active run with a reason. Incident evidence links to the run.
- Contract tests: every run/attempt/step state in OpenAPI has a colour and a label in ru/en/de and only "passed" is green; every worker/backend error code is explained.
- Fixed a request race on the checks, incidents and reconciliation lists: a slower response for a previous store or filter could overwrite the current one (seen once in the smoke as a missing "enable checks" button); stale responses are discarded, with a test that fails without the fix.
- Smoke: seeds a verified "Smoke Check Shop" with a disabled scenario and a failed run with steps; clears the local rate limiter before starting (back-to-back runs hit the 5 logins/min limit).

Verification:

- `make frontend-check` green (customer 49 tests).
- `make frontend-smoke` 26/26, six consecutive runs: readiness, enable scenario, change interval, run now → queued → cancel with a reason, failed run steps and details, German mobile layout; screenshots inspected.
- Backend unchanged in this step (last runs: SQLite 313 + 4 skipped, PostgreSQL 18 317).
- Not covered in the browser: a real worker run and the screenshot of a run (no worker or object storage in the smoke); scenario creation is covered by unit tests.

### Step 62: Dashboard Notification Screens

Status: complete

Added:

- Customer app `/app/notifications` (owner/admin manage; everyone sees the log): add an email recipient with preferences (minimum severity, email language, time zone, quiet hours, critical during quiet hours — owner only, recovery messages, all or selected stores); confirm with the mailed 6-digit code or request a new one; enable/disable; test email; settings; change address (requires a new confirmation). Channel state: receiving / disabled / awaiting confirmation / emails not delivered (from channel health).
- Delivery log with channel and state filters: kind, recipient, time, attempts, next attempt, translated error, incident link; "delivery unknown" is explained (not resent to avoid duplicates) and shown as a warning, not as delivered.
- Backend: `POST /notification-channels/{id}/verification-code` — a new code to the saved address of an unverified channel, once a minute (before, a new code needed an address change); the contract now lists `Idempotency-Key` on channel creation and `content`/`created_at` of deliveries. Catalogue §11 updated.
- Contract tests: every delivery state has a colour and a label (only "sent" green); every delivery error and channel rejection code from the backend is translated.
- Smoke runs real email: Mailpit receives the confirmation code, the browser test reads it through the Mailpit API, and a background `notifications:deliver` loop delivers the test email.

Verification:

- `make frontend-check` green (customer 55 tests).
- `make frontend-smoke` 30/30, twice: add recipient, confirm with the code from the real email, test email delivered by the worker (in Mailpit and in the log), quiet hours saved, German mobile layout; emails and screenshots inspected.
- Backend: SQLite 314 + 4 skipped, PostgreSQL 18 318 passed.
- Not done: Telegram (P1), deleting a recipient (disable instead), daily digest/reminders. The mobile navigation with five items takes a lot of height; a compact menu is a follow-up.

### Step 63: Dashboard Overview and Mobile Menu (ADR 0018)

Status: complete

Added:

- Backend `GET /api/v1/overview`: active incidents by severity and the 5 latest, verified discrepancies of active money incidents summed by the database per currency and component (never across currencies or kinds), the number of active money incidents without a verified amount, the 5 latest finished checks. Decisions in `docs/adr/0018-overview-discrepancy-totals.md` (for owner review). OpenAPI and catalogue §11 updated.
- Overview page: verdict banner — "problems found" with active incidents; "no problems found" only when every store has a fresh connector, money reconciling, payment attempts observed and passing checkout checks; otherwise "data incomplete" with the number of stores with gaps. Tiles: active incidents by severity, verified discrepancies per currency and kind labelled "not losses" with the unknown-amount count, last successful checkout check; latest incidents, recent checks, then the store cards.
- Navigation below 768 px collapses into a "Menu" button with a panel (sections, user, language, sign out); the current section stays highlighted on child pages (store, incident, order, check run) and the panel closes after navigation.
- Smoke seeds money incidents through the real correlator, checks the verdict and the EUR capture total, and uses the menu at mobile width.

Verification:

- `make frontend-check` green (customer 59 tests: verdict rules, totals never added across kinds, menu behaviour).
- `make frontend-smoke` 32/32 twice; screenshots inspected.
- Backend: SQLite 315 + 4 skipped, PostgreSQL 18 319 passed (overview totals checked above 2^53 on both).
- Limits (ADR 0018): live and test-mode incidents are not separated; a sum above the signed 64-bit range fails on SQLite only.

### Step 64: Account and Team (ADR 0019)

Status: complete

Added:

- Backend: password reset request/complete (same answer for unknown addresses, one-time 60-minute link stored as a hash, ends every session, confirms the address), email confirmation (signed 24-hour link sent at sign-up, works without a session, resend once a minute), `PATCH /auth/me` (name, language), `POST /auth/password` (needs the current password, ends other sessions), team: `GET/PATCH/DELETE /memberships`, `GET/POST /invitations`, revoke, public lookup, accept, and sign-up through an invitation (joins the team, no new tenant). Role rules, audit and limits in `docs/adr/0019-account-and-team.md` (for owner review). Mail texts in ru/en/de (`lang/*/account.php`).
- Fixed: a request without access to an active team (no team in the session or membership removed) returned HTTP 500; it now answers `403 tenant_forbidden` (an old test had asserted the 500). The email confirmation route sat behind the SPA-session middleware, so a link opened from an email was rejected — found by the smoke, moved out.
- Customer app: "Forgot password?" → request page → reset page from the email; banner "confirm your address" with resend and the result notice after the link; Settings → Profile (name, language — also switches the UI, address state, password change) and Team (members with role selects only where allowed, remove/leave with confirmation, invite by email with allowed roles, pending invitations with revoke); invitation page (accept when signed in with the invited address, warning for another address, sign-in for an existing account, account creation for a new person). Header name links to settings; mobile menu has Settings.
- Smoke (real email through Mailpit): the owner confirms the address from the email; invites an operator; in a separate browser the operator creates an account from the invitation email and later restores a forgotten password from the reset email.

Verification:

- `make frontend-check` green (customer 66 tests: password rules, team permissions, every invitation state, every account rejection code translated).
- `make frontend-smoke` 36/36, five consecutive runs; emails and screenshots inspected.
- Backend: SQLite 327 + 4 skipped, PostgreSQL 18 331 passed (account 6 and team 6 new feature tests).
- Not done: MFA, ownership transfer, several teams per user in the dashboard (create/switch), per-device session list.

### Step 65: Stripe Read-Only Connector — Backend (ADR 0020)

Status: complete for the backend; tested with Stripe-shaped fixtures only (no real Stripe account yet)

Added:

- `POST /stores/{id}/integrations/stripe` (restricted key only, full secret keys refused, read probes for payment intents/charges/refunds, optional account check and expected account, encrypted key and webhook secret, one Stripe integration per store, window re-queued), `PUT /integrations/{id}/webhook-secret`, `POST /integrations/{id}/sync` (delta|audit), `POST /webhooks/stripe/{integration}` (signature checked by the official `stripe/stripe-php` 22.0 library).
- Mapping: PaymentIntent → `payment.snapshot`; captured charge → capture `transaction.observed`; refund → refund operation; other mode and unconfirmed currencies skipped. Change detection per object (`provider_object_states`) with content-derived event ids — re-syncs and webhook/poll races emit nothing new.
- Shared `EventIngestor` (plugin ingress and server adapters use the same validation, authority check, idempotency and outbox).
- Independent-provider operations may change status (pending → succeeded → failed) in observation order with history; operations that arrive before their payment are attached later.
- `ExactReferenceMatcher`: captures linked to the single order whose transaction reference is the PaymentIntent/charge id; provider refunds linked to the store refund naming them; ambiguity links nothing.
- `stripe:sync` every 15 min (delta with 60 min overlap, page budget with backlog), `stripe:sync --audit` daily (90 days). A rejected key degrades the integration, stops calls and turns money checks back to unknown.

Verification:

- Backend: SQLite 334 + 4 skipped, PostgreSQL 18 338 passed; 7 new end-to-end tests (connect rules, sync → allocation → reconciliation without discrepancy, refund lifecycle and refund link, rejected key → unknown, skipped mode/currency, webhook signature, page-budget backlog).
- Not tested with real Stripe: the compatibility spike (Stripe sandbox + WooCommerce Stripe Gateway) is still required — see ADR 0020.

### Step 66: Stripe Connection in the Dashboard

Status: complete; one unexplained smoke failure (see below)

Added:

- Store page "Payment provider" panel: connection form with step-by-step Stripe instructions (restricted key only, a full `sk_` key is refused in the UI and the API), optional webhook secret and expected account; connected state with mode, account (verified or not), key `rk_••••last4`, last sync and its result, last error, webhook state; "Sync now", webhook secret form with the endpoint URL, disconnect with confirmation; a rejected key is shown as a separate alert.
- Smoke: a fake Stripe API (`tests/e2e/fake-stripe.php`) and a background `outbox:dispatch` loop; the smoke refuses a secret key, connects with a restricted key, syncs (2 events) and finds the Stripe capture among payments without an order.

Verification:

- `make frontend-check` green (customer 70 tests).
- `make frontend-smoke`: 38/38. One earlier run failed once at "incident page in German at mobile width"; four reruns did not reproduce it. The step now waits for the Resolved tab to be selected (URL `tab=resolved`) before clicking; seven consecutive runs passed after that.
- Backend unchanged since Step 65: SQLite 334 + 4 skipped, PostgreSQL 18 338 passed.
- Not tested with real Stripe (fake API only).

### Stripe Compatibility Spike — Part 1 (real test account)

Status: done for reading; captures/refunds and the WooCommerce gateway are still open

- With the owner's test-mode restricted key: payment intents, charges and refunds are readable; `/v1/account` and `/v1/invoices` answer 403 (account stays "unverified", as designed).
- The owner's invoice marked paid produced a cancelled PaymentIntent without a charge; the connector projected a cancelled payment and no capture — correct, since no money moved through Stripe.
- Connect → audit sync → processing → re-sync without new events verified on real data (temporary store, removed after the run). Details in ADR 0020.
- Next for the spike: a real test-card payment, a partial refund and a failed payment (Stripe Dashboard or the WooCommerce Stripe Gateway on the test site) to confirm capture/refund shapes, the order transaction reference and gateway metadata.

### Stripe Compatibility Spike — Part 2 (real payments)

Status: done; only the WooCommerce Stripe Gateway part remains

- Owner's test-mode data: captured payment with a partial refund, two declined payments, an uncaptured authorization, an invoice marked paid. The connector produced captured / failed / failed / authorized / cancelled payments, one capture (500) and one refund (200); no capture for failures, the authorization or the invoice.
- Exact-reference matching linked the capture to the test order and the refund to the store refund; reconciliation gave capture ok (500 = 500) and refund ok (200 = 200). Re-sync emitted nothing. Temporary data removed. Details in ADR 0020.

### Stripe Compatibility Spike — Part 3 (WooCommerce Stripe Gateway)

Status: done

- `tests/matrix/stripe-gateway-spike.sh`: WooCommerce 11.2 + Stripe Gateway 11.0.1 in test mode, classic checkout with `pm_card_visa`, partial refund through WooCommerce. Order transaction id is the charge id; refund id is in `_stripe_refund_id`; gateway metadata includes `order_id` and `site_url` (and customer email/name, which Watchdog never stores).
- Fixed: the Watchdog plugin now sends the refund's `_stripe_refund_id` as `provider_ref` (it sent none, so refunds could never be linked automatically). Plugin tests: floor and latest 44/44; PHP 7.4 lint ok.
- Backend test for an order that references the charge id (as the gateway does). Backend: SQLite 335 + 4 skipped, PostgreSQL 18 339 passed.
- Open: `verified_metadata` matching by `order_id` + `site_url`; one-run full chain (plugin → backend → Stripe).

### Step 67: Verified-Metadata Matching (ADR 0020)

Status: complete

- `payment.snapshot` gains optional `provider_order_ref` and `provider_site_origin` (event schema in `contracts/`, `spec/contracts/` and the backend copy); the Stripe adapter fills them from metadata `order_id` and the origin of `site_url` only.
- Matcher rule 2: when no order has the exact reference, link the order named by `provider_order_ref` if the store domain is confirmed and equals `provider_site_origin` (same mode and currency, exactly one order). Retried when an order arrives after its payment, after domain confirmation and in the nightly sweep; provider refunds of a newly linked payment are tried right away.
- Tests: 4 new end-to-end tests (metadata link only on the confirmed domain, other site / exact reference wins, late order via a real order snapshot, metadata kept without customer data). Backend: SQLite 338 + 4 skipped, PostgreSQL 18 342 passed.
- Real check: the gateway-created Stripe payment linked to order 12 by metadata, its refund by the refund id, reconciliation ok; temporary store removed.

### Step 68: One-Run Stripe Chain (ADR 0020)

Status: complete

- `plugins/woocommerce-watchdog/tests/matrix/stripe-chain-e2e.sh` + `backend-e2e.php` actions `stripe-chain-setup|check|cleanup`: backend, WooCommerce 11.2 + Stripe Gateway 11.0.1, owner's real Stripe test account; checkout, partial refund, plugin delivery, Stripe sync, matching and reconciliation in one run. Result PASS: capture and refund linked by exact reference, `MONEY_CAPTURE_AMOUNT` and `MONEY_REFUND_MISSING` ok.
- Plugin: `PaymentMode` records the Stripe test/live mode once at payment/authorization (`_bw_payment_mode`) and sends it as `mode`; the order snapshot carries `order_key_hash` (SHA-256 of the order key, never the key). Plugin tests: floor and latest 45/45; PHP 7.4 lint ok.
- Backend: the first chain run linked old Stripe payments with the same metadata `order_id` to the new order. Metadata no longer links an order that has its own transaction reference, and requires the order-key hash to match when Stripe metadata has `order_key` (`payment.snapshot.provider_order_key_hash`, `order.snapshot.order_key_hash`; event schema in all three copies). New backend test. SQLite 339 + 4 skipped, PostgreSQL 18 343 passed.
- The mode assumption and the guards are recorded in ADR 0020 for the owner.

### Step 69: Two-Factor Authentication and Ownership Transfer (ADR 0021)

Status: complete

- Backend: TOTP (RFC 6238, own class tested on the RFC reference values) with keyring-encrypted secret, enrollment confirmed by a code, 10 hashed one-time recovery codes, replay protection by the last used step. Login with MFA answers `mfa_required` without a session; `POST /auth/mfa/challenge` (5 minutes, 5 attempts) signs in. Step-up (password + code) for disabling, new recovery codes and ownership transfer. `POST /ownership-transfer`: owner only, target is a member with a confirmed address, owner ↔ admin swap in one transaction, audited. New dependency `bacon/bacon-qr-code` for the QR SVG. Migration: `users.mfa_*` columns, `mfa_recovery_codes`.
- Dashboard: second sign-in step (app or recovery code, return to the password on expiry), "Two-factor authentication" panel in the profile (QR + key, confirmation, recovery codes with copy/download, new codes, turning off; owners without MFA see a recommendation), "Transfer ownership" on the team page with password and code. ru/en/de.
- Tests: 7 backend tests (RFC vectors, enrollment, sign-in with app and recovery codes, expiry, step-up, transfer with and without MFA). Backend: SQLite 346 + 4 skipped, PostgreSQL 18 350 passed. Customer unit tests 75. `make frontend-smoke` 41/41 with three new steps on the real backend (enable MFA with a computed TOTP code, sign in with a recovery code, transfer ownership to the invited operator); 5 of 6 runs green, one run hung on the reconciliation findings request in an older step (not MFA-related, not reproduced in the next three runs).

### PayPal Compatibility Spike — Parts 1–2 (ADR 0022)

Status: done; Transaction Search on accumulated data still to check

- Part 1 (REST API, owner's sandbox): app tokens carry refund/capture scopes even with every optional feature unticked — PayPal credentials cannot be limited to reading. Reads by id work; listing needs Transaction Search (403 until ticked, then lags). `custom_id` links order, capture and refund to the store order. A EUR payment to a USD account: order `COMPLETED`, capture `PENDING`.
- Part 2 (`tests/matrix/paypal-gateway-spike.sh`, WooCommerce 11.2 + PayPal Payments 4.1.3, real browser checkout with the sandbox buyer): transaction id = capture id; authorize-only keeps the authorization id until capture; pending capture leaves the order on-hold; WooCommerce refunds have no PayPal refund id. Results in `docs/compatibility.md`.
- No connector code yet; no write call with the Watchdog app.

### Step 70: PayPal Read-Only Connector (ADR 0022)

Status: complete; decisions await owner review

- Backend: `App\Support\Providers\PayPal\*` — client with a GET allowlist (plus OAuth token and webhook verification), connector with mandatory write-access acknowledgement and Transaction Search probe, order-centric mapper, sync over Transaction Search (lag-aware watermark, 31-day windows, request budget, daily audit with cursor), webhook controller (PayPal-side signature verification, order re-read), `paypal:sync` command and schedule, `POST /integrations/{id}/sync` dispatches per provider. Shared `ProviderEventEmitter` extracted from the Stripe ingestor. Migration: credential kinds `paypal_client`, `paypal_webhook`; refund allocation strategy `unique_amount`.
- Matching and reconciliation: provider-neutral refund link by unique amount inside an exactly matched order; provider coverage per order gateway.
- Plugin: `ppcp-*` gateways supported; mode from `_ppcp_paypal_payment_mode`. Plugin tests floor/latest 46/46, PHP 7.4 lint ok.
- Dashboard: "Payment provider" block with Stripe/PayPal tabs (status on each tab, the connected one opens first); PayPal tab with app setup steps, the write-access warning that must be acknowledged, rights shown after connecting, Transaction Search freshness, webhook id. ru/en/de.
- Tests: 9 backend PayPal tests (acknowledgement, Transaction Search probe, exact capture link + unique-amount refund, late store refund, pending capture and authorization, watermark and ambiguous refunds, request allowlist, webhook verification, per-gateway coverage and rejected credentials, currency conversion). Backend: SQLite 355 + 4 skipped, PostgreSQL 18 359 passed. Customer unit tests 80. `make frontend-smoke` 41/41 in the last three consecutive runs (two earlier runs failed once each on unrelated late steps — German order page, sign out — while other jobs were loading the machine).
- Real check on the owner's sandbox: the real Watchdog app connected through the connector, audit sync found the plugin and API payments via Transaction Search, and orders mapped correctly (captured with refunds, pending EUR, authorization). Store "PayPal Sandbox Shop" left in the local smoke tenant for viewing.

### Step 71: One-Run PayPal Chain (ADR 0022)

Status: complete

- `plugins/woocommerce-watchdog/tests/matrix/paypal-chain-e2e.sh` + `backend-e2e.php` actions `paypal-chain-setup|check|cleanup`: backend with PayPal connected (real Watchdog app, sandbox), WooCommerce 11.2 + PayPal Payments 4.1.3 + Watchdog plugin, real sandbox buyer checkout, partial refund through PayPal, plugin delivery, PayPal sync (Transaction Search delta and the capture → order read a webhook uses), matching and reconciliation in one run. Result PASS: capture `exact_reference:1000`, refund `unique_amount:300`, `MONEY_CAPTURE_AMOUNT` and `MONEY_REFUND_MISSING` ok.
- `paypal-spike-configure.php` takes `BW_PAYPAL_INVOICE_PREFIX` (fresh WordPress reuses order numbers and PayPal refuses duplicate invoice ids).
- Transaction Search lagged ~2 h; the fresh payment came only through the capture → order path. A real signed webhook was not exercised. Proposal recorded in ADR 0022: use the store-reported capture id of PayPal orders as a read pointer to remove the lag without a webhook.

### Step 72: PayPal Capture-Id Pointers (ADR 0022)

Status: complete (owner accepted the proposal from Step 71)

- `PayPalSync::syncPointers()` + `paypal:sync --pointers` every minute: recent (6 h) store orders of `ppcp-*` gateways in the integration's mode whose `transaction_ref` has no PayPal operation yet are read at PayPal by that capture id and mapped from the PayPal order; at most 50 per run, each id at most once per 15 minutes. Config `watchdog.paypal.pointer_*`.
- New backend test (pointer read and link; old, other-mode, other-gateway orders ignored; unknown capture retried only after the interval; only allowlisted GETs). SQLite 356 + 4 skipped, PostgreSQL 18 360 passed.
- The one-run chain (`paypal-chain-e2e.sh latest`) now checks the pointer path instead of a simulated hint: PASS on the owner's sandbox.

### Step 73: Plugin Safety (ADR 0023)

Status: complete

- Owner decisions on the platforms research: Germany/DACH, WooCommerce REST API-first, plugin safety first (ADR 0023).
- Plugin 0.2.0: local queue limit 100 000 with skip-before-revision and backfill recovery below 90 000; heartbeat `outbox_full`/`skipped_events`; emergency stop (settings page, `wp business-watchdog stop|resume`) and `BUSINESS_WATCHDOG_DISABLED` constant before the autoloader; 7-day backlog flag; uninstall removes the new options; README with emergency instructions.
- Backend: a full plugin queue makes the connector `partial` / `local_queue_full`; reason text ru/en/de in the dashboard.
- Release: `bin/build-release.sh` (package without tests, PHP 7.4 lint), `tests/matrix/release-checks.sh` on the shipped ZIP — 27 checks PASS on floor and latest (upgrade/rollback, checkout with the service unreachable, switches, no WooCommerce, offline deactivation and repeated uninstall).
- Tests: plugin integration floor/latest 48/48; backend new freshness test.

### Step 74: Acceptance Evidence Refresh

Status: complete for documentation evidence refresh

Updated:

- `spec/ACCEPTANCE.md`: replaced the original "all not done" package snapshot with live statuses `done` / `partial` / `not_done`, evidence pointers and remaining gaps for ACC-01..ACC-50.
- `spec/PANELS-ACCEPTANCE.md`: replaced the original "all not done" note with live EXT-01..EXT-29 status/evidence, making clear that customer cabinet/MFA/team/i18n are partly implemented while SaaS billing/platform/support-grants remain mostly specification-only.
- `PROJECT_STATE.md`: bumped the recovery marker to Step 74 and noted the acceptance evidence update.

Verification:

- Documentation-only change. No backend/frontend/plugin tests were run.

Coordination:

- WooCommerce REST API spike files currently belong to Claude's parallel work and were not touched.

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

### Step 76: WooCommerce REST API Connector (ADR 0024)

Status: complete (backend); dashboard screen next; assumptions await owner review

- Spike on the matrix (6.0 … 11.2, without the plugin): pretty permalinks required up to 9.9, HTTPS only, `_fields` keeps billing/customer data out, refunds bump the order only from 7.9, trash/deletion behaviour, writes refused for a Read key, a write-free probe that tells a Read key from a Read/Write key.
- Backend `App\Support\Providers\WooCommerce\*`: SSRF-safe client (store's own HTTPS origin, pinned DNS, no redirects, GET allowlist on order routes + the connect-time probe), connector (`POST /stores/{id}/integrations/woocommerce-api`; Read key only; one order source per store — pairing the plugin is refused while the API is the source and vice versa), mapper to the plugin's events (`order.snapshot`, `refund.snapshot`, `order.deleted`), sync (delta every 5 min, recent re-read hourly, 90-day audit daily with deletion check by `include=`), `woocommerce-api:sync [--recent|--audit]`, freshness by last successful sync, rejected key → degraded and no more polling. `ProviderEventEmitter` gives revisioned drafts a per-object revision (`provider_object_states.revision`). Credential kind `woocommerce_api`.
- Tests: 8 backend tests (connect rules, origin/SSRF, mapping without personal data, revisions, late and deleted refunds, audit deletion, rejected key and freshness, pairing refusal, amounts). Backend SQLite 368 + 4 skipped, PostgreSQL 18 372 passed (tree including the parallel export work). Real check: `tests/matrix/rest-api-chain-e2e.sh floor latest` PASS. The matrix WordPress port is published on 127.0.0.1 (random port) so the backend can reach it; `WATCHDOG_DEV_WC_API_ENDPOINT` is honoured only in local/testing.

### Step 77: Owner Decisions on the REST API Connector (ADR 0024)

Status: complete

- Owner accepted: one order source, Read key only, audit for deletions; DNS verification stays for now (proposal: confirm the store by a working Read key on its own origin); late-refund delay on old WooCommerce becomes a warning on the connection screen.
- Payment mode without the plugin: `mode_reported` in the order projection and API; money checks against a test-mode-only provider are `unknown` / `payment_mode_needs_plugin`, and test-mode payments without an order in an API-connected store too. New backend test.
- Fix: the spec copy of the OpenAPI contract (`spec/contracts/openapi.yaml`) missed the REST API connect operation in Step 76 (its copy test failed on that commit); both copies are identical again.

### Step 78: Store Confirmed by a Read Key (ADR 0024)

Status: complete

- Owner decision: a working Read key on the store's own HTTPS origin confirms the store. `StoreVerificationService::confirmByApiKey()` records a verification with method `api_key` (pgsql constraint extended) on connect, activates an onboarding store, writes `store.verified` to the audit log and re-runs exact matching like the other methods; a store already verified for its URL is left alone. `api_key` cannot be requested through `POST /stores/{id}/verify`. OpenAPI (both copies): method enum. Two backend tests; SQLite 373 + 4 skipped, PostgreSQL 18 377 passed.

### Step 79: Order Source Screen (ADR 0024)

Status: complete

- Store page: "Order source" block (`OrderSource.vue`) with tabs "REST API key" (`WooCommerceApiPanel.vue`: steps to create a Read key, access details, requirements, warnings about late refunds and payment mode, store confirmation by the key, connected key ck_••••, read-only, last sync, sync now, key-rejected alert, disconnect with a reminder to delete the key in WooCommerce) and "Plugin" (the former connector panel, plugin integrations only). One source: the other tab explains that the current source must be disconnected first.
- Verification: the plugin method only for a paired plugin; hint that a REST API key confirms the store; method `api_key` named. Coverage: "Order source" with the last sync of an API connection (`coverage.connector.last_sync_at`, backend + OpenAPI). Order page: "test/live mode: plugin needed" when the store did not report the mode. Incident facts for `api_key_rejected`, `api_sync_failing`, `api_not_synced_yet`. Error texts for every REST API connector code (ru/en/de, checked by a test).
- Tests: customer unit tests 87 (new `orderSource.test.ts`); `make frontend-check` green; `make frontend-smoke` 41/41 in the last three runs (smoke checks the plugin tab of a paired store and the recommended key tab of a new store). Backend SQLite 373 + 4 skipped, PostgreSQL 18 377.

### Step 80: Shopware 6 Spike, Part 1 (ADR 0025)

Status: complete; decisions await the owner

- `plugins/shopware-app/tests/spike/run.sh` on dockware/play Shopware 6.7.2.2: read-only ACL role + Integration, orders through the Store API, admin changes. Findings: criteria search and GET work with the read role; `includes` keeps personal data out (without it `orderCustomer` comes back despite no customer privilege); money as floats with currency rounding decimals; new orders have no `updatedAt` and payment state changes touch only the transaction, so the delta needs an OR over order created/updated and transaction updated; deleted orders vanish (404).
- Security finding: the order/transaction/delivery state transition endpoints have no ACL in 6.7.2.2 — a read-only key changed an order and a payment status (money-moving refunds stay protected). A read-only key cannot be verified without writing or reading all integrations' secrets. Proposal in ADR 0025: Integration first with a PayPal-style warning, App (manifest permissions, webhooks) next; responsible disclosure to Shopware is the owner's call.

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

### Step 81: Shopware Decisions and Spike Part 2 (ADR 0025)

Status: complete

- Owner accepted: Integration first with a PayPal-style warning, App later, read-only not verifiable for an Integration, responsible disclosure to Shopware (the owner sends the report), supported versions 6.6 and 6.7.
- Spike part 1 repeated on Shopware 6.6.10.6: identical results. The generic state-machine route checks the update privilege while the order/transaction/delivery routes do not (delivery shipped with the read-only key) — added to `verify_acl.py`.
- SwagPayPal 10.8.5 source: capture id, PayPal order id and sandbox flag in the order transaction's custom fields; refunds leave only the transaction state (no amount, no id) in Shopware — reconciliation model to be decided with the connector.

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

### Step 85: Shopware Integration Connector (ADR 0026)

Status: complete (backend); dashboard screen next

- Owner decision on refunds kept only as a payment state: `full` must equal the provider's refunds of the captured amount; `partial` shows the provider's refunded amount and is `unknown` unless the provider refunded nothing or everything; provider refunds with store state `none` are extra refunds. New optional `order.snapshot.refund_state` (event schema, all copies) and a state-based branch in the refund rules.
- `App\Support\Providers\Shopware\*`: client (allowlist: OAuth token, order search, order search-ids; never state transitions), connector (`POST /stores/{id}/integrations/shopware`, risk acknowledgement required, key confirms the store, one order source), mapper (float amounts only when exact, effective transaction, SwagPayPal capture id and sandbox flag, `swag_paypal_*` → PayPal coverage, refund state), sync (OR delta over created/updated/transaction updated, daily audit with deletion check), `shopware:sync [--audit]`. Shared `StoreOriginHttp` (SSRF-safe store origin requests, now also used by the WooCommerce client) and `OrderSources` (plugin, WooCommerce key or Shopware key — one per store). Stores can be created with `platform` `woocommerce` or `shopware`. PayPal pointers also follow `swag_paypal_*` orders.
- Tests: 5 backend Shopware tests (connect rules and allowlist, mapping without personal data, audit deletion, six refund-state cases against PayPal provider data, float conversion). SQLite 380 + 4 skipped, PostgreSQL 18 384. Real chain `plugins/shopware-app/tests/chain/chain-e2e.sh` on Shopware 6.6.10.6 and 6.7.2.2: PASS.

### Step 86: Shopware Connection Screen (ADR 0025/0026)

Status: complete

- Store creation asks for the platform (WooCommerce or Shopware 6). A Shopware store's "Order source" block shows only `ShopwarePanel.vue`: steps for a role with Orders → View and an Integration without administrator rights, requirements, what is read and not read, the state-change warning that must be acknowledged before connecting, the refund note (partial refund amount from the provider), connected key SWIA••••, last sync, sync now, key-rejected alert, disconnect with a reminder to delete the integration. Verification hint and the `api_key` method name cover both key types. Error texts for every Shopware connector code (ru/en/de, checked by a test).
- Tests: customer unit tests 90; `make frontend-check` green; `make frontend-smoke` 42/42 twice in a row (new step: a Shopware store gets the Shopware panel, connecting waits for the acknowledgement).

### Step 87: Export Download Pending Notice

Status: complete

Updated:

- Customer frontend export helper now returns whether a download URL was actually opened.
- Reconciliation and incident export actions show the existing quiet loading status when an export response has no `download_url` yet, instead of silently doing nothing.

Verification:

- Customer frontend: `npm run check -w @bw/customer` from `apps/frontend` — 87 tests passed and production build completed.

Coordination:

- Claude's Shopware Step 85 files were left unstaged; this step only touched customer frontend export helper/views and this progress entry.

### Step 88: Telegram Notification Binding

Status: complete (first backend/frontend slice)

Updated:

- Backend accepts `kind=telegram` notification channels as pending channels with a one-time 6-digit binding code, encrypted pending destination, masked destination output and normal tenant/idempotency/audit handling.
- Added secret-protected `POST /api/v1/webhooks/telegram/bind` for the Telegram bot gateway: the code must come from the chat that will receive alerts, then the channel is verified/enabled and the encrypted destination becomes the Telegram chat id.
- Added `TelegramNotificationSender` to the notification sender registry: it uses the configured Bot API token, maps Telegram 429/5xx/timeouts/permanent rejects into existing retry/dead-letter semantics, and keeps notification content PII-free through the existing renderer.
- Customer frontend notifications screen now offers Email/Telegram recipient creation in the existing compact form style, shows the one-time Telegram code, supports reissuing an unverified Telegram code, hides email address changes for Telegram channels and keeps ru/en/de labels/error explanations in sync.
- `contracts/openapi.yaml` and `spec/contracts/openapi.yaml` now document Telegram channel creation and the bot bind webhook; `contracts/ui-api-catalog.md`, `PROJECT_STATE.md` and `spec/ACCEPTANCE.md` were updated as evidence.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications/NotificationChannelApiTest.php tests/Feature/Contracts/OpenApiRoutesTest.php` — 16 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications` — 29 passed.
- Customer frontend: `npm run check -w @bw/customer` from `apps/frontend` — 90 tests passed and production build completed.

Remaining gaps:

- Real Telegram bot deployment/webhook registration and a live Telegram send were not tested in this slice.

### Step 89: Telegram Binding Code Hardening

Status: complete

Updated:

- Telegram binding now looks up only active, unconsumed Telegram verification rows by code hash instead of scanning unrelated verification rows.
- New Telegram binding codes are checked against active global Telegram bindings before issue; if a legacy/manual collision is still present, binding returns `telegram_binding_code_collision` and leaves all channels unverified instead of picking one by timestamp.
- The new rejection code is localized in ru/en/de for the customer app.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications` — 30 passed.
- Customer frontend: `npm run check -w @bw/customer` from `apps/frontend` — 90 tests passed and production build completed.

Coordination:

- Claude's Shopware test files were left unstaged; this step only stages notification hardening and evidence files.

### Step 90: Native Telegram Update Webhook

Status: complete

Updated:

- Added `POST /api/v1/webhooks/telegram/updates` for native Telegram Bot API webhook updates. It accepts `message` and `channel_post`, checks the configured secret against `X-Telegram-Bot-Api-Secret-Token` (and keeps the internal header for controlled tests/tools), extracts a 6-digit binding code from text, and binds the sending chat directly.
- Updates without a binding code or chat id return `202 {"status":"ignored"}` and do not create side effects.
- `contracts/openapi.yaml` and `spec/contracts/openapi.yaml` document the native update endpoint; project state, acceptance and UI catalog evidence were bumped.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications/NotificationChannelApiTest.php tests/Feature/Contracts/OpenApiRoutesTest.php` — 19 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications` — 32 passed.

Remaining gaps:

- Real Telegram bot webhook registration and live update delivery were not tested in this slice.

Coordination:

- Claude's Shopware test files were left unstaged; this step only stages Telegram webhook and evidence files.

### Step 91: Telegram Sender Failure Semantics

Status: complete

Updated:

- Added direct unit coverage for `TelegramNotificationSender` so its Bot API outcome mapping is explicit and protected: success returns provider message id, 429 uses Telegram `retry_after`, 5xx is retryable, 4xx is permanent, missing bot token / invalid destination are permanent configuration failures, and transport exceptions become `uncertain`.
- Acceptance/project/catalog evidence now points to Telegram sender success/failure tests in addition to binding/webhook tests.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Unit/Notifications/TelegramNotificationSenderTest.php` — 5 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Unit/Notifications tests/Feature/Notifications` — 42 passed.

Coordination:

- Claude's Shopware files were left unstaged; this step only stages Telegram sender test and evidence files.

### Step 92: Telegram Webhook Registration Readiness

Status: complete

Updated:

- Added `telegram:webhook set|info|delete` for production operations. `set` registers `/api/v1/webhooks/telegram/updates` through Telegram Bot API with `secret_token`, `allowed_updates=message,channel_post`, optional pending-update drop and HTTPS URL validation; `info`/`delete` support smoke checks and rollback.
- Added `TELEGRAM_WEBHOOK_URL` config alongside the existing bot token, username and webhook secret.
- Acceptance/project/catalog evidence now distinguishes automated webhook registration readiness from the remaining live Telegram delivery smoke.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan list telegram` — command registered.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications/TelegramWebhookCommandTest.php` — 4 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Unit/Notifications tests/Feature/Notifications` — 46 passed.

Remaining gaps:

- No real Telegram token was used in this slice; live send/update delivery must be checked against the production bot after deployment.

Coordination:

- Claude's Shopware files were left unstaged; this step only stages Telegram webhook registration readiness and evidence files.

### Step 87: Real PayPal Payments through SwagPayPal (ADR 0025 part 3)

Status: complete

- `plugins/shopware-app/tests/paypal/` (`configure.py`, `order.py`, `approve.mjs`, `refund.py`, `inspect.py`, `pay.sh`, `chain.sh`): SwagPayPal configured with the owner's sandbox shop app, real buyer payments approved in Chromium, refunds through the plugin's admin route, then the service connects the Shopware integration key and the Watchdog PayPal app and reconciles. Screenshots and order ids stay in a git-ignored `.out/`.
- Confirmed on Shopware 6.7.2.2 (SwagPayPal 10.8.5) and 6.6.10.6 (SwagPayPal 9.13.4): capture id and sandbox flag in the transaction custom fields; EUR to an account without EUR balance stays pending/in progress; refunds leave only `refunded_partially`/`refunded`. Chain PASS on both: exact capture links, partial refund unknown with the provider's 5.00 shown, full refund reconciled, no refund without findings.

### Step 93: Shopware App (ADR 0027)

Status: complete

- Backend `ShopwareApp` + `ShopwareAppController`: per-store app archive (risk acknowledgement required, read-only manifest permissions, registration URL and secret per integration), registration and confirmation handshake, key checked once the app is active, signed webhooks (`checkout.order.placed`, `order.written`, `state_enter.*` for payment and order states, app lifecycle) reading the named orders right away through `ShopwareSync::syncChanged()`; Admin API at the shop URL Shopware reports. Credential kind `shopware_app`. OpenAPI: four operations.
- Dashboard: the Shopware panel offers the app (recommended, download after the acknowledgement, waiting state that refreshes itself) or the integration key; the connected view shows the connection type and the last webhook. `@bw/api-client` gained `requestFile` for file responses.
- Tests: 5 backend app tests; SQLite 394 + 4 skipped, PostgreSQL 18 402 (tree with the parallel Telegram work). Customer unit tests 91. Real `plugins/shopware-app/tests/app/app-e2e.sh` on Shopware 6.6.10.6 and 6.7.2.2: PASS. `make frontend-smoke`: the Shopware step passes; the run stops later at the e-mail recipient step because Step 88 (parallel session) renamed "Добавить адрес" to "Добавить получателя" without updating the smoke test.

### Step 94: Telegram Live Smoke and Handle Display

Status: complete

Updated:

- Fixed Telegram bot handle rendering in the customer notification binding hint: `TELEGRAM_BOT_USERNAME` may be configured with or without `@`, but the UI now displays exactly one leading `@`.
- Completed live Telegram smoke with the real bot through a Cloudflare quick tunnel: `telegram:webhook set` registered the native update endpoint, `telegram:webhook info` confirmed the URL with zero pending updates, a real Telegram message with the binding code reached the backend, the channel became verified, and a backend-triggered test notification was delivered to the Telegram chat.
- Acceptance/project/catalog evidence now marks ACC-38 as done, with the remaining deployment note limited to replacing the quick tunnel with a stable production domain/tunnel.

Verification:

- Customer frontend: `npm run check -w @bw/customer` — 92 tests passed and production build completed.
- Live Telegram: Cloudflare `/up` returned 200; unauthenticated webhook update returned 401; secret-authenticated update returned 202; real bot binding update verified `Telegram chat ***3272`; test delivery `01a12aea-a2e8-71d3-81e2-fd89a15d2f13` reached `sent` and the message was received in Telegram.

Coordination:

- Claude's Shopware script files were left unstaged; this step only stages Telegram UI/evidence files.

### Step 94: Newest Shopware Releases (ADR 0025/0027)

Status: complete

- `plugins/shopware-app/tests/build-image.sh` builds exact Shopware releases from the dockware base through composer and `system:update:finish`; `wait-ready.sh` waits until the admin token works (a fresh container may restart its web server after answering 401). `paypal/run.sh` installs the matching SwagPayPal line (9.x for 6.6, 10.x for 6.7) and runs the money chain.
- 6.7.15.1: key chain, SwagPayPal chain and app — PASS. 6.6.10.29: key chain and SwagPayPal chain — PASS; app installation fails inside Shopware 6.6.10.25–29 (empty DI argument for the app-system TrustedUrlResolver), affecting every app with a registration URL; documented, the key remains the path there.
- Stripe for Shopware: no open-source Shopware 6 Stripe plugin exists (packagist/GitHub); the common "Stripe Payment" plugin (Pickware) is downloaded only through a Shopware account — needs the owner.

### Step 95: Notification Reminders

Status: complete

Updated:

- Added opt-in open-incident reminders for notification channels: `reminder_hours` is validated and normalized per channel, `incident_reminder` deliveries are deduped per incident/channel/window, and reminders are only planned after a previously sent open/reopen/reminder delivery.
- Added `notifications:remind` plus hourly scheduler wiring. The planner respects tenant/store scope, enabled+verified channels, severity/store filters, active suppressions and quiet hours.
- Customer notifications UI now exposes reminders inside the existing compact preferences form and shows the reminder interval in the channel summary. RU/EN/DE translations and OpenAPI contracts were updated.
- Acceptance/catalog/project evidence for ACC-39 now records reminders as implemented; daily digest remains the notification gap.

Verification:

- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Notifications tests/Unit/Notifications` — 49 passed.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan list notifications` — `notifications:remind` registered.
- Backend: `/opt/homebrew/opt/php@8.4/bin/php artisan test` — 401 passed, 4 skipped.
- Customer frontend: `npm run check -w @bw/customer` — 93 tests passed and production build completed.

Coordination:

- Claude's Shopware script files were left unstaged; this step only stages notification reminder and evidence files.

### Fix: PayPal webhook URL empty on the store page

- The webhook URL was only in the connect and sync responses; the store page loads the integration list, which lacked it, and the PayPal panel had no fallback, so the field next to "Copy" stayed empty after a reload. `IntegrationDto` now returns `webhook_url` for Stripe and PayPal (null for other connectors; OpenAPI `Integration` schema, both copies), and both panels fall back to building it in the browser. Regression tests: the list carries the URL (backend), the PayPal panel shows it when the field is missing (frontend). The old frontend fixture always contained `webhook_url`, which hid the bug.

### Step 96: Readiness Summary

Status: complete

- `docs/readiness.md`: readiness by TZ area (26 areas), what separates the project from a commercial P1 (subscription/tariffs, public site, owner and admin panels, operations, checkout layer 2, §43), current test results and pending owner decisions.
- Owner decisions: Mollie and Shopify are P2; Stripe in Shopware is unsupported until a pilot customer with the Pickware plugin (ADR 0025); the Shopware panel states it (ru/en/de).
- Current smoke is red at the notifications "test email" step after Step 95 (parallel notifications work); recorded in the readiness table.

### Step 97: Plans and Entitlements

Status: complete (plan contents and prices await the owner)

- ADR 0029. Migration `2026_10_11_180000_create_billing_plans_and_entitlements`: `billing_plans`, `billing_plan_versions`, `billing_prices` (`price_kind` base / extra_store), `subscriptions` (plan snapshot, `extra_store_quantity`, trial and past-due dates), `entitlement_overrides`; PostgreSQL CHECK constraints.
- `FeatureCatalog` (7 features, 6 limits, validation of entitlement JSON), `Entitlements` (access state, reasons, `allows`, `limit`), `EntitlementCalculator` (priority: deleting/deleted → no service; suspended → paused manual; paid period; override on top of the paid plan; trial; past-due grace 7 days; paused by billing / onboarding).
- Owner requirement: the price depends on the number of stores. `withStores()` adds bought stores to `max_active_stores` up to `max_extra_stores` and multiplies per-store limits (`monthly_event_budget`, `manual_runs_per_day`); the check interval floor applies to every store.
- Enforcement points: check scheduling and manual runs (`checkout_monitoring`, interval floor; API 403 `feature_not_in_plan`), payment attempt windows, money reconciliation (`unknown` / `not_entitled`), unmatched payments scan, Telegram channels (403), store creation (403 `plan_store_limit_reached`).
- `WATCHDOG_BILLING_ENFORCEMENT`: `pilot` (default, today's behaviour, everything allowed) or `enforce`.
- `GET /api/v1/entitlements` (OpenAPI, both copies). `billing:seed-placeholder-plans` creates draft Free / Start / Pro marked as placeholders.

Verification:

- `tests/Feature/Billing/EntitlementsTest.php` — 14 tests.
- Backend SQLite: 415 passed, 4 skipped. PostgreSQL 18: 419 of 419.

### Step 98: Paddle Billing Adapter

Status: complete in Paddle sandbox (owner decisions 6–8 of ADR 0030 pending)

- ADR 0030. Migration `2026_10_11_190000_create_billing_provider_tables`: `billing_customers` (provider ref only, no profile), `billing_subscription_contracts`, `billing_checkout_sessions`, `billing_event_inbox`, `billing_sync_cursors`; `billing_prices.provider_product_ref`.
- `App\Support\Billing\Paddle`: `PaddleClient` (pinned `Paddle-Version: 1`), `PaddleCatalogSync`, `PaddleCheckout` (local session first, provider call outside the DB transaction, own idempotency by tenant + key and request hash, supersedes pending sessions and cancels their transactions, one live contract per tenant), `PaddleSignature`, `PaddleEventRecorder` (sanitized inbox, duplicate/conflict by event id + payload hash), `PaddleEventPuller` (event log in id order, cursor two minutes behind), `PaddleEventProcessor` (lease, backoff, dead letter, quarantine), `PaddleSubscriptionSync` (current subscription from Paddle, link only via a server-created `origin: api` transaction of our checkout, freshness by `updated_at`, unknown prices -> `unknown` contract without access change). Provider-neutral `SubscriptionProjector` writes the tenant's `subscriptions` row.
- API: `POST /api/v1/billing/checkout`, `GET /api/v1/billing/checkouts/{id}`, `GET /api/v1/billing/subscription` (owner only, `TenantRoles::billingManage`), `POST /api/v1/webhooks/paddle`. OpenAPI (both copies).
- Commands: `billing:paddle-catalog`, `billing:paddle-events` (every minute), `billing:process-events` (every 30 s); `billing:seed-placeholder-plans --test-prices`.
- Real Paddle sandbox: catalog created (Start/Pro base and extra-store prices), checkout through the service, payment with Paddle's test card through Paddle.js in Playwright, events pulled from the event log, subscription projected (`start`, active, 2 extra stores -> 3 stores), checkout confirmed, scheduled cancellation projected as `cancel_at_period_end`. Spike transactions created by hand were quarantined as not linked. A real webhook delivery was not tested (needs a public URL).
- Found on PostgreSQL with real data: non-UUID custom data crashed the session lookup — now validated, and any unexpected processing error retries and then dead-letters instead of blocking the inbox.

Verification:

- `tests/Feature/Billing/PaddleBillingTest.php` — 14 tests (HTTP faked).
- Backend SQLite: 429 passed, 4 skipped. PostgreSQL 18: 433 of 433.

### Step 99: Owner Decisions on ADR 0030 and Our Own Trial

Status: complete

- Owner: prices stay gross for consumers for now (to be checked with a tax adviser); trial is our own without a payment method; policy acceptance waits for the legal pages.
- Migration `2026_10_11_200000_add_trial_consumed_to_tenants`. `TrialService` + `POST /api/v1/billing/trial` (owner, verified email, `idempotency` middleware): once per tenant under the tenant lock, `provider = internal`, `trial_ends_at` from the plan version's `trial_days`; drafts only in the Paddle sandbox. A paid contract replaces the trial row through `SubscriptionProjector`. OpenAPI (both copies).

Verification:

- `tests/Feature/Billing/TrialTest.php` — 3 tests.

- Backend SQLite: 432 passed, 4 skipped. PostgreSQL 18: 436 of 436.

### Step 100: One Trial per Store Domain

Status: complete

- ADR 0031 (owner 2026-10-12: organisation and store domain are checked together; `www.` and the bare host are one domain).
- Migration `2026_10_12_100000_create_trial_store_hosts`: `trial_store_hosts` (unique host → tenant, store, claimed_at), kept after the store or the trial is gone.
- `TrialStoreHosts`: host normalization (lower-case, trailing dot, IDN punycode, one leading `www.`; port/path ignored, subdomains distinct), cross-tenant "claimed by another organisation" lookup, claims.
- `TrialService::start`: besides `tenants.trial_consumed_at`, rejects 409 `trial_store_already_used` when a verified live store of the tenant has a domain claimed by another tenant; claims the domains of its verified stores.
- `StoreVerificationService::markVerified`: while the tenant is on our own trial, a store whose domain is claimed elsewhere fails verification with `trial_store_already_used`; otherwise a verified store of a tenant that consumed its trial claims its domain. Only verified stores claim, so a domain cannot be blocked by typing someone else's address.
- Customer dashboard: the verification panel explains `trial_store_already_used` (ru/en/de). Also added the missing error texts for `feature_not_in_plan` and `plan_store_limit_reached` (Step 97 codes; the notification contract test had been red since then). OpenAPI trial description updated (both copies).

Verification:

- `tests/Feature/Billing/TrialTest.php` — 7 tests (4 new: domain normalization, one trial per verified domain across organisations with subdomains distinct, unverified store does not block but cannot be verified during the trial and can after a purchase, store verified during the trial claims its domain).
- Backend SQLite: 436 passed, 4 skipped. PostgreSQL 18: 440 of 440.
- Customer frontend `npm run check`: 93 tests passed, build completed.

### Step 101: Usage Counter

Status: complete (event budget enforcement awaits the owner, ADR 0032 p. 6)

- ADR 0032. Migration `2026_10_12_110000_create_usage_tables`: `usage_buckets`, `usage_entries` (reference schema; PostgreSQL CHECKs).
- `UsageMeter`: idempotent `consume` by operation key under the locked period bucket, limit check before recording, `current`; day and month periods in the tenant's timezone stored as UTC bounds.
- Manual check runs: `CheckScheduler::runNow` consumes `manual_runs_per_day` last (after eligibility, per-minute and active-run checks); `UsageLimitResponse` returns 429 `usage_limit_reached` with limit/value/used/resets_at and Retry-After.
- `EventIngestor`: accepted non-synthetic events from store-reported integrations are counted into `monthly_event_budget` once per ingest call (duplicates, quarantined, synthetic and provider events are not); no limit yet.
- `GET /api/v1/entitlements` returns `usage`. OpenAPI (both copies): `Entitlements.usage`, `UsagePeriod`, manual run 429 description. Customer error text `usage_limit_reached` (ru/en/de).

Verification:

- `tests/Feature/Billing/UsageTest.php` — 3 tests (daily limit with Retry-After and reset at the tenant's midnight, refused attempts not counted; ledger idempotency and pilot counting; store events per month across a DST change, duplicates/quarantined/synthetic/provider events excluded, API usage).
- Backend SQLite: 439 passed, 4 skipped. PostgreSQL 18: 443 of 443. Customer `npm run check`: 93 passed, build completed.

### Step 102: Subscription Page

Status: complete

- Owner decisions on ADR 0032: the event budget is a soft limit (events are never refused; warning above 80 % and above 100 % with an upgrade offer); manual runs are not returned to the counter.
- ADR 0033. Backend: `BillingCatalog` (sellable states and prices shared by `PaddleCheckout`, `TrialService` and the catalog), `GET /api/v1/billing/plans` for any member (latest sellable version per plan, prices as minor-unit strings, `mode`, `checkout_available`, `trial_available`). OpenAPI (both copies): path and `BillingPlan` schema.
- Customer dashboard `/app/settings/billing` ("Подписка" tab): access state, subscription details for the owner (plan, payment state, trial end, renewal or end date, grace, extra stores, scheduled cancellation), limits and usage with the soft event-budget warning, plan cards (price, extra-store price, limits, features, draft marker, current plan), trial button, purchase with extra stores through Paddle.js; "confirmed" only after the backend returns a paid provider subscription. Members see the plan without actions. ru/en/de texts, billing error texts.
- `tests/e2e/run-billing-live.sh` + `billing-live.mjs`: live check against the Paddle sandbox (fresh verified owner, Start + 1 extra store = 28,00 €, Paddle's test card, confirmation by the backend, subscription shown as paid).

Verification:

- Backend: `tests/Feature/Billing/BillingPlansTest.php` (new). SQLite 440 passed, 4 skipped; PostgreSQL 18 444 of 444.
- Customer: `tests/billing.test.ts` 5 tests; `npm run check` 98 passed, build completed; `make frontend-smoke` 42/42.
- Live Paddle sandbox: `run-billing-live.sh` PASS (first run found that the checkout is confirmed by the transaction event a moment before the subscription is projected; the page now waits for the subscription).

### Step 103: Control Panel Sign-in for Platform Staff

Status: complete

- Owner decision (2026-10-12): everything sold — packages, prices, billing periods (any "every N days/weeks/months/years"), limits, trial days, discounts — is managed in the owner's panel. Order: sign-in (this step), plans and prices, discounts.
- ADR 0034. Migration `2026_10_12_120000_create_platform_staff_tables` (`platform_staff`, `platform_audit_log`, PostgreSQL CHECKs). `PlatformStaff`, `PlatformAudit`, `PlatformCapabilities`, `PlatformSession`.
- `/platform-api/v1` (`routes/platform.php`, middleware group `platform`): own session cookie and CSRF cookie (`UsePlatformSession` restores the session config after the request, `ValidatePlatformCsrfToken`), guard `platform`, `RequirePlatformStaff` (active staff, authenticator on, idle/absolute/permission-version limits, capabilities). Endpoints: `csrf-cookie`, `auth/login`, `auth/mfa`, `me`, `auth/logout`; rate limiters `platform-login`, `platform-mfa`. OpenAPI (both copies) with `StaffSession`; the route contract test now covers `/platform-api`.
- `php artisan platform:create-owner <email>`: creates or attaches the account, hidden password, enrollment of the authenticator on the server, recovery codes once, system audit entry.
- `@bw/api-client` `configureCsrf()`; control app: two-step sign-in, `/owner` layout with payment mode, staff and role, section placeholders, redirect back after sign-in, expired-session handling; ru/en/de.
- Found while testing: an unauthenticated `/me` call between password and code ended the session and dropped the pending sign-in — the session is now ended only when a signed-in user is no longer valid.

Verification:

- `tests/Feature/Platform/PlatformAuthTest.php` — 7 tests (two-step sign-in and audit, customer session/account refused, own cookies, no authenticator / disabled, idle 30 min / 8 h / permission change, rate limit, bootstrap command with enrollment).
- Backend SQLite: 447 passed, 4 skipped; PostgreSQL 18: 451 of 451. Control `npm run check`: 4 passed, build completed; customer: 98 passed.
- `apps/frontend/control/tests/e2e/run-login.sh` (real backend + Vite + Chromium, real TOTP): redirect to sign-in, wrong password refused, password + code open `/owner/plans`, only `bw-platform-session` and `BW-PLATFORM-XSRF` cookies, sign-out ends the session — PASS.

### Release README and Authenticator Re-encryption

- `README.md` rewritten as the release and operations guide: environment variables, migrations, scheduler, first platform owner (`platform:create-owner`), browser worker token, Telegram webhook, Paddle setup, mail check, plugin ZIP and release checks, frontend builds, maintenance commands, local tests and live checks. `CLAUDE.md`: every new command, variable or manual step goes into the README right away (owner request 2026-10-12).
- Found while writing it: `security:reencrypt-secrets` did not re-encrypt users' authenticator secrets, so removing an old key after rotation would have locked out everyone with two-factor sign-in, including platform staff. The command now also re-encrypts `users.mfa_secret_ciphertext`; test `test_reencrypt_command_moves_authenticator_secrets_so_codes_keep_working`.

### Step 104: Plans, Prices and Billing Periods in the Owner Panel

Status: complete (discounts are the next step)

- ADR 0035. Migration `2026_10_12_130000_add_billing_interval_count`: prices billed every 1–365 days/weeks/months/years (`billing_interval_count`, unique key includes the period); trial 0–365 days.
- `PlanAdmin` + `PlanController` (`/platform-api/v1/plans`, `plans/{id}/versions`, `plan-versions/{id}` PATCH, `…/prices`, `plan-prices/{id}` DELETE, `…/publish`, `…/retire`): create (immutable code), edit drafts (ru/en/de texts, trial, features, limits with minimums), prices (Paddle currencies, ISO exponent, minor-unit strings, any period), new version from the latest, publish with reason + authenticator code (Paddle product per plan shared by versions, prices with the billing cycle created before the local switch; previous version retired for new buyers, subscribers untouched), retire. Capabilities `plans.read` / `plans.manage`; every change audited.
- Customers see only published versions and prices in every environment; the sandbox seeder publishes placeholder plans and their test prices (also prices left as drafts by older runs — found by the live purchase rerun). `PaddleCheckout` matches the extra-store price by period too. Customer catalog DTO: `interval_count`, `description`; the subscription page prints any period ("каждые 2 недели", "каждый 21 день").
- Control app: "Тарифы и скидки" list and version editor (content, features, limits with "unlimited", prices with amount parsing, period preview, publish/retire confirmation with code). `formatMinor` moved to `@bw/ui` together with the new string-only `parseMinor`.
- README: plan management replaces the catalog command in the release steps; `run-live.sh login|plans`.

Verification:

- `tests/Feature/Platform/PlanAdminTest.php` — 4 tests (draft → prices for any period → step-up → Paddle billing cycle → customer catalog; new version retires the old one without moving subscribers; Paddle failure keeps the draft; roles). Billing tests moved to published fixtures; seeder test.
- Backend SQLite: 457 passed, 4 skipped; PostgreSQL 18: 461 of 461. Control: 9 tests; customer: 99 tests; builds pass.
- Live: `apps/frontend/control/tests/e2e/run-live.sh plans` — PASS in the Paddle sandbox (plan created in the browser, 45,50 € every 2 weeks + 5,00 € per extra store, wrong code refused, published, retired); Paddle API confirms the price `billing_cycle {week, 2}`. `run-billing-live.sh` — PASS after the change. `make frontend-smoke`: 42/42 on the third run; the first two runs failed at different steps of the owner's two-factor section (log-out click, ownership transfer) without backend errors — a flaky part of the smoke scenario, not touched by this step.

### Step 105: Discounts in the Owner Panel

Status: complete

- ADR 0036. Migration `2026_10_12_140000_create_billing_discounts`: `billing_discounts` (percentage in basis points or flat amount with currency, audience code/tenant/automatic, duration once/periods/forever, usage limit, start/end, plans, Paddle id, state; PostgreSQL CHECKs), `billing_discount_tenants`, `billing_checkout_sessions.discount_id`.
- Paddle API verified in the sandbox before coding (discount fields, archiving, a server-created transaction with `discount_id`: 19.00 € − 20 % = 15.20 €).
- `DiscountAdmin` (create in Paddle with `enabled_for_checkout: false`, then locally; change name/text/plans/organisations/dates/limit; archive), `DiscountResolver` (code > organisation's own > best public offer by saving; reasons `discount_code_invalid`, `discount_expired`, `discount_not_applicable`, `discount_used_up`; uses from confirmed checkouts). `PaddleCheckout` takes an optional code, stores the discount and sends `discount_id`.
- API: platform `GET/POST /discounts`, `PATCH /discounts/{id}`, `POST /discounts/{id}/archive` (reason + `confirmation_code`), `GET /tenants/lookup`; customer `GET /billing/discounts/check`, `discount_code` on checkout, `discount` in the catalog and the checkout. OpenAPI (both copies).
- Control app: "Скидки" tab with list (value, plans, organisations, uses, dates, state), creation form (audience, organisation search, percent or amount parsed without floats, duration, limit, dates, plans) and change/archive with confirmation. Customer: promo code field, discount on plan cards, crossed-out and discounted first payment, code sent with the purchase.
- Found on PostgreSQL only: the organisation lookup compared the uuid id with free text — the id is compared only for UUID queries now.

Verification:

- `tests/Feature/Platform/DiscountTest.php` — 4 tests (creation with Paddle terms, step-up, duplicates and validation, change, archive, audit; promo code applicability, expiry and usage limit; organisation discount beats public offers, best saving, start/end, archived; lookup). Control 10 tests, customer 102 tests.
- Backend SQLite: 461 passed, 4 skipped; PostgreSQL 18: 465 of 465 (twice).
- Live in the Paddle sandbox: `run-live.sh discounts` created promo code E2EMV4WAY65 (20 %, 2 periods, Start only, 5 uses) in the browser; `BW_PROMO=E2EMV4WAY65 run-billing-live.sh` applied it (28.00 € → 22.40 €) and paid with the test card — PASS. Paddle: transaction total 22.40 €, discount on it, `times_used` 1, the subscription carries the discount until two monthly periods end.

### Step 106: Stacked Discounts and Per-Period Discount Ledger

Status: complete

- Owner decision 2026-10-12: discounts stack — offer, then the organisation's own discount, then the promo code, each on the amount left by the previous one; each keeps its own duration; before every renewal the server recomputes the next period's combined discount and sets it at Paddle; every period is recorded in a readable form. ADR 0036 updated.
- Sandbox checks before coding: a flat discount lowers a tax-inclusive total by exactly its amount (28.00 € − 5.60 € = 22.40 € for a German buyer); a subscription refuses a one-time discount but accepts `recur: true, maximum_recurring_intervals: 1` with `effective_from: next_billing_period` (next payment 25.00 €, the one after back to 28.00 €).
- Migration `2026_10_12_150000_create_discount_stacking_tables`: `billing_discount_grants` (discounts a purchase got, periods total/used), `billing_discount_applications` (per period: before, saving, after, Paddle discount, status) and `billing_discount_application_lines` (per discount in order); `billing_checkout_sessions.discount_id` removed.
- `DiscountResolver::stack/calculate` (best offer + best personal + code, sequential, half up), `DiscountLedger` (records the purchase, creates the purchase's one-time combined Paddle discount, links grants to the contract when the subscription is linked, `planRenewals` creates the next period's one-period discount and patches the subscription, retries failures, never duplicates a period), command `billing:plan-discounts` every 15 minutes. Checkout returns `discounts` (breakdown); the catalog lists `discounts`; platform `GET /discount-applications`.
- Customer page: every discount line with its saving in order and the total after discounts. Control: "История скидок" tab.

Verification:

- `DiscountTest` — 5 tests (promo code breakdown and Paddle combined discount; offer → personal → code 2800 → 2300 → 1955 → 1655; renewal planning without the one-time code, no duplicate, Paddle failure then retry, history endpoint). Control 11, customer 102.
- Backend SQLite: 462 passed, 4 skipped; PostgreSQL 18: 466 of 466.
- Live in the Paddle sandbox: offer 10 % forever + promo code 20 % for 2 periods created in the panel; purchase 28.00 € → 25.20 € → 20.16 € paid (Paddle total 2016); `billing:plan-discounts` set the second period's combined discount, Paddle's next transaction 20.16 €, the second run planned nothing; ledger rows for periods 1 and 2 match.

### Step 107: Outgoing Mail Authentication (DKIM, SPF, DMARC)

Status: complete

- `MAIL_RETURN_PATH` (Laravel's global `mail.return_path`) sets the bounce address on every message.
- `DkimMailSigner` signs every outgoing message on `MessageSending` (relaxed/relaxed, SHA-256, `Return-Path` not signed) when `MAIL_DKIM_ENABLED=true` with `MAIL_DKIM_DOMAIN`, `MAIL_DKIM_SELECTOR` and a key (`MAIL_DKIM_PRIVATE_KEY` with `\n` or `MAIL_DKIM_PRIVATE_KEY_PATH`, optional passphrase). An unreadable key does not block delivery: the message leaves unsigned and `mail.dkim_signing_failed` is logged (domain, selector, exception class only) — alerts must still go out; `mail:auth-check` catches the misconfiguration before release.
- `php artisan mail:auth-check [--strict]` (`MailAuthenticationInspector`): SPF on the return-path domain, DKIM configured (own key readable as RSA, or `MAIL_DKIM_PROVIDER_MANAGED=true` with domain + selector), DKIM TXT present and not revoked (empty `p=`), own private key matches the published `p=`, DMARC (`v=DMARC1` with `p=none|quarantine|reject`, also found on a parent domain), relaxed alignment of return-path and DKIM domains with the sender domain (equal, subdomain or parent; siblings are reported as not aligned because no public suffix list is used — conservative). Fails in `--strict` or in `production`.
- README «Выпуск релиза → Почта»: both DKIM options, key generation, the three DNS records, the check command.

Verification:

- `tests/Feature/Mail/MailAuthenticationTest.php` — 10 tests: header added; a multipart HTML+text message sent through the Laravel mailer verifies cryptographically (relaxed body hash and header signature against the public key; tampered body or recipient fail); unreadable key sends unsigned and logs; inspector happy path, mismatched and revoked DKIM key, unreadable private key, provider-managed DKIM with a provider bounce subdomain, DMARC on the parent domain, strict failure and non-strict report.
- Backend SQLite: 468 passed, 4 skipped; PostgreSQL 18: 472 of 472.
- Real SMTP to local Mailpit with a freshly generated key: the raw message received by Mailpit verified with the independent `dkimpy` 1.1.8 verifier (`True`; with the recipient changed `False`). No real mail provider or public DNS was used.

### Step 108: Plan Change, Cancellation and Store Capacity in the Customer Dashboard

Status: complete

- Paddle sandbox checks before coding (ADR 0037): subscription preview returns the proration and the next transaction without changing anything; `prorated_immediately` Start + 1 store → Pro + 1 store charged 27.82 € now and 56.00 € at renewal; Paddle has no scheduled item change (only cancel/pause/resume), `do_not_bill` changes items at once and the renewal bills the new items; the proration charge carries no subscription discount and the old next-period combined discount stays on the subscription after an item change; scheduled cancel keeps `active` with `scheduled_change.action = cancel`, `scheduled_change: null` resumes, items can change while a cancel is scheduled; `discount: null` and `on_payment_failure: prevent_change` are accepted.
- Migration `2026_10_12_160000_create_billing_plan_changes`: `billing_plan_changes` (from/to price and extra stores, kind immediate/next_period, status, due now, next period before/after discounts, retained stores, contract version, preview expiry, effective time, idempotency key, error, discounts settled; PostgreSQL CHECKs and one scheduled change per contract) and `stores.capacity_paused_at`.
- `PaddleSubscriptionActions`: preview (same currency and interval; not lower recurring price → immediate with Paddle proration, lower → next period; while a cheaper plan is scheduled only another cheaper plan; retained stores required when the new plan allows fewer stores; our next-period price with the discounts that still apply), confirm (token valid 10 minutes, contract version re-checked, Idempotency-Key; `on_payment_failure: prevent_change`; declined → `payment_failed` and nothing changes; lost answer → `unknown`, never retried), withdraw a scheduled change (paid items back with `do_not_bill`), cancel at period end and resume (optional expected version), all audited; the subscription is read back from Paddle after every action. `billing:plan-changes` (every minute): due scheduled changes take effect, unknown outcomes are resolved by reading the subscription, next-period discounts are re-planned.
- `SubscriptionProjector` keeps the paid plan and stores until a scheduled cheaper plan is due. `DiscountLedger`: next-period discounts only for plans they cover, `replanNextPeriod` (gives the period's uses back, removes the old Paddle discount, plans on the new price), `nextPeriodQuote` for the preview. `BillingCatalog` now owns the sellable base/extra-store price lookup used by checkout and plan change.
- `StoreCapacity`: retained stores are applied when the change takes effect, the others get `capacity_paused_at` (nothing deleted); with more running stores than the limit and no choice the organisation is `capacity_choice_required`; `CheckScheduler` skips paused stores and such organisations, manual runs return 403 `store_capacity_paused`. No limit in pilot mode.
- API: `POST /billing/plan-change-preview`, `POST /billing/plan-change`, `POST /billing/plan-change/cancel`, `POST /billing/cancel`, `POST /billing/resume`, `PUT /billing/retained-stores`; subscription DTO adds `price`, `scheduled_change`, `capacity`; store DTO `capacity_paused_at`. OpenAPI (both copies).
- Customer dashboard: plan cards of a paid subscription — "switch to this plan" / "change number of stores" with extra stores; the change panel shows when it takes effect, the charge now, the next payment before and after discounts, the store choice when needed and confirms; scheduled change with "withdraw"; cancellation explains until when, what stays and that nothing is refunded, optional reason; scheduled cancellation with "resume"; capacity choice panel; store card shows a capacity pause. Texts ru/en/de, new error codes translated.
- Found in the first live run: the page showed "resumed" before it had re-read the subscription, so a check right after the notice still saw the old state; notices now appear after the reload.

Verification:

- `tests/Feature/Billing/PlanChangeTest.php` — 11 tests (upgrade preview/confirm/replay/idempotency conflict/used; expiry, stale contract and superseded preview; declined payment; lost answer resolved by `billing:plan-changes`; cheaper plan kept until the period end then applied; withdraw; retained stores required/invalid, pause only at the period end, nothing deleted, re-choice; scheduler and manual run skip paused stores and wait for a choice; cancel/repeat/resume/version conflict with audit reason; owner only, unchanged, interval, extra stores, past due; next-period discounts re-planned on the new price with a plan-restricted code dropped).
- Backend SQLite: 479 passed, 4 skipped; PostgreSQL 18: 483 of 483. Customer: 107 tests, typecheck, build; palette check ok; dashboard smoke 42/42.
- Live in the Paddle sandbox (`BW_CHANGE=1 run-billing-live.sh`, real test-card purchase Start + 1 store 28.00 €): upgrade to Pro previewed (charged now 21.00 €, next 49.00 €) and confirmed — Paddle transaction `subscription_update` 21.00 € completed, Pro read back as current; Start scheduled for the next period with Pro kept; withdrawn; renewal cancelled at the period end; resumed — PASS (second run, after the notice-order fix).

### Step 109: Customers and Subscriptions in the Owner Panel

Status: complete

- ADR 0038. New capability `tenants.manage` (platform owner only).
- `TenantDirectory`: organisation list (search by name, organisation id or the owner's verified email; filters service state, subscription state incl. none, plan, cancellation scheduled, trial ending before; cursor, 50 per page) with access state and reasons next to the subscription, store capacity, open incidents, active override; organisation card (access, capacity, members, stores as a summary only, billing: subscription with scheduled change and next charge, provider contracts, plan changes, overrides; platform audit of the organisation); subscription list. Commercial fields only with `billing.read`; emails masked (`a***@domain`) unless the viewer is the platform owner; no secrets.
- `TenantActions`: suspend / resume (organisation status only, billing untouched), grant temporary access (published plan version + extra stores until ≤ 180 days, one at a time) / revoke, extend our own trial (later than now and the current end, ≤ 90 days), cancel at period end through `PaddleSubscriptionActions::cancel` (customer audit shows a system actor with `by: platform`), refresh from Paddle. Preview applies database-only actions in a rolled-back transaction and reports access before/after, stores and effective date. Apply needs a reason and an authenticator code; every attempt (denied, failed, succeeded) goes to `platform_audit_log` with access before/after.
- API: `GET /platform-api/v1/tenants`, `GET /tenants/{id}`, `GET /subscriptions`, `POST /tenants/{id}/actions/{action}/preview`, `POST /tenants/{id}/actions/{action}`; OpenAPI (both copies, YAML parsed).
- Control panel: "Клиенты" (filters in the address, search kept out of it because it may hold an email), organisation card with tabs overview / stores / subscription / members / audit and an actions panel (preview → reason + code → confirm), "Подписки"; ru/en/de.
- Found while testing: an override granted "now" was shown as not active (`starts_at` equal to now is not "past"); the audit recorded the access after an action from the calculator's memo (same as before); pressing Enter in the action form bypassed the disabled confirm button — all fixed.

Verification:

- `tests/Feature/Platform/TenantAdminTest.php` — 7 tests (list, filters, search by verified email and id, subscription list; masked contacts and no billing for support, no actions; card without secrets and with a store summary only; suspend preview/denied code/apply/audit/billing untouched/resume; override period, one at a time, revoke; trial extension rules; cancel through the adapter with the customer audit and refresh from Paddle).
- Backend SQLite: 486 passed, 4 skipped; PostgreSQL 18: 490 of 490 (one test first failed on PostgreSQL only because `jsonb` reorders keys — the assertion now compares by key). Control: 17 tests, typecheck, build; palette check ok.
- Live in Chromium against the real backend (`run-live.sh tenants`): organisation found among trials, filter in the address and the search not, card and stores, trial extended by 10 days, service suspended and resumed, every action in the organisation audit — PASS.

### Step 110: Owner Decisions on ADR 0038 and Temporary Access Limits

Status: complete

- The owner accepted ADR 0038 points 4–6: suspension leaves billing untouched (may be revisited), temporary access up to 180 days and one at a time, our own trial extended up to 90 days from now; a provider trial with a payment method is not extended, so its first charge date never moves.
- Fixed: on top of a paid period an override replaced the plan's limits, so a temporary access of a smaller plan lowered the store limit the customer pays for. `EntitlementCalculator::widerLimits` now takes the more generous value of each limit (null is "no limit"; the minimum check interval takes the smaller value, every other limit the larger; a limit the override does not mention stays as the plan has it). Without a paid period the override's own limits apply, as before.
- The action hint in the owner panel says so (ru/en/de).

Verification:

- `EntitlementsTest::test_override_never_lowers_a_limit_of_the_paid_plan`.
- Backend SQLite: 487 passed, 4 skipped. Control: 17 tests, typecheck.

### Step 111: Public Site Rendered on the Server, Content Pages and the Contact Form

Status: complete

- ADR 0039. Owner choices: HTML code with a live preview, one slug with a text per language, messages stored and announced by email, draft → publish with every published version kept; server-side rendering mandatory.
- Rendering: Laravel Blade on every request (`routes/site.php`, `SiteController`, `resources/views/site`): `/` redirects by `Accept-Language`, `/{lang}` home, `/{lang}/pricing`, `/{lang}/contact` (GET/POST in the `web` group for the session and CSRF), `/{lang}/{slug}` and `/{lang}/{slug}/{slug}`, a 404 page in the site layout. Canonical and `hreflang` only for languages with published content; header/footer menus from published titles; mobile menu with `<details>`, no JavaScript. Public pages `Cache-Control: public, max-age=60`, no cookies; the contact page `no-store`. The Laravel welcome page and its example test are gone.
- `apps/frontend/public` now only builds the site stylesheet (Tailwind + frozen palette + content classes) into `apps/backend/public/site` (not in git) with a manifest read by `SiteAssets`; vite-ssg, Vue and the prerender check were removed from it (ADR 0017 superseded for this package).
- Content: `public_pages` (system `home`, `pricing`, `contact` from the migration; ordinary pages with a slug fixed at creation, parent only a top-level ordinary page, unique path among active pages, menu and order) and `public_page_versions` (per language one draft and one published version by partial unique indexes; publishing retires the previous version, restore copies into the draft, unpublish, archive). `PageAdmin` + `PageSanitizer` (Symfony HtmlSanitizer, new dependency `symfony/html-sanitizer`): no scripts, frames, forms, handlers or `style`; links http(s)/mailto/tel/relative with `rel="noopener noreferrer"`; images https/relative. Every change audited; publish/unpublish/archive with a reason; the publication audit keeps version, previous version and content hash.
- Pricing (`PricingTable`, `MoneyFormat`): published catalog of the current Paddle mode, period/currency switches only over published prices, yearly charge with “about X per month” and a saving only against a monthly price of the same plan and currency, tax wording, trial, features and limits; minor units formatted with string arithmetic. Placeholder plans only in test mode, marked “Example plan”.
- Contact form: name, email, company, message; honeypot; 5 per hour per IP; IP not stored. `ContactInbox`: list with counts per status, card, status/note with audit, `site:contact-notify` (every minute; `WATCHDOG_SITE_CONTACT_NOTIFY` or all active owners; claim before sending, released on failure) and `site:contact-purge` (daily; closed for 365 days).
- Platform API: `GET/POST /pages`, `GET/PATCH /pages/{page}`, `POST /pages/{page}/preview`, `PUT/DELETE /pages/{page}/locales/{locale}/draft`, `POST .../publish`, `POST .../unpublish`, `POST /pages/{page}/versions/{version}/restore`, `POST /pages/{page}/archive` (capability `content.manage`, owner); `GET /contact-requests`, `GET /contact-requests/{id}` (`contacts.read`: owner, admin, support), `PATCH /contact-requests/{id}` (`contacts.manage`: owner, support); OpenAPI (both copies, YAML parsed).
- Control panel: sections «Сайт» (pages with state per language, create with a slug preview) and the page editor (language tabs, title, description, HTML, a live preview of the real layout in a sandboxed frame with the stylesheet inlined, save / publish with a reason / discard / unpublish, version history with restore, menu and order, delete), «Обращения» (status tabs with counts, card with reply link, status and internal note); ru/en/de.
- Found while testing: on PostgreSQL the self-referencing foreign key of `public_pages` was created before the primary key — moved to a separate statement (SQLite did not show it); the editor preview was unstyled because the sandboxed frame could not reach `APP_URL` — the stylesheet is now inlined into the preview; the first card had an extra top margin; a page removed from the site was still shown from the browser cache for up to a minute (expected with `max-age=60`, recorded in ADR 0039 point 12).

Verification:

- `tests/Feature/Site/PublicSiteTest.php` — 8 tests (language redirect, built-in home without content, canonical/hreflang, no cookies, 404; draft → sanitized → preview → publish → second version → history → restore → discard → unpublish with audit; slugs fixed, unique, two levels, system pages, archive rules; home/contact texts from the panel; pricing with monthly/yearly, saving, extra store, trial, test notice, example plans in test mode only, live prices only in live mode; contact form storage, validation, honeypot, rate limit, notification and failure; roles and retention). `OpenApiRoutesTest` green.
- Backend SQLite: 494 passed, 4 skipped; PostgreSQL 18: 498 of 498. Frontend check: palette, customer 107, control 22 (new `tests/site.test.ts`, 5), builds, site stylesheet check.
- Live in Chromium against the real backend (`run-live.sh site`): page created and published in the panel with the preview, read on the public site as complete HTML with JavaScript off (script and handler removed, linked in the menu, mobile layout), English 404 until translated, pricing page, contact form errors then sent, message closed with a note in the panel, page deleted and 404 — PASS.

### Step 112: Parallel Integration Sync and Reconciliation at Scale

Status: complete

- ADR 0040. Owner choices: the nightly sweep queues only what can change plus a monthly full re-check; an unchanged result writes no new history.
- Sync: `SyncIntegration` job (queue `sync`, unique per integration and mode while queued, `WithoutOverlapping` per integration across modes, timeout 840 s, `retry_after` 960 s); `IntegrationSyncRunner` (eligible integrations per provider, oldest successful sync first; the call per provider). `stripe:sync`, `paypal:sync`, `woocommerce-api:sync`, `shopware:sync` only request jobs (`requested=N`); `--inline` syncs in the process as before. Docker Compose `sync-worker` (`queue:work redis --queue=sync`, `BW_SYNC_WORKERS`).
- Reconciliation: `reconciliation:work` (batches of 25, lease 300 s, sleeps when idle, exits on `--max-time`/SIGTERM/memory, `--stop-when-empty`); claims use `FOR UPDATE SKIP LOCKED` on PostgreSQL; Docker Compose `reconciliation-worker` (`BW_RECONCILIATION_WORKERS`); `pcntl` added to the backend image (it was missing — queue job timeouts were not enforceable either).
- `reconciliation_subject_states` + `ReconciliationResultRecorder`: one current result per order / store scan (run, result hash, worst status, finding count, versions, entitlement and freshness seen, `evaluated_at`, `changed_at`); an unchanged result only moves `evaluated_at`, incidents are correlated only for changed results; backfill from the latest completed run per subject; index on `reconciliation_findings.run_id`. Current findings (cabinet, CSV export, order card) read the current state.
- Nightly sweep: within 90 days only never-checked, pending/unknown, changed since the last check, older rules, entitlement or freshness changed — plus the longest-unchecked orders older than 30 days, ≤ 1/30 of the store's window per night (min 100). Output `orders_marked`, `orders_rechecked`.
- Found while working: a mark arriving during processing kept the subject's past due time, so a pending order was re-claimed on every pass until its grace deadline (with continuous workers this would be a hot loop) — the mark now sets its own due time; the unmatched-payment scan put every allocation id of the store into the statement (PostgreSQL parameter limit on a big store) — now a subquery; "current findings" showed an older result when the newest one had no findings — fixed by the current state.

Verification:

- New `tests/Feature/Integrations/IntegrationSyncQueueTest.php` (3: one job per eligible integration, oldest first, statuses per provider, modes, queue; uniqueness and the shared overlap key, `retry_after` > timeout; job skips ineligible/missing integrations; `--inline`) and `tests/Feature/Reconciliation/ReconciliationAtScaleTest.php` (6: unchanged result writes no history and moves the check time; current findings with an empty result; nightly policy picks exactly the changeable orders and the oldest; monthly budget; workers until empty and an unchanged second pass; no re-claim loop before a grace deadline). Adjusted: correlator, findings API and scheduled reconciliation tests for the new behaviour.
- Backend SQLite: 503 passed, 4 skipped; PostgreSQL 18: 507 of 507.
- Measured locally on PostgreSQL 18 (Docker on the development Mac, 6000 orders in 3 stores, separate database): one worker ~62 orders/s with results written (96 s), ~119 orders/s unchanged (50.5 s); four workers ~290 orders/s unchanged (20.6 s); runs stayed 6000 after the unchanged passes; 10 SQL statements per evaluation.
- Live in Docker against Redis and the dev database: `stripe:sync`, `paypal:sync` and a repeated `stripe:sync` requested 5 jobs, the repeated two were dropped as duplicates, `queue:work` ran 3 syncs against the Stripe/PayPal sandboxes (PayPal 38 s); two `reconciliation:work` processes split 388 subjects (188 + 200) without overlap; a nightly sweep over 153 stores queued 70 orders + the store scans and the re-check wrote no new runs (409 before and after).
- `spec/validate_spec.py` fails on its platform-billing operation count before and after this change (pre-existing, unrelated).

### Step 113: Fixes After Review of Step 112

Status: complete

- Sync dispatch skipped and repeated integrations: `IntegrationSyncRunner::dispatch` paged with `chunk(500)` ordered by `last_successful_sync_at` while workers updated that column, so rows moved between pages (the owner reproduced 100 of 1000 skipped and 100 repeated). The ids are now read in one query in the required order before any job is queued. New test: 1201 integrations with the synchronous test queue — each job updates its sync time during the dispatch — every integration is requested exactly once (the test fails on the old code).
- The monthly re-check did not guarantee a month: an order entered it only after 30 days and then a nightly budget applied, so orders checked on one day could wait longer. Each subject state now has a fixed `recheck_slot` (crc32 of the subject id mod 30, backfilled by a migration) and the sweep re-checks the orders of tonight's slot, plus any order not checked for more than 30 days (a night that did not run). Tests: 300 orders checked on one day are each re-checked exactly once over the next 30 nights, at most 30 a night; an order whose night was missed is taken the next night. ADR 0040 and README updated.

Verification: backend SQLite 505 passed, 4 skipped; PostgreSQL 18: 509 of 509.

### Step 114: Volume-Based Packages Configured in the Owner Panel

Status: complete

- ADR 0041 (now partly implemented). Order counter `store_order_volumes` filled by `OrderSnapshotProjector` on the first projection of a non-synthetic order placed after the store was added (`OrderVolume::count`); `OrderVolume` figures (this month, three-complete-month average), daily `billing:order-volume` (average, over-the-package mark, one email to owners with the date to choose by, clears when back within), grace days per package version, recommendation of the smallest covering package (individual one last).
- Package settings in the owner panel: plan limit `max_orders_per_month` (in the generic limits list, minimum 1), `order_volume_grace_days` (0–365) and `contact_sales` on the version (publish without a base price allowed for it; copied to new versions).
- Shown: cabinet billing «Order volume» block, order limit in plan cards, individual package card with a contact link and without trial/purchase; owner panel organisation card stores tab (orders this month, average / limit, over since); public pricing page (orders per store row, «Individual» card with «Discuss terms»).
- Mail texts ru/en/de (`account.order_volume_over`).

Verification:

- New `tests/Feature/Billing/OrderVolumeTest.php` (4: whole-month average; over the package → marked, one email with average/limit/date, repeated night sends nothing, summary with grace end and recommendation, back within clears; individual package recommended beyond priced ones, pilot evaluates nothing; pricing page row and individual card), `DomainOutboxDispatcherTest` (an order counts once over two revisions; an order placed before the store was added does not), `PlanAdminTest` (limit, grace days, validation, publish of an individual package without prices, copied to a new version). Owner panel: plan editor saves the limit, grace days and individual price; stores tab shows the volume (control 23 tests). Cabinet: volume block, over notice, recommended individual package and card (customer 108 tests).
- Backend SQLite: 511 passed, 4 skipped; PostgreSQL 18: 515 of 515 (one assertion first failed on PostgreSQL only because `jsonb` reorders keys). Frontend check green. No live browser run in this step.

