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

Status: implemented, test run pending

Added:

- `DomainOutboxDispatcher`.
- No-op handler for `event_inbox.received`.
- Unsupported topic failure handling.
- `outbox:dispatch` console command.
- Dispatch counters for leased/published/failed.
- Feature tests for known topic publish, unsupported topic retry, and console command execution.
- `docs/implementation-step-18-checklist.md`.

Verification:

- PHP syntax checks should be run before commit.
- `php artisan test` must be run inside the backend Docker container.

Remaining:

- Run tests after pulling changes.
- Add real projection processing, external side effects, long-running worker loop, exponential backoff with jitter, and consumer idempotency tests.

### Specification Update: Mandatory Three-Language Portal

Status: complete

Added:

- Mandatory `ru`, `en`, `de` locale coverage for public site, customer app, owner panel, admin panel, backend validation/problem messages, notifications, and user-facing exports.
- Updated base TZ, panel specs, UI API catalog, and panels acceptance.

Reason:

- Owner requirement: the whole backend/frontend portal must be multilingual in three languages, and deviations from specification must be documented.
