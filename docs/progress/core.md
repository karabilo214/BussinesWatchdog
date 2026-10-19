# Progress: Core platform

Infrastructure, repository and runtime, auth and tenancy, stores and pairing, event ingestion, outbox, hardening, API readiness, acceptance and readiness summaries.

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

### Step 55: API Readiness for the Dashboard (ADR 0014)

Status: complete; the API the minimal dashboard needs is contract-described and enforced

Added:

- Real store coverage summary (connector, money, payment attempts, browser checks, last passed check, active incidents) instead of placeholders.
- Catalogue routes for scenarios, check runs, cancel, artifact download, store integrations; `If-Match`/`ETag`; `X-Request-ID`.
- OpenAPI for all user endpoints (generated from one description), heartbeat/rotation documented as implemented; `OpenApiRoutesTest`; catalogue §11 with deviations and gaps.

Verification:

- SQLite 309 passed + 4 skipped; PostgreSQL 18 313 passed.

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

### Step 96: Readiness Summary

Status: complete

- `docs/readiness.md`: readiness by TZ area (26 areas), what separates the project from a commercial P1 (subscription/tariffs, public site, owner and admin panels, operations, checkout layer 2, §43), current test results and pending owner decisions.
- Owner decisions: Mollie and Shopify are P2; Stripe in Shopware is unsupported until a pilot customer with the Pickware plugin (ADR 0025); the Shopware panel states it (ru/en/de).
- Current smoke is red at the notifications "test email" step after Step 95 (parallel notifications work); recorded in the readiness table.

### Housekeeping: Smaller Context Files and Split Billing Controller (2026-10-13)

- `PROJECT_STATE.md` reduced from ~80 KB to a short summary (done areas, owner decisions pending, gaps, next step); step history only in `docs/progress/`.
- `docs/progress.md` split into area files under `docs/progress/` with an index; `CLAUDE.md` maps task topics to files.
- One OpenAPI contract: `spec/contracts/openapi.yaml` and its copy test removed; `spec/validate_spec.py` reads `contracts/openapi.yaml`.
- `BillingController` split into `CheckoutController` (checkout, follow, promo code), `PlanCatalogController` (plans, trial) and `SubscriptionController` (read, plan change, cancel, resume, retained stores) with the shared `Concerns\RespondsToBilling`; routes unchanged.
- Owner panel `TenantView` split into tab components `components/tenant/*`.

Verification: backend SQLite 532 passed, 4 skipped; PostgreSQL 18: 536 of 536 (the copy test removed); control 27, customer 112. `spec/validate_spec.py` still fails at the platform API operation count (unchanged).
- Follow-up: `spec/contracts/event.schema.json` and `spec/contracts/platform-billing-api.yaml` (identical duplicates) removed; `validate_spec.py`, `spec/README.md`, `contracts/README.md`, `CLAUDE.md` and `AGENTS.md` point to `contracts/`. `spec/contracts/ui-api-catalog.md` stays (the original spec text; the working catalog in `contracts/` has diverged). The backend runtime copy of the event schema stays, guarded by `IngestEventsApiTest`.

### Step 131: «Сервис» — Read-Only Service Summary in the Owner Panel

Status: complete

- ADR 0051 (accepted for now, to be confirmed by the owner). `ServiceHealth`: components from `ReadinessChecks`; queues (event inbox, domain outbox, Paddle events + last received, reconciliation backlog, notification deliveries, framework jobs) with waiting count, oldest age and problems; browser checks queued/running and workers seen in 5 minutes; stale connector syncs; thresholds; versions (`watchdog.release` from `WATCHDOG_RELEASE`, environment, PHP, Laravel). New capability `service.read` (owner, admin); `GET /platform-api/v1/health` (no-store). OpenAPI and README (`WATCHDOG_RELEASE`) updated.
- `ReadinessChecks` now catches any `Throwable` per component: on the host PHP without the phpredis extension the Redis check threw `Error` and turned the whole summary into a 500; now Redis shows as a problem (also for `/health/ready`).
- Owner panel: «Сервис» replaces the placeholder: overall state and time, components, queues table with explanations, browser checks and connector sync cards, version; refreshes every minute and on demand; staff without access see a short notice. ru/en/de.

