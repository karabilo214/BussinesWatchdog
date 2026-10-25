# Progress: Core platform

Infrastructure, repository and runtime, auth and tenancy, stores and pairing, event ingestion, outbox, hardening, API readiness, acceptance and readiness summaries.

### Step 01: Local Infrastructure Bootstrap

Status: complete for local infrastructure bootstrap

Added:

- `.env.example` with local infrastructure defaults.
- `docker-compose.yml` for PostgreSQL, Redis, MinIO, and Mailpit.
- `Makefile` helpers for setup, up/down, logs, and reset.
- `docs/implementation/implementation-step-01-checklist.md`.

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
- `docs/implementation/implementation-step-03-checklist.md`.

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
- `docs/implementation/implementation-step-04-checklist.md`.

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
- `docs/implementation/implementation-step-05-checklist.md`.

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
- `docs/implementation/implementation-step-06-checklist.md`.

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
- `docs/implementation/implementation-step-07-checklist.md`.

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
- `docs/implementation/implementation-step-08-checklist.md`.

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
- `docs/implementation/implementation-step-09-checklist.md`.

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
- `docs/implementation/implementation-step-10-checklist.md`.

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
- `docs/implementation/implementation-step-11-checklist.md`.

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
- `docs/implementation/implementation-step-12-checklist.md`.

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
- `docs/implementation/implementation-step-13-checklist.md`.

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
- `docs/implementation/implementation-step-14-checklist.md`.

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
- `docs/implementation/implementation-step-15-checklist.md`.

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
- `docs/implementation/implementation-step-16-checklist.md`.

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
- `docs/implementation/implementation-step-17-checklist.md`.

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
- `docs/implementation/implementation-step-18-checklist.md`.

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
- `docs/implementation/implementation-step-19-checklist.md`.

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
- `docs/implementation/implementation-step-26-checklist.md`.

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
- `docs/implementation/implementation-step-27-checklist.md`.

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
- `docs/implementation/implementation-step-28-checklist.md`.

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
- `docs/implementation/implementation-step-29-checklist.md`.

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
- `docs/implementation/implementation-step-39-checklist.md`; ADR 0001 sections marked resolved.

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
- `docs/implementation/implementation-step-40-checklist.md`; ADR 0001 section marked resolved.

Verification:

- `php artisan test` (PHP 8.4) passed: 245 tests.

Remaining:

- Plugin-side rotation client, Stripe credential rotation; remaining ADR 0001 gaps (JSON Schema ingest validation, store verification, projection composite FKs / PostgreSQL test run, MinIO).

### Step 41: Full JSON Schema Validation Of Ingested Events

Status: complete; closes the ADR 0001 ingest-validation deviation

Added:

- `opis/json-schema` 2.6; `EventSchemaValidator` against a backend copy of `contracts/event.schema.json` (sync test).
- Every ingested event is validated against the Draft 2020-12 schema; violations are quarantined as `schema_invalid`. Non-object bodies return 422 instead of 500.
- `docs/implementation/implementation-step-41-checklist.md`; ADR 0001 section marked resolved.

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
- `docs/implementation/implementation-step-42-checklist.md`; ADR 0001 section marked resolved.

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
- `docs/implementation/implementation-step-43-checklist.md`; ADR 0001 sections marked resolved.

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
- `docs/implementation/implementation-step-58-checklist.md`: implementation checklist for the future code slice, including URL normalization, anti-abuse gates, public-only browser policy, separate public diagnostic artifacts, purge job and verification plan.

Verification:

- Documentation-only change. No backend/frontend/plugin/worker tests were run.

Coordination:

- This step intentionally avoided the Shopware checkout script implementation files and only added new planning documents.

### Step 159: Owner Review of Pending ADRs

Status: complete

The owner reviewed every ADR that waited for confirmation: 0009, 0018, 0020, 0021, 0028, 0037 (points 3, 4, 6, 7), 0045–0049, 0051–0054, 0057 and 0061. Each ADR now has an «Owner decision (2026-10-16)» section. Accepted as implemented: 0009 (15 min stale, 1 h partial, warning, reconciliation paused), 0028 (Paddle first; live account when the project is ready for release), 0061 (dashboard hint only). Changes requested by the owner became tasks in `docs/backlog-old.md` («Решения по ADR»): owner setting for showing money amounts, separating test-mode incidents, one-click Stripe connection, MFA warning and MFA resets, plan-restricted discounts forfeited on plan change, full pause of stores above the plan limit, image upload and HTML highlighting in the page editor, block templates moved under site pages, configurable staff permissions, editable reminder/reply templates with full sent copies, support actions in customer stores, audit of changes only, member management of customer teams, editable notes, service alert e-mail, staff authenticator reset in the panel, 2-year platform audit retention, pause and public banner during maintenance windows, web server log retention for the observation endpoints. Amount/time matching stays forbidden (spec §13 unchanged).

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

### Step 190: Own Mail Server (Postfix, Dovecot, Rspamd)

Status: complete

Owner decision (2026-10-19): own mail server instead of a mail provider — Postfix receives mail for the domain, no forwarding, Dovecot mailboxes for staff.

