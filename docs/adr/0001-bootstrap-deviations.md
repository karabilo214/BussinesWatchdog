# ADR 0001: Bootstrap Deviations From Specification

Date: 2026-10-08

Status: accepted for local bootstrap; must be revisited before P0/P1 acceptance.

This ADR records temporary implementation deviations made to keep D1 bootstrap moving. Each item must either be reverted, formalized in a later ADR, or proven equivalent before the relevant acceptance gate.

## Deviations

### Local S3 Uses S3Mock Instead Of MinIO

Specification target: MinIO for local S3-compatible storage.

Current implementation: `adobe/s3mock:5.2.2`.

Reason: the official `minio/minio` Docker image was not pullable from Docker Hub in the local environment, and LocalStack latest required license activation.

Risk: S3Mock behavior may differ from MinIO and production S3-compatible storage.

Return plan: choose and pin the local object-storage emulator in a D0 infra ADR before storage-sensitive features or artifact retention are implemented.

### Auth API Uses Session Middleware Without Sanctum Package

Specification target: Vue SPA with Sanctum same-origin sessions and CSRF.

Current implementation: `/api/v1/auth/*` routes use Laravel session middleware, with CSRF excluded for `api/*`. Sanctum is not installed yet.

Reason: bootstrap needed working register/login/me/logout endpoints before installing and configuring Sanctum.

Risk: current auth routes are not final production CSRF/Sanctum configuration.

Return plan: install/configure Sanctum and restore proper same-origin CSRF flow before frontend auth work is considered complete.

Resolution (Step 39, 2026-10-09): resolved. `laravel/sanctum` 4.3 with `statefulApi()`; the `api/*` CSRF exclusion is removed; session routes use `auth:sanctum` and reject non-SPA origins (`400 stateful_session_required` / `401`); `/sanctum/csrf-cookie` issues `XSRF-TOKEN`; login (5/min per email+IP) and signup (3/hour per IP) are throttled per `spec/contracts/ui-api-catalog.md`. Connector endpoints (HMAC) are unaffected.

### Test Migrations Use SQLite-Compatible Fallbacks

Specification target: PostgreSQL 18 constraints and JSONB semantics.

Current implementation: Postgres CHECK constraints are applied only on `pgsql`; SQLite tests skip those raw constraints. `stores.settings` uses a SQLite-safe default in tests.

Reason: Laravel's default fast test setup uses in-memory SQLite, which cannot parse Postgres-specific `ALTER TABLE ... CHECK` and `::jsonb` syntax.

Risk: fast tests do not prove PostgreSQL constraint behavior.

Return plan: keep PostgreSQL migration verification in local Docker and add PostgreSQL integration tests before relying on DB constraints for acceptance.

### Backend Uses File Sessions Until Session/Auth Migration Strategy Is Final

Specification target: same-origin sessions with Laravel auth/session tables.

Current implementation: local backend `.env` uses `SESSION_DRIVER=file` during bootstrap.

Reason: early health endpoints ran before `sessions` table migrations were introduced.

Risk: session behavior may differ from final DB-backed or Sanctum-backed setup.

Return plan: decide session storage as part of auth hardening and update `.env.example`, tests, and deployment config together.

Resolution (Step 39, 2026-10-09): resolved. `SESSION_DRIVER=database` (Laravel `sessions` table) in `.env.example` and local `.env`; tests keep the `array` driver. `SESSION_SECURE_COOKIE=false` is a local-only default and must be `true` in any HTTPS deployment.

### Store Verification API Issues Challenges Without External Proof Check

Specification target: domain verification proves exact hostname ownership through connector/plugin challenge or DNS before a store is considered verified.

Current implementation: `/api/v1/stores/{store}/verify` creates a pending challenge and `/api/v1/stores/{store}/verification` reads latest state. It does not yet perform DNS TXT lookup, connector/plugin challenge fetch, store `verified_at` update, or activation.

Reason: this D1 slice establishes the database shape, routing, tenant scoping, role checks, and safe DTO behavior before adding network-dependent verification workers.

Risk: the current endpoint must not be interpreted as proof of domain ownership.

Return plan: add DNS/connector external checks, explicit failure reason codes, and store activation rules before enabling browser checks or integrations.

### Pairing Exchange Does Not Yet Include Full Abuse And Keyring Controls

Specification target: pairing has code attempt limits, IP-level rate limiting, audit trail, production keyring-backed credential encryption, and HMAC/nonce verification before accepting connector traffic.