Verification: `tests/Feature/Platform/ServiceHealthTest.php` (2: ages, problems, queued checks without workers, stale sync, release, no customer data in the response; support forbidden), `HealthEndpointTest` +1 (a component throwing `Error`). Control 47 tests (`tests/service.test.ts`, 2). Frontend check green. Backend SQLite 546 passed, 4 skipped; PostgreSQL 18: 550 of 550. Live (`run-live.sh overview`): the stand shows Redis as a problem from the host, 29 queued browser checks without a worker, dead outbox/inbox entries — PASS.

### Step 133: «Аудит» — Platform Audit Viewer With Filters and CSV Export

Status: complete

- ADR 0053 (accepted for now, to be confirmed by the owner). `AuditJournal` (page of 50 with cursor, filters actor/system, exact action, target, organisation, result, dates; list of actions and staff for filters; export generator up to 10 000 rows), `AuditController` (`audit.read`): `GET /platform-api/v1/audit`, `GET /platform-api/v1/audit/export` (CSV, formula cells quoted, audited as `audit.exported`, step-up limiter). OpenAPI: 2 operations.
- Owner panel: «Аудит» replaces the placeholder: filters kept in the address, entries with action label, result, actor or «Система», time, organisation link, reason, details (target, request id, changes), «Показать ещё», «Выгрузить CSV» with the same filters; staff without access see a notice. Labels for staff, site, block, contact and audit actions. ru/en/de.
- Found while testing on PostgreSQL: `EntitlementsTest::test_checks_need_checkout_monitoring_and_respect_the_interval_floor` compared the scheduler's next run with a later real `now()` and failed when a second boundary passed in between — the test now freezes the time (scheduler unchanged).

Verification: `tests/Feature/Platform/AuditJournalTest.php` (2: paging over 58 entries, filters by action/organisation/result/dates/system actor, CSV of the filter with a quoted formula cell and the export audited; support forbidden). Control 52 tests (`tests/audit.test.ts`, 2). Frontend check green. Backend SQLite 553 passed, 4 skipped; PostgreSQL 18: 557 of 557. Live in Chromium (`run-live.sh staff`): audit filtered by «Изменён сотрудник» shows the disabling with its reason; CSV export of the same filter — PASS.

### Step 134: «Настройки» — Read-Only Configuration and a Versioned Maintenance Window

Status: complete