- `infra/docker/mail` (Alpine 3.22, Postfix 3.10, Dovecot 2.4): port 25 accepts mail only for existing mailboxes and aliases (`reject_unauth_destination`, unknown recipients 550, no AUTH); 587 (STARTTLS required) and 465 (TLS) accept mail only after login and only from the user's own address or an alias that leads to the user's mailbox (`reject_sender_login_mismatch`); internal 2525 (not published) takes the service's letters from the Docker network without the filter and without TLS (the certificate is for `mail.…`, not for the Docker name `mail`). Delivery to Dovecot over LMTP, Maildir, IMAP only on 993 with TLS; the Sieve rule moves letters marked by the filter to Junk. Ports are published on IPv4 only, so the filter always sees the real sender address (Docker's IPv6 proxy would hide it).
- `mailbox` command in the container: `list`, `add`, `passwd`, `del`, `alias`, `unalias` (password typed in the terminal, at least 12 characters, SHA512-CRYPT); data in the volume `mail-data`.
- `infra/docker/rspamd` (Rspamd 3.11, Redis db 5): milter for ports 25/465/587; reject at 15, Junk at 6, no greylisting; DKIM signing of staff mail with the service key (`MAIL_DKIM_SELECTOR`, `MAIL_DKIM_PRIVATE_KEY`). When the filter is down, Postfix answers with a temporary failure (`milter_default_action = tempfail`).
- Certificate: Caddy obtains `mail.business-watchdog.com`; the mail container copies it from `caddy-data` and reloads on renewal (checked every 10 minutes); a temporary self-signed certificate until it exists.
- Backups: `backup.sh` also archives `/var/mail` (`mail-<stamp>.tar.gz`, read back before it counts), encrypts and uploads it with the dump; `prune.py` keeps each kind by the same rule.
- Production `.env`: `MAIL_MAILER=smtp`, `MAIL_HOST=mail`, `MAIL_PORT=2525`, DKIM key generated on the server (`deploy.sh init` does it for a new server and prints the DNS record). DNS (owner): `A mail`, SPF, DKIM `bw1`, DMARC `p=quarantine`, PTR of both addresses → `mail.business-watchdog.com`; MX pending.

Verification: local stack (mail, rspamd, redis) — inbound letter for an alias delivered to the mailbox; unknown recipient 550; relay 554; no AUTH on 25; wrong IMAP password refused; IMAP lists INBOX, Drafts, Sent, Junk, Trash; on 587 no AUTH before TLS; sending as someone else's address 553; sending as own alias accepted; 465 login; staff letter signed (`DKIM_SIGNED business-watchdog.com:s=bw1`); GTUBE rejected; letters above the Junk threshold land in Junk with `X-Spam: Yes`. Production: a service letter to Gmail accepted (`250 2.0.0 OK`, TLS 1.3); from outside — Let's Encrypt certificate on 993 and 587 verifies; relay to Gmail 554, unknown recipient 550, no AUTH on 25. Backend SQLite 624 passed + 4 skipped.

### Step 191: fail2ban for the Mail Server

Status: complete

- The `mail` container logs to journald (`tag: bw-mail`). Jail `infra/production/fail2ban/jail.d/bw-mail.conf` (systemd backend, `CONTAINER_TAG=bw-mail`): 6 failures in 10 minutes ban the address on 25/465/587/993 for 1 hour, growing on repeats up to a week. Filter `bw-mail`: Postfix SASL failures, any SMTP session that tried AUTH without success (`auth=0/N`, also on port 25, where AUTH is not offered — legitimate servers never try it), Dovecot IMAP `auth failed`. The ban rule goes into `DOCKER-USER` (`chain = DOCKER-USER`; a `chain` inside `banaction` is overridden by the jail default `INPUT`, which Docker-published ports bypass). `deploy.sh` installs the filter and the jail and reloads fail2ban.
- The mail container fixes the queue group of `public`/`maildrop` itself (`postfix set-permissions` fails on the missing docs directory in Alpine).

Verification: `fail2ban-regex` on three hours of production logs matched 5 bot sessions (port 25 and 587); a SASL failure line and a Dovecot 2.4 `auth failed` line matched. A manual ban of 203.0.113.9 put `-A DOCKER-USER -p tcp -m multiport --dports 25,465,587,993 -j f2b-bw-mail` and the REJECT rule in place; unban removed it; nothing in `INPUT`. No postdrop warnings after restart.

### Step 197: CI on GitHub Actions; README Split Into docs/ops

Status: complete

- `.github/workflows/ci.yml` on every push to `main` and every pull request (a newer push cancels the running one):
  - backend: PHP 8.4 with the image's extensions, Composer cache, `.env.example` + `key:generate`, `pint --test`, tests on SQLite and on a PostgreSQL 18 service;
  - frontends: Node 24, `npm ci`, `npm run check` (palette, types, unit tests, builds, site stylesheet check);
  - browser worker: `npm ci` without browser download, unit tests;
  - WooCommerce plugin: PHP 7.4 syntax lint (`lint-php74.sh`);
  - production configuration: `docker compose --profile browser config` with `env.example`, `sh -n` of the deploy, backup and mail scripts, the backup retention rule.