Current implementation: pairing code creation and exchange are implemented with hashed one-time codes, per-code attempt tracking, base URL binding, encrypted secret storage through Laravel encryption, and one-time secret reveal. HMAC middleware and cache-backed nonce replay protection were added in Step 13. IP-level rate limiting, audit events, rotated-key draining, and production keyring strategy are not implemented yet.

Reason: this D1 slice establishes durable pairing tables, at-most-once exchange, and credential bootstrap before signed ingestion endpoints exist.

Risk: the exchange and signed heartbeat endpoints are not yet production-hardened against distributed abuse and must not be exposed publicly without edge/app rate limiting.

Return plan: add route throttle policies, audit logging, deployment keyring design, rotated-key draining, and durable event ingestion before enabling connector ingestion beyond local bootstrap.

Resolution (Step 40, 2026-10-09): resolved for the plugin HMAC credential.
- `POST /pairing/exchange` throttled at 20/hour per IP (`pairing-exchange` limiter); per-code 5 attempts unchanged. Failed exchanges for a known code (consumed/expired/attempts exceeded/base URL mismatch) write `integration.pairing_failed` audit rows without the code itself.
- Keyring: `App\Support\Security\Keyring` encrypts with `WATCHDOG_KEYRING_CURRENT` and decrypts by the stored `key_version`. Version 1 is `APP_KEY` with the app cipher (keeps existing ciphertexts readable); versions ≥2 come from `WATCHDOG_KEYRING_KEYS` and use AES-256-GCM. Key material stays in the environment, never in the DB. `security:reencrypt-secrets` moves credentials and notification destinations to the current version; `/health/ready` reports `keyring`.
- Rotation: `POST /integrations/{id}/rotate` (admin, Idempotency-Key) only flags the request; the heartbeat response then carries `credential_rotation_requested: true`; the plugin calls signed `POST /ingest/credentials/rotate` and receives the new secret once. The signing key becomes `draining` for 24 h, any never-used active key is revoked, and the first request signed with the new key revokes the draining key (`integration.credential_drained`).
- Still open: the plugin-side implementation (plugin is a placeholder), Stripe API/webhook credential rotation, nonce store fail-closed behaviour has no dedicated test.

### Event Ingress Uses Envelope Validation Before Full JSON Schema Validation

Specification target: `/api/v1/ingest/events` validates signed raw body, batch limits, event schema, semantic rules, and persists invalid semantic records to the correct durable state.

Current implementation: Step 14 commits valid signed events into `event_inbox`, handles identical duplicates and event ID conflicts, and validates required envelope fields, schema version, event/aggregate enums, timestamps, and batch count. It does not yet run full JSON Schema Draft 2020-12 validation against `contracts/event.schema.json`, enforce the 1 MiB body limit, persist quarantined semantic failures, or start projection/outbox processing.

Reason: this slice establishes durable at-least-once intake and idempotency before adding schema engine, projection workers, and operational throttles.

Risk: some structurally invalid event `data` payloads may be accepted until full schema validation is added.

Return plan: add a JSON Schema validator dependency or generated validator, request-size limit enforcement, quarantine persistence, and projection/outbox worker before using ingress for production data.

### Projection Event FKs Are Simplified During Bootstrap

Specification target: projection tables reference `event_inbox` through tenant/store scoped composite keys where applicable.

Current implementation: Step 20 uses a single-column nullable FK from `order_revisions.event_id` to `event_inbox.id`. Step 23 uses the same single-column nullable FK pattern from `financial_transactions.source_event_id` to `event_inbox.id`.

Reason: the reference composite FK uses `ON DELETE SET NULL (event_id)` semantics, while Laravel's portable schema builder does not express column-specific `SET NULL` for a composite FK cleanly across PostgreSQL and SQLite test migrations.

Risk: the database does not independently prove that projection `tenant_id/store_id` values match the referenced inbox event; application code currently writes scoped values from the same `EventInbox` row.

Return plan: replace the simplified FK with PostgreSQL-specific DDL for the exact composite constraint, or make the scoped event reference enforceable through an additional nullable scoped key design, before projection tables are considered production-complete.

## Tracking

Related docs:

- `docs/progress.md`
- `docs/implementation-step-01-checklist.md`
- `docs/implementation-step-06-checklist.md`
- `docs/implementation-step-11-checklist.md`
- `docs/implementation-step-12-checklist.md`
- `docs/implementation-step-13-checklist.md`
- `docs/implementation-step-14-checklist.md`