- ADR 0054 (accepted for now, to be confirmed by the owner). Migration `maintenance_notices` (version, start, end, messages ru/en/de, author, reason, cancellation). `PlatformSettings` (configuration with fingerprints only, current/history, schedule with bounds, cancel, customer view), `SettingsController` (`settings.manage`, step-up code), customer `GET /api/v1/maintenance` (`MaintenanceController`). New capability `settings.manage` (owner). OpenAPI: 4 operations.
- Owner panel: «Настройки» — maintenance block (plan with start/end, messages in three languages, reason, code; current window with cancel; version history) and configuration (set/not set + fingerprints, environment, mail, Telegram, sessions, retention, site). The placeholder view `SectionView` and its texts are gone — every section of the panel is now real; «Обзор» for staff without billing access shows a short explanation. ru/en/de.
- Customer account: maintenance banner in the layout ("planned" / "in progress" with times and the message in the user's language); failures to load it are silent.
- Found on the live stand: the Telegram bot name already starts with «@» in the environment (shown once now); zero notification recipients means all owners (worded so).

Verification: `tests/Feature/Platform/PlatformSettingsTest.php` (2: configuration without secret values, bounds and messages validated, step-up, versions replace each other, customer sees German message planned then running, cancel, audit order; admin forbidden). Control 54 tests (`tests/settings.test.ts`, 2), customer 114 (`tests/maintenance.test.ts`, 2). Frontend check green. Backend SQLite 555 passed, 4 skipped; PostgreSQL 18: 559 of 559. Live in Chromium (`run-live.sh overview`): settings shown with fingerprints, maintenance published and cancelled with fresh codes — PASS.

### Step 142: Acceptance Evidence Refresh After Billing, Owner Panel and Shopware Steps

Status: complete for documentation evidence refresh

Updated:

- `spec/ACCEPTANCE.md`: refreshed the timestamp and evidence after Steps 97-141; plan quotas/suspension are now marked done with billing/entitlement evidence, Shopware browser/network-policy evidence is reflected, and stale notes about billing not being implemented were removed.
- `spec/PANELS-ACCEPTANCE.md`: refreshed platform guard, platform staff, MFA/step-up, price publishing, checkout, billing event inbox, LIVE/TEST isolation and provider-failure statuses after the Paddle, public-site and owner-panel slices.
- `PROJECT_STATE.md` and `docs/progress.md`: bumped the recovery marker to Step 142 and recorded that this was a documentation-only task.

Verification:

- Documentation-only change. No backend/frontend/plugin/worker tests were run; Step 141 remains the latest full test evidence.

Coordination:

- Claude is working on the Shopware checkout script (layer 2). This step avoided backend/frontend/worker code and did not touch the script area.

### Step 144: ADR and Checklist for Public Storefront Check by URL

Status: complete for planning documentation

Added:

- `docs/adr/0058-public-url-storefront-check.md`: proposed public pre-registration storefront smoke check by URL, explicitly limited to public pages and cautious verdicts. It records mandatory CAPTCHA, normalized host/origin cooldown (30 days), IP cooldown (one site per 10 days), submit-attempt limiting, SSRF protections, separate queue/capacity, no payment/financial/monitoring claims, and 5-minute artifact retention.
- `docs/implementation-step-58-checklist.md`: implementation checklist for the future code slice, including URL normalization, anti-abuse gates, public-only browser policy, separate public diagnostic artifacts, purge job and verification plan.

Verification:

- Documentation-only change. No backend/frontend/plugin/worker tests were run.

Coordination:

- This step intentionally avoided the Shopware checkout script implementation files and only added new planning documents.

### Step 159: Owner Review of Pending ADRs

Status: complete

The owner reviewed every ADR that waited for confirmation: 0009, 0018, 0020, 0021, 0028, 0037 (points 3, 4, 6, 7), 0045–0049, 0051–0054, 0057 and 0061. Each ADR now has an «Owner decision (2026-10-16)» section. Accepted as implemented: 0009 (15 min stale, 1 h partial, warning, reconciliation paused), 0028 (Paddle first; live account when the project is ready for release), 0061 (dashboard hint only). Changes requested by the owner became tasks in `docs/backlog.md` («Решения по ADR»): owner setting for showing money amounts, separating test-mode incidents, one-click Stripe connection, MFA warning and MFA resets, plan-restricted discounts forfeited on plan change, full pause of stores above the plan limit, image upload and HTML highlighting in the page editor, block templates moved under site pages, configurable staff permissions, editable reminder/reply templates with full sent copies, support actions in customer stores, audit of changes only, member management of customer teams, editable notes, service alert e-mail, staff authenticator reset in the panel, 2-year platform audit retention, pause and public banner during maintenance windows, web server log retention for the observation endpoints. Amount/time matching stays forbidden (spec §13 unchanged).

Code change: the 90-day audit windows of Stripe, PayPal, WooCommerce REST API and Shopware and the reconciliation lookback are now env-configurable (`WATCHDOG_*_AUDIT_DAYS`, `WATCHDOG_RECONCILIATION_LOOKBACK_DAYS`, default 90, documented in README). A legal review package for the checkout observation script (ADR 0057/0059) was prepared as a Claude Docs document for the owner's lawyer. No migration or contract change.

### Step 165: Customer MFA Reset by Tenant Owner and Platform Staff (ADR 0021; Spec §7, §24, §26; ACC-02, EXT-03)

- Tenant owner can reset another member's MFA from the team page after entering a reason, current password and their own MFA code when enabled. Platform owner/admin/support can reset MFA from the organisation team page with a reason and fresh staff authenticator code; billing staff cannot. A dedicated `customers.mfa_reset` capability scopes the platform path.
- Reset is scoped to a current member, rejects platform staff accounts and an owner resetting themselves, clears the global MFA secret and recovery codes, rotates the remember token, ends every customer session, and writes tenant audit entries in every team the user belongs to. Platform resets also write success/denial to platform audit. The user gets a localized warning email and can enroll again.
- Added two API routes, `mfa_enabled` to team member responses and `user_id` plus `mfa_enabled` to platform tenant cards. Updated `contracts/openapi.yaml` and `contracts/ui-api-catalog.md`; corrected pre-existing indentation in the OpenAPI tenant-store parameters so the YAML parses. No migration or event schema change.

Verification: owner and platform reset feature tests, including role/tenant isolation, wrong step-up, session/recovery-code revocation, platform staff protection, audit and email. Backend SQLite 603 passed + 4 skipped, PostgreSQL 18 607/607; customer 131/131, control 58/58, both typecheck/build. OpenAPI route guard passed and YAML parsed. No live browser or SMTP delivery smoke.

### Step 179: E-mail the Responsible Person About Service Problems

Status: complete (ADR 0051 owner decision)

- Migration `2026_10_18_190000_create_service_alert_state`; `App\Support\Platform\ServiceAlerts`, command `service:alerts` (scheduled every minute, without overlapping, on one server); config `watchdog.service_alerts.email|locale` (`WATCHDOG_SERVICE_ALERT_EMAIL`, `WATCHDOG_SERVICE_ALERT_LOCALE`); texts `account.service_alert.*` (ru/en/de) naming the problem parts; platform audit `service.alert_sent`; «Настройки» shows the number of recipients. README: variables, schedule, maintenance command.

Verification: `ServiceAlertsTest` (nothing while fine; one problem e-mail to each valid address with the parts and the link; no repeat while it lasts; one recovery e-mail when it drops to attention; nothing more; audit; a failed e-mail leaves the state unsent for a retry; no address — nothing). Control 65/65, typecheck, build. Backend SQLite 624 passed + 4 skipped; PostgreSQL 18: 628/628. A real SMTP send was not run.

### Step 181: Platform Audit Kept for Two Years

Status: complete (ADR 0053 owner decision)

- Command `platform:audit-purge` (chunked delete, scheduled daily 03:50 UTC), config `watchdog.platform.audit_retention_days` (`WATCHDOG_PLATFORM_AUDIT_RETENTION_DAYS`, 730); «Настройки» lists the period; README variables, schedule and maintenance command.

Verification: `PlatformAuditPurgeTest` (entry just older than two years deleted, newer kept; shorter configured period). Control 67/67, typecheck, build. Backend SQLite 626 passed + 4 skipped; PostgreSQL 18: 630/630.

### Step 182: Pause Alerts During a Maintenance Window

Status: complete (ADR 0054 owner decision)

- `App\Support\Platform\MaintenanceWindow` (`active`, `lastEndedAt`, `since`). Guards in `CheckScheduler::scheduleDue`, `ConnectorFreshness::checkAll`, `NotificationDeliveryWorker::deliverDue` (sweep only), `IncidentReminderPlanner::planDue`, `ObservedPaymentAttempts::markStuck`, `CheckoutObservationEvaluator::evaluateAll`; `ConnectorFreshness::assess` counts heartbeat, delivery backlog and polled sync gaps from the end of the last window.

Verification: `ConnectorFreshnessTest` (active window: no freshness change, no scheduled run, no delivery; right after the end no incident; still silent 15 minutes later — stale and one incident; a window cancelled before start changes nothing). Backend SQLite 628 passed + 4 skipped; PostgreSQL 18: 632/632. No migration, no contract change.

### Step 187: First Production Server

Status: complete (owner chose Contabo Cloud VPS 8 and the domain business-watchdog.com)

- Server hardening: key-only SSH (dedicated deploy key), ufw 22/80/443, fail2ban, unattended upgrades, Docker 29 / Compose 5.
- `infra/production`: `docker-compose.yml` (Caddy `web` with Let's Encrypt and an internal `http://web:8080` for the browser worker, `app` PHP-FPM with baked code, `scheduler`, `sync-worker` ×2, `reconciliation-worker` ×2, PostgreSQL 18 and Redis unpublished, browser worker and egress under the `browser` profile), `Caddyfile` (site via PHP, `/app` and `/owner` SPAs with fallbacks, immutable asset caching, HSTS), `env.example`, `deploy.sh` (git working copy on the server, checkout of a pushed commit, refuses local changes, build, wait for PostgreSQL over TCP, migrate, start). New Docker stages `frontend`, `production`, `web`; `.dockerignore`. Backend trusts forwarded headers when `TRUSTED_PROXIES` is set (`bootstrap/app.php`). README section «Боевой сервер».
- Artifacts, exports and site media use the local disk volume; mail is log-only until an SMTP provider is chosen; Paddle keys are not set yet.

Verification: from outside — `/` → `/en`, `/en`, `/de/pricing`, `/app/`, `/owner/login`, `/health/ready` (database, Redis, cache, keyring ok), `www` and `http` redirects, Let's Encrypt certificate until 2027-01-17, HSTS; scheduler runs its tasks; all migrations ran. Backend SQLite 624 passed + 4 skipped after the proxy change. No platform owner, worker token, SMTP or backups yet.

### Step 188: Daily Production Database Backups with a Restore Check

Status: complete (retention rule by the owner)

- `infra/production/backup/backup.sh`: `pg_dump --format=custom` from the running PostgreSQL, restore into a throwaway `postgres:18` container (tables and migrations must exist), `last-success` marker, then `prune.py`. `prune.py`: all backups of the current ISO week; the last backup of each earlier week of the current month; the last backup of each earlier month for `BW_BACKUP_KEEP_MONTHS` (12, the owner did not fix a number); the newest is always kept. `bw-backup.service` / `bw-backup.timer` (03:15 Europe/Berlin, persistent), installed and enabled by `deploy.sh`. README: running, listing, copying and restoring.

Verification: prune rule on 100 simulated daily backups at 2026-10-19 keeps 2026-07-31, 08-31, 09-30, 10-04, 10-11, 10-18 and 10-19; first real run on the server: 316 KB dump, 96 tables restored, timer scheduled. Open: the backups are on the same server (no off-site copy yet), so ACC-41 (restore on a new host) is still not done.

### Step 189: Encrypted Off-site Backups

Status: complete

- `backup.sh`: after the restore check and local pruning, the dump is encrypted with `age` for `BW_BACKUP_AGE_RECIPIENT` (public key only; the private key was generated on the owner's Mac, `~/business-watchdog-backup.agekey`, to be kept offline), uploaded with `rclone/rclone:1.71.2` to `BW_BACKUP_S3_*` (Contabo Object Storage, `eu2.contabostorage.com`, bucket `business-watchdog-backups`), and off-site copies are pruned by the same rule (`prune.py --names`). `deploy.sh` installs `age`; `env.example` lists the variables. README: off-site copy and restore steps. ACC-41 → partial.

Verification: on the server the backup uploaded `db-2026-10-19T094852Z.dump.age`; on the owner's Mac the copy was downloaded from the bucket, decrypted with the private key and listed by `pg_restore` (96 table data entries). `prune.py --names` keeps 09-30, 10-11, 10-12, 10-19 and deletes 09-15 for 2026-10-19. The storage credentials were shared in the chat; the owner decided not to rotate them.