- To make the style gate pass, 32 older backend files were formatted with Pint (whitespace, quotes, empty bodies only; separate commit).
- README split on the owner's request: overview, «Что где лежит» and an index stay in `README.md`; the text moved unchanged to `docs/ops/` — `setup.md`, `production-server.md`, `backups.md`, `mail.md`, `billing.md`, `site.md`, `integrations.md`, `maintenance.md`, `development.md`. `CLAUDE.md`: new commands and steps go to the topic file in `docs/ops/`.
- Production: plan entitlements enforced (`WATCHDOG_BILLING_ENFORCEMENT=enforce`, owner's decision; also in `env.example`); applying `.env` changes documented.

Verification: the first CI run on `d074813` — all five jobs green (run 39263726906). Locally before that: backend from a clean export with `.env.example` (628 passed, 6 skipped), PostgreSQL 18 634/634, frontends 133 + 67 tests and builds, worker 27/27, PHP 7.4 lint ok.

### Step 203: Organization Deletion — Backend (ADR 0069)

Status: backend complete; the cabinet, owner panel and emails follow in Step 204; not deployed

- Owner's decisions (2026-10-20): purge 30 days after the request (cancellable until then); a paid Paddle subscription is cancelled immediately; billing records without people, trial-domain hashes and the deletion journal are kept; the organization owner (cabinet) or the platform owner (owner panel, on written request) can start it.
- `data_deletion_requests` (TZ schema plus via/reason/previous status/purge_after/cancel/lease/attempts; one open request per tenant, partial unique index on PostgreSQL) and `data_deletion_journal` (no foreign key, survives the purge).
- `OrganizationDeletion`:
  - request: cancels a live Paddle contract with `effective_from: immediately` (`PaddleSubscriptionActions::cancelImmediately`; a Paddle refusal → 503 `subscription_cancel_failed`, nothing changes), then sets the tenant `deleting` and writes the audit;
  - cancel: within the 30 days, restores the previous status and asks the connectors for catch-up from the request time (`StoreCatchUp`).
- While `deleting`:
  - `StoreCapacity::paused()` / `pausedStoreIds()` treat its stores like a full store pause, so ingestion answers skipped and webhooks, syncs, reconciliation, observation and freshness skip them;
  - entitlements give no service, so there are no browser checks;
  - the notification worker suppresses deliveries (`tenant_deleting`); the digest and reminder planners skip the tenant;
  - the cabinet sets no tenant context (every tenant endpoint → 403 `tenant_forbidden`); `/auth/me` memberships carry `tenant_status` and `deletion_purge_after`.
- `OrganizationPurger` + `privacy:process-deletions` (scheduler, 5 min) run resumable stages:
  - start: atomic `requested → revoking`, so a cancellation that wins the race stops the purge; the member IDs are noted;
  - revoke: integrations `revoked`, credentials and pairing codes deleted, channels disabled;
  - delete objects: screenshots and exports on their disks;
  - purge: 54 tenant tables in foreign-key order, 500 rows per chunk, cursor per table;
  - finish: members in no other organization and not platform staff are anonymized (email `deleted-<id>@deleted.invalid`, password, MFA, sessions, tokens); trial hosts become HMAC hashes (`TrialStoreHosts::hashed`, still blocking a second trial); the tenant row becomes the tombstone «Deleted organization»; the journal entry is written.
  - Five failed attempts → `failed`, with `failed_stage` in the cursor.
- Kept: `billing_customers`, `billing_subscription_contracts`, `billing_transactions`, `billing_refunds`, `policy_acceptances`, `trial_store_hosts` (hashed), the request and journal; the platform audit (2 years). A test fails when a new table with `tenant_id` is in neither list.
- Backups: `privacy:deletion-journal` (JSON lines). The nightly backup writes `deletion-journal.jsonl` and uploads it encrypted. After restoring any dump, `privacy:replay-deletions <file|->` deletes again what the dump brought back (`docs/ops/backups.md`).
- API:
  - cabinet: `GET/POST /api/v1/organization-deletion`, `POST …/cancel` — owner only, step-up, exact organization name;
  - owner panel: `POST /platform-api/v1/tenants/{tenant}/deletion` and `…/cancel` with the new owner-only capability `tenants.delete`, reason and code;
  - the tenant card carries `deletion`. OpenAPI updated.

Verification: `tests/Feature/Privacy/OrganizationDeletionTest.php` (8 tests: table coverage guard; owner-only, step-up and name; stop at once and cancel within 30 days; full purge in more than three resumable runs keeping billing records, files gone, users anonymized or kept, other tenant untouched; journal replay after an "old backup"; platform owner only with code and cancel; Paddle cancelled first and a refusal changes nothing; a cancelled request is not purged). Backend SQLite 674 passed + 4 skipped, PostgreSQL 18 678/678 (the purge runs against real foreign keys), Pint ok; owner panel 67/67; customer cabinet 133/134 — the failing test is about a Shopware error code from the parallel Codex work on the App, not this step.

### Step 204: Organization Deletion — Cabinet, Owner Panel, Emails (ADR 0069)

Status: complete; not deployed

- Cabinet: new settings tab «Организация» (`/settings/organization`, `OrganizationView`):
  - it explains the three separate actions: pause a store's monitoring, cancel the subscription, delete the organization;
  - the owner opens the deletion form, types the organization name exactly (the API returns `tenant_name`), the password and the MFA code when it is on; the button stays disabled until the name matches;
  - other roles read «only the owner».
- While the active organization is `deleting`, the router sends every page except the profile to this screen. It shows who requested the deletion and when, and the purge date. The owner can cancel with the password (and code), which returns to the overview; others are told to ask the owner; a running purge cannot be cancelled.
- Owner panel, customer card (`TenantDeletion`):
  - the platform owner (`tenants.delete`) gets «Удалить организацию по просьбе клиента…» with a reason and the authenticator code;
  - every staff member sees a banner with the purge date and who requested it, plus a «Удаляется» badge;
  - the platform owner can cancel within the 30 days; a failed purge says it needs help on the server. The capability has its label in the staff permissions.
- Emails to every owner of the organization (ru/en/de, `lang/*/account.php`): deletion scheduled (date, who, link to cancel, «if you did not ask for this»), deletion cancelled (the subscription does not come back), deletion completed (sent once, before the members are anonymized; the owners are noted when the purge starts). A mail failure never stops the deletion.
- Delivery log: error label `tenant_deleting`.

Verification: backend `OrganizationDeletionTest` 8/8 with the emails (scheduled + completed to the owner, nothing to a non-owner member, scheduled + cancelled on cancel); SQLite 674 passed + 4 skipped, PostgreSQL 18 678/678; cabinet `organization.test.ts` 3/3 (owner-only, exact name + step-up + code error, cancel and redirect, non-owner during deletion), typecheck and build; owner panel 68/68 (`tenants.test.ts`: only with `tenants.delete`, reason + code, banner, cancel). The cabinet suite has one failing test unrelated to this step: Shopware connector error codes added in `d34576b` (Codex) have no translations yet.

### Unnumbered (2026-10-21): PHP security advisory — pin the backend PHP image

Status: complete; deployed 2026-10-22 (`deb2162`, together with Step 225)

- PHP 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35 fix two critical unauthenticated RCE vulnerabilities; PHP-FPM is affected. Production serves the backend through `php:8.4-fpm` behind Caddy.
- The Dockerfile used the floating tags `php:8.4-cli` / `php:8.4-fpm`, and `deploy.sh` ran `docker compose build` without `--pull`, so the server kept whichever base image it had cached at first build and never picked up PHP patch releases.
- Now both stages are pinned to `php:8.4.27-cli` / `php:8.4.27-fpm`, and `deploy.sh` runs `docker compose build --pull` (also refreshes `node`, `caddy`, `composer` base images). Until the 8.4.27 tag appears on Docker Hub a deploy fails at `FROM` instead of shipping a vulnerable PHP.
- Not covered by this change: the test stores on the same server (`infra/test-shops`: `wordpress:7.1.3-php8.3-apache` is mod_php, the dockware Shopware image ships its own PHP) — they need `docker compose pull`/rebuild once patched images exist, or a stop until then. Local Homebrew `php@8.4` is 8.4.26 (CLI only, not serving requests).
- Ops: `docs/ops/production-server.md`.
- Deployed 2026-10-22 once the tags were published (21.10 22:10 UTC): local build of the `fpm` stage checked first (PHP 8.4.27, redis/gd/intl/pdo_pgsql/exif). On the server app, scheduler, sync and reconciliation workers report 8.4.27. Test stores: WooCommerce recreated on the refreshed `wordpress:7.1.3-php8.3-apache` (8.3.35 → 8.3.36, data in volumes); Shopware (dockware, PHP-FPM from the ondrej PPA) upgraded in place with `apt-get install --only-upgrade php8.3-*` and `service php8.3-fpm restart` (8.3.25 → 8.3.36) — lost if the container is recreated from `bw-shopware`.

### Step 230: «Сервис» Reads the Real Job Queue and Its Workers

Status: complete; deployed 2026-10-22

- Reported: `ServiceHealth` counted the `jobs` row from the database `jobs` table, while production runs `queue:work redis --queue=sync`; Redis answering PING said nothing about workers keeping up.
- `queues.jobs` now reads the configured queue connection (`QUEUE_CONNECTION`, Redis in production) for the queues `sync` (connector sync jobs, the only job type) and the connection's default queue through Laravel's `pendingSize` / `delayedSize` / `reservedSize` / `creationTimeOfOldestPendingJob`: waiting, delayed, running, age of the oldest pending job, failed jobs of the last day, plus `connection`, `queues`, `workers_seen`, `last_processed_at`.
- Workers' signs of life: new table `queue_worker_heartbeats` (`QueueWorkerHeartbeats`), written on the queue events `Looping` (at most every 30 s), `JobProcessing`, `JobProcessed`, `JobFailed`; a failed write never disturbs the worker; rows older than a day are removed. A worker is alive when it waited within 120 s or is inside a job started within 960 s (the sync job timeout is 840 s).
- State: crit when the oldest pending job is older than an hour, when jobs wait over 5 minutes with no worker alive (a restart is not an alarm), or when the queue does not answer (`error: queue_unavailable`); warn from 15 minutes or with failed jobs. The service alert e-mail names the part «задания синхронизации (обработчики очереди)». Owner panel «Сервис» shows the queue, delayed/running, workers and last job (ru/en/de). OpenAPI description updated.
- Note: a job released many times (waiting for the same integration) keeps its original creation time, so its age includes those waits.

Verification: `ServiceHealthTest` — new test runs a real `queue:work --once` on the database queue (heartbeat with the finished job), jobs waiting 10 minutes with no worker → crit, a 2-minute gap → ok, a worker inside a long job counts as alive, an unreachable Redis queue → crit `queue_unavailable`; the summary test reads pending/delayed/reserved separately. `ServiceAlertsTest` adapted. Backend SQLite 716 passed + 4 skipped, Platform tests on PostgreSQL 18 58/58, Pint ok; control typecheck and 70/70.

### Step 231: Specification Validator Passes Again and Runs in CI

Status: complete (CI and contracts only; nothing to deploy)

- Reported: `spec/validate_spec.py` failed on unique operation ids — `downloadShopwareApp` named both `POST /api/v1/stores/{store_id}/integrations/shopware-app` and `GET /api/v1/downloads/shopware-app`. The GET is now `downloadUniversalShopwareApp` (the one archive for every store, ADR 0076). The frontend calls are hand-written, nothing generated depends on the id.
- Further failures once that passed: `platformListTenants` was used both in `contracts/openapi.yaml` and in the design contract `contracts/platform-billing-api.yaml` for the same route; renamed in the design contract to `platformSearchTenants` (its other ten shared routes already had their own names). Exact counts frozen at the first version (62 tables, 42 TZ sections, 50/28 acceptance ids, 16 billing cases, 20 extension operations) turned into "at least that many, numbered without gaps, unique"; the structural checks are unchanged. Duplicate-id assertions now name the duplicates.
- `spec/VALIDATION.md` regenerated (it still said 10 operations); the validator's output is deterministic.
- CI: new job «Specification and contracts (static checks)» — Python 3.12, PyYAML 6.0.2, `python spec/validate_spec.py`, then `git diff --exit-code spec/VALIDATION.md`. Ops: `docs/ops/development.md`.

Verification: validator passes locally (237 + 20 operations, 64 tables, 43 TZ sections, 50 + 29 acceptance ids); `OpenApiRoutesTest` passes; CI run checked after push.

### Step 235: Temporary Support Access per Store

Status: deployed 2026-10-23 (`1d51398`)

- Owner decisions are recorded in ADR 0078: an employee or tenant_owner requests access for one store; tenant_owner alone approves, denies and revokes, including for platform_owner. Customer selects an active employee by work e-mail. A case reference and reason are required; 24 hours by default, at most 72 from approval.
- `support_access_grants` migration, model and service store fixed scopes (`read_diagnostics`, `read_artifacts`, `request_recheck`), requester, approval, version and expiry. Pending requests grant no access; matching retries reuse a pending request. The exact staff capability, tenant, store, scope, state and time are checked on each detailed route. Artifact content is served through a grant-checked application route so a revoked link stops working. Grant decisions, opens and actions are audited.
- Customer settings and platform tenant card have localized grant request/status/activity screens. A new request e-mails organisation owners once even when the same pending request is retried. The open staff store page clears loaded details when access ends. Incident acknowledge/resolve has a separate `tenants.incident_actions` capability, reason, audit and owner e-mail; store create/delete retain their administrative permissions.
- Contracts: `contracts/openapi.yaml`, `contracts/platform-billing-api.yaml`, `spec/database/platform-billing-extension.sql`; panel specifications and EXT-05/06 updated. No connector or money semantic changes.

Verification: backend SQLite 728 passed + 4 skipped (6212 assertions), PostgreSQL 18 732/732 (6220 assertions); after adding the request e-mail, focused `SupportGrantTest` passed 2/2 (44 assertions) on both databases. Platform frontend 70/70, customer frontend 158/158, both typechecks and builds; Pint and static spec validator passed (246 core + 25 extension operations). Production deploy `1d51398`: migration `2026_10_23_100000_create_support_access_grants` applied; app, web, workers and mail running; `/app/settings/support`, `/owner/tenants` and `/ru` returned HTTP 200; both support grant APIs returned 401 without a session. Owner-led end-to-end check remains open.

Owner acceptance on production (2026-10-23): request submission, notification e-mail and its link, approval button, audit record and revoke button all worked. Expiry, an already open staff page and an old artifact link were not part of this manual check.

### Step 236: Automatic Support Case Numbers

Status: deployed 2026-10-23 (`99f409c`)

- Owner clarified that the support access request number must be assigned without staff input. One global sequence serves every organisation. The server allocates `SUP-000001`, `SUP-000002`, … inside the same transaction as the new request, after matching pending requests have been checked. A duplicate request keeps its number and sends no duplicate owner e-mail.
- New migration `2026_10_23_110000_create_support_case_counter` seeds the counter after any existing manually entered `SUP-` references. Both request APIs prohibit a supplied `case_reference`; their responses and the owner e-mail still include it. Both cabinets remove the input and show the number after creation, as the owner chose. Contracts, ADR 0078, panel specs and support runbook updated.
- Verification: backend SQLite 728 passed + 4 skipped (6218 assertions), PostgreSQL 18 732/732 (6226 assertions); control 70/70 and customer 158/158; both frontend builds, Pint and static spec validator passed. Production migration `2026_10_23_110000_create_support_case_counter` applied; app/web/workers running; `/health/live`, `/health/ready`, `/app/settings/support` and `/owner/tenants` returned HTTP 200. The owner has not yet tested creating an automatically numbered request in the cabinet.

### Step 238: «Сервис» Connector Sync Counts Plugin Heartbeats

Status: complete

- Reported: «Сервис» kept warning «активных подключений: 9, без успешной синхронизации больше 24 ч: 1». Read-only production query (2026-10-24): all 9 active integrations were healthy; the one counted was the WooCommerce plugin integration of WooCommerce-Test (heartbeat 06:55 UTC that day). `ServiceHealth` judged every integration by `last_successful_sync_at`, which only API syncs (WooCommerce REST, Shopware, Stripe, PayPal) write; the plugin pushes events and reports only heartbeats, so an active plugin was always "stale".
- `ServiceHealth` now judges pushed order sources (`OrderSources::PROVIDERS` minus `POLLED`, i.e. the WooCommerce plugin) by `last_heartbeat_at` and everything else by `last_successful_sync_at`, both against the same 24 h. Stores paused for capacity or in organisation deletion (`StoreCapacity::pausedStoreIds()`) are not counted as stale, since nothing syncs them; they still count as active. No API shape or contract change.

Verification: new `ServiceHealthTest` case (fresh plugin, silent plugin with a recent API sync value, synced provider, paused store); backend SQLite 734 passed + 4 skipped, PostgreSQL 18 738/738, Pint ok.

### Step 240: Names for Every Recorded Admin Audit Action

Status: complete (control panel frontend only)

- Reported: the admin audit showed raw codes such as `support_grant.requested` — `AuditView` falls back to the code when `audit_action.<code>` has no translation.
- Added ru/en/de names for the 33 recorded actions that had none: refunds and withdrawal, subscription cancel/resume, plan change cancel, discounts, plans, plan prices and versions, staff logout, support grant requested/approved/denied/revoked, support access opened (data, check materials), store created/deleted, reminder sent, check rerun, incident acknowledged/resolved, member MFA reset, customer MFA enabled/disabled/reset and recovery codes. Existing texts unchanged (verified key by key).
- New `tests/auditActions.test.ts`: every action in its list is named in all three languages, and ru/en/de have the same code set. The list is maintained by hand (codes are scattered in the backend); readable descriptions, before/after values and removing JSON remain in the backlog item «Читаемый аудит в админке».

Verification: control frontend 72/72, typecheck and build ok; backend SQLite suite green. No API or DB change.

### Step 248: Admin Store Details Open in a Dialog

Status: complete (control panel frontend only)

- Admin «Магазины клиента»: clicking an order number («Заказы»), a check date («Проверки и скриншоты») or an incident («Инциденты») used to unfold the details under the table. They now open in one shared modal `DetailDialog` (native `<dialog>` with `showModal`: focus stays inside, Esc and a click outside close it, long content scrolls inside, the «Закрыть» button kept).
- The support confirmation (acknowledge/resolve with a reason) for an incident is shown inside the incident dialog, so it is not hidden behind the modal; the rerun confirmation stays on the page.
- Not done from the backlog item: a link in the address bar that opens the dialog directly and closes it with the browser «Back».

Verification: `tenants.test.ts` — the order detail is a `<dialog>`, opens, closes on Esc (`cancel`) and on «Закрыть»; check, screenshot, incident and support action tests unchanged and green. Control 72/72, typecheck and build ok; backend SQLite suite green. No API or DB change.

### Step 250: Badges in the Admin Menu

Status: complete (control panel frontend only)

- The admin side menu shows a badge where something waits, coloured like the customer cabinet (Step 249): «Обращения» — new requests (yellow, count); «Сервис» — «!» red when the service state is crit, yellow when warn; «Оплаты» — refunds awaiting the owner's approval (status `requested`, yellow, count). Hover text and screen-reader label explain it, ru/en/de; more than 99 shows «99+».
- Uses existing endpoints: contact requests `counts.new`, `GET /platform-api/v1/health` state, refunds filtered by `status=requested`. Only sections the staff member may open are queried (the server checks the capability anyway); refresh once a minute while the tab is visible; a failed request just shows no badge.
- Not done from the backlog item: «Подписки» (past due) and «Организации» (pending support requests) — no cheap existing source.

Verification: `tests/ownerBadges.test.ts` (no badges when calm, counts, service colours, 99+, texts in three languages); `service.test.ts` stubs the menu badges so it still counts only the page's own health requests. Control 75/75, typecheck and build ok; backend SQLite suite green. No API or DB change.

### Step 251: Staff Cancel and Resend Support Access Requests

Status: complete — contains a migration

- Owner request: in the admin customer card → «Доступ поддержки», «Отменить» for a request still waiting for the owner and «Отправить повторно» for any request, including revoked ones.
- New state `cancelled` (migration `2026_10_25_100000_add_cancelled_state_to_support_access_grants` widens the PostgreSQL state check; `spec/database/platform-billing-extension.sql` updated). `POST /platform-api/v1/support-grants/{grant}/cancel` — own pending request only, idempotent, 409 once decided; the owner can no longer approve it and it never gives access. `POST /platform-api/v1/support-grants/{grant}/resend` — pending: the owner e-mail is sent again (200, same request); denied/revoked/expired/cancelled: a new pending request with the same store, scopes, duration and reason and the next `SUP-…` number (201). Another employee's request answers 404. Both require `tenants.support` and step-up throttling; both are written to the platform audit, cancel also to the customer audit (`support_grant.cancelled`).
- Admin card shows the buttons and a confirmation line; both cabinets name the new state («Отменён» / «Отменён сотрудником»), ru/en/de. ADR 0078 amended, `docs/ops/support-access.md`, `contracts/openapi.yaml` (two operations), `spec/VALIDATION.md` (250 operations).
- Not done from the backlog item: «Удалить» (hide a history entry without deleting it).

Verification: `SupportGrantTest` new case (resend of a pending request e-mails again without a new request; cancel twice; customer audit and platform audit; cancelled request cannot be approved and gives no access; resend of a cancelled request creates SUP-000002 with the same parameters; an approved request cannot be cancelled; another employee's request is 404). Backend SQLite 735 passed + 4 skipped, PostgreSQL 18 739/739, Pint ok; control 76/76 (new card test), customer 172/172, both builds ok; spec validator passes.

### Step 252: Staff Hide Finished Support Access Requests

Status: complete — contains a migration

- Owner request: «Удалить» for any history entry, without real deletion. `DELETE /platform-api/v1/support-grants/{grant}` sets `staff_hidden_at` (migration `2026_10_25_110000_add_staff_hidden_to_support_access_grants`, spec SQL updated); the staff list no longer shows it. Nothing else changes: the request row, the customer's history and both audit journals keep it; the hide itself is in the platform audit (`support_grant.hidden`). A pending or still active request answers 409 (cancel or have it revoked first, so hiding never looks like ending access). Own requests only (404 otherwise), idempotent, step-up throttled. «Отправить повторно» still works for a hidden request.
- Admin card: «Удалить» (red) on finished requests with an explanatory tooltip and confirmation line, ru/en/de. ADR 0078, `docs/ops/support-access.md`, `contracts/openapi.yaml`, `spec/VALIDATION.md` (251 operations).
- This closes the support history part of the backlog item.
- Note: `control/tests/site.test.ts` failed once and passed in three following full runs — a flaky timing test, unrelated to this change.

Verification: `SupportGrantTest` — pending and active requests cannot be hidden; a cancelled one is hidden twice without error, disappears from the staff list while the active one stays, row and audit kept; another employee's request is 404 (test clock moved past the step-up limit). Backend SQLite 735 passed + 4 skipped, PostgreSQL 18 739/739, Pint ok; control 76/76, typecheck and build ok; spec validator passes.

### Step 253: Customer MFA Reset for the Owner Only, Password Reset Link for Support

Status: complete

- Owner decision (2026-10-24): «Отключить MFA» of a customer member only for the service owner, with the authenticator code; «Отправить ссылку на восстановление пароля» for the owner and staff with the right.
- `POST /platform-api/v1/tenants/{tenant}/members/{user}/mfa/reset` now answers 403 `owner_only` (audited as denied) for anyone but `platform_owner`, even with `customers.mfa_reset`; the owner path (code, reason, session revocation, e-mail) is unchanged.
- New `POST /platform-api/v1/tenants/{tenant}/members/{user}/password-reset` (`tenants.support`, step-up throttled): reason required, member must belong to the tenant (404 otherwise), sends the standard reset e-mail via `PasswordResetService`, audited as `tenant.member_password_reset_sent`; 202.
- Control panel, customer card → «Участники»: «Отключить MFA» only for the service owner; «Ссылка для смены пароля» with a reason form for staff with `tenants.support`, ru/en/de. Placed in the member row (both act on one person) rather than «Обзор → Действия» as first described in the backlog.
- ADR 0021 amended; `contracts/openapi.yaml` (new operation, MFA reset description), `spec/VALIDATION.md` (252 operations).

Verification: `TenantAdminTest` — the owner resets MFA as before; support gets `owner_only` and a denied audit row; support sends the reset link (token row, e-mail to the member, audit with reason), another tenant 404, reason required. Control `tenants.test.ts` — support sees the link button and not the MFA reset, sends with a reason. Backend SQLite 736 passed + 4 skipped, PostgreSQL 18 740/740, Pint ok; control 77/77, typecheck and build ok; spec validator passes.

### Step 254: Customer Session List and Sign Out Everywhere

Status: complete for the session-list part of the MFA backlog item; not deployed

- `/app/settings/profile` shows the signed-in customer's active database sessions, with browser family, IP, last activity in UTC and a current-session marker. The API returns no session IDs or payloads and only queries the authenticated user. A just-created current session is included before Laravel writes it after the response.
- `POST /api/v1/auth/sessions/revoke-all` requires the current password and an MFA or recovery code when MFA is enabled. It removes all customer sessions, signs out the current browser (including its remember token) and writes `user.sessions_revoked` in every team audit. Platform staff sessions remain separate.
- ADR 0021, `spec/panels/CLIENT-PANELS.md`, and `contracts/openapi.yaml` updated. No migration or financial semantics change. Passkeys, trusted devices and a non-blocking team-member MFA warning remain open.

Verification: `AccountApiTest` checks auth, tenant/user isolation, absence of raw session IDs, step-up failure and success, other-user session preservation and audit. `MfaApiTest` verifies an MFA/recovery code is required when enabled. Customer `account.test.ts` checks display and logout flow. Backend SQLite 738 passed + 4 skipped, PostgreSQL 18 742/742; customer frontend 173/173, typecheck and build; Pint, `git diff --check` and static spec validator (254 core operations) passed. No live browser or production deployment was run.

### Step 255: Customer Passkeys / WebAuthn

Status: complete for the passkey part of the MFA backlog item; not deployed — includes a migration

- Customer login accepts a discoverable WebAuthn passkey with local user verification, fresh one-use challenge and server-side origin/RP/signature/counter checks. An enabled user with a team membership gets a normal customer session; platform staff authentication stays separate. Password/TOTP login remains available.
- The profile lists only the user's passkey names and timestamps. Registration first requires a fresh password and an MFA/recovery code if MFA is enabled, then a WebAuthn ceremony; deletion uses the same step-up. Registration authorization and both challenges expire after five minutes. Register, login and delete write audit events to every team of the user. Privacy anonymization removes unused users' passkeys.
- Installed `laravel/passkeys` and `@laravel/passkeys`; package routes are disabled in favor of seven project API routes with the existing stateful session, CSRF and throttling. Migration `2026_10_25_120000_create_passkeys_table.php` stores credential material privately, without cascading user deletion. Updated `contracts/openapi.yaml` (seven operations), reference `spec/database/schema.sql`, ADR 0021, customer panel spec and EXT-03 evidence. UI strings ru/en/de.
- This closes only passkeys. Trusted devices and a non-blocking team-member MFA warning remain open. No live authenticator or production deployment was run; backend and frontend must be released together with the migration and stable `APP_URL`/`APP_KEY` or `PASSKEYS_USER_HANDLE_SECRET`.

Verification: `PasskeyApiTest` exercises a valid WebAuthn attestation for registration, fresh options, user-scoped list/delete with step-up, cryptographically signed assertion login, challenge consumption and stale-signature rejection; customer `account.test.ts` checks the passkey panel. Backend SQLite 742 passed + 4 skipped; customer frontend 175/175, typecheck and build; Pint, `git diff --check` and static spec validator (261 core operations) passed. PostgreSQL 18 746/746.

### Step 256: Optional Trusted Customer Devices

Status: complete for the trusted-device part of the MFA backlog item; not deployed — includes a migration

- Owner corrected the backlog: customer-team MFA is optional. A member without MFA should see a warning and setup link like the organization owner; no team-wide enforcement. The warning is the next separate task.
- After a successful authenticator/recovery-code login, the user may explicitly trust this browser for 30 days. A later password sign-in on the same browser skips the MFA prompt only for that exact user and only while the device token is valid. Sensitive step-up actions still require password and MFA. Cookie: host-only, HttpOnly, SameSite=Strict, auth-path scoped, Secure in production; database stores only a hash of the 256-bit random token. No IP or raw user-agent binding.
- Profile lists browser family and expiry without token or raw user agent and allows revocation; issuance, reuse and revocation are audited in every team. Password change/reset, MFA reset/disable/re-enrollment, sign-out-everywhere and privacy anonymization revoke trust. Platform-staff login is unchanged.
- Migration `2026_10_25_130000_create_trusted_devices_table.php`, two OpenAPI operations plus MFA challenge opt-in, reference SQL, ADR 0021, customer panel spec and EXT-03 evidence updated. Backend and customer frontend must be deployed together after migration. Stripe not touched.

Verification: `TrustedDeviceApiTest` covers opt-in, cookie attributes, hashed token, password sign-in reuse, own-device listing/revocation, other-user isolation, 30-day expiry, password-change/reset and sign-out-everywhere invalidation, continued MFA step-up and audit. Customer `account.test.ts` covers the opt-in and revocation UI. Backend SQLite 747 passed + 4 skipped, PostgreSQL 18 751/751; customer frontend 177/177, typecheck and build; Pint, `git diff --check` and static spec validator (263 core operations, 67 reference tables) passed. No live browser or production deployment was run.

### Step 259: Guarded Service Outbox Retry

Status: complete locally; not deployed

- The owner panel «Сервис» lists the 50 oldest `domain_outbox` dead-letter entries by ID, topic, error code, attempts and time, without payload or tenant identity. A member needs the new assignable `service.manage` capability (which implies `service.read`) to see them or retry one entry.
- A retry requires a reason and authenticator code. The server locks the row and accepts only `dead_letter`, clears the old lease/error and resets attempts before the existing dispatcher picks it up. A repeated request returns 409. Successful and denied attempts record target, tenant where known, reason, result and sanitized metadata in platform audit. No arbitrary command execution is exposed.
- ADR 0051 supersedes the earlier deferral of repair actions; ADR 0064, owner panel spec, EXT-23 and `contracts/openapi.yaml` document the capability and two routes. No migration. Other queues retain their own recovery semantics and are not retried by this action.

Verification: `ServiceHealthTest` covers permission, no customer data in the list, code rejection, reason validation, requeue state, duplicate rejection and audit. Control `service.test.ts` covers permission gating and the confirmation form; `staff.test.ts` includes the capability. Backend SQLite 748 passed + 4 skipped; control frontend 79/79, typecheck and build; static spec validator 265 core operations. No production retry or deployment was run.

### Step 260: Complete Admin Menu Badges

Status: complete locally; not deployed

- The remaining «Подписки» and «Организации» badges now show past-due subscriptions and the signed-in staff member's pending support access requests. Both are yellow with a count; the existing contacts, service and refund badges keep their severity rules, and counts above 99 show `99+`. Refund requests are shown only to staff allowed to approve them. Other menu sections have no independent action queue, so no badge is defined for them.
- All indicators come from one compact `GET /platform-api/v1/attention` response instead of loading three detail endpoints. The backend includes only fields allowed by the staff capabilities and no customer identifiers or payloads; service state is cached for at most 30 seconds. The panel refreshes once a minute in a visible tab and clears badges if the request fails. `contracts/openapi.yaml` and owner panel spec updated; no migration.

Verification: `PlatformAttentionTest` covers counts, the current refund mode, own pending requests, capability omissions and no customer data; `ownerBadges.test.ts` covers levels, 99+, translations and one request. Backend SQLite 750 passed + 4 skipped; control frontend 80/80, typecheck and build; Pint, `git diff --check`, static spec validator (266 core operations) passed. No live deployment was run. Traceability: owner panel §7, ADR 0064, backlog menu-badge item.

### Step 261: Читаемый аудит админки — работа над одним пунктом бэклога

Статус: локально завершён; пункт «Читаемый аудит в админке» отмечен `[x]`, следующий пункт не начинали; не выложен.

- `AuditPresentation` формирует для страницы и CSV локализованные название, описание, строки деталей, ссылку на объект и дополнительные ссылки на организацию/магазин. Имена берутся по ID при чтении и проверяются по возможностям сотрудника; недоступный объект показывается как ограниченный, удалённый — как удалённый. Ответ API больше не содержит сырой `sanitized_changes` и `request_id`; экран не печатает JSON, тип/ID объекта или хэш. CSV содержит читаемое описание вместо JSON и технических идентификаторов.
- Добавлены отсутствовавшие названия действий ru/en/de и автоматический тест, который собирает коды из PHP-источников и набора действий организации и сверяет копии переводов для CSV. Для обращения показываются адрес получателя и шаблон, для запроса поддержки — номер SUP, магазин, организация, области, срок и причина. Старые записи без прежнего значения показывают только новое, без выдуманного «было».
- Сохраняются `before`/`after` для изменений обращения, страниц и черновиков, блоков, версий тарифа, скидок, ролей, инцидентов, возвратов, запросов поддержки, outbox, MFA и действий над организацией. Сохранение черновика получило отдельное действие вместо общего `platform.change`. Напоминание теперь сохраняет в platform audit ID адресатов, а приглашение сотрудника и сброс MFA — без дублирования email. Существующие исторические записи не переписывались.
- Миграций нет. Контракт: `contracts/openapi.yaml` для `/platform-api/v1/audit` и CSV. Трассировка: `spec/panels/OWNER-PANEL.md` §7, OWN-09, OWN-12, EXT-29 и пункт бэклога «Читаемый аудит в админке». Stripe не затронут.

Проверка: backend SQLite 757 пройдено, 4 пропущено; PostgreSQL 18 — 761/761; control `npm run check` (81 тест, типы, сборка); Pint, `spec/validate_spec.py` и `git diff --check` прошли. Отдельные тесты проверяют ограничение прав, удалённый объект, старую запись без `before`, обращение, запрос поддержки, переводы прав/лимитов, HTML без разметки, CSV и защиту от формул.

Ограничения: в старых записях отсутствующее прежнее значение не восстанавливается; живой браузер и выкладка не проверялись. `platform.change` остаётся защитной записью для будущих маршрутов без предметного аудита; текущий маршрут сохранения черновика пишет отдельное действие.

### Выпуск 2026-10-25: Steps 238–261

Статус: выложено на production коммитом `88e7829` через `infra/production/deploy.sh`. Перед выпуском: PostgreSQL 18 — 761/761, customer — 178/178, control — 81/81, browser worker — 28/28, Pint, PHP 7.4 lint плагина, проверка контрактов и сборки; CI коммита полностью зелёный. Две новые миграции (`passkeys`, `trusted_devices`) применены; остальные миграции до Step 253 уже были на сервере.

После выпуска: сервер и образы на `88e7829`, все контейнеры работают; `/health/live`, `/health/ready`, `/ru`, `/app/`, `/owner/`, `/ru/contact`, `/ru/check-store` ответили HTTP 200. Готовность БД, Redis, кэша и keyring — `ok`; в проверенных журналах ошибок запуска нет, браузерный воркер сообщает `sandbox: true`. Перед выпуском была проверена резервная копия от 2026-10-25. Живые входы passkey/MFA, ручной повтор dead-letter записи и сквозное подключение магазина в production не проверялись. Пункт 11 бэклога остаётся открытым; Stripe не менялся.
