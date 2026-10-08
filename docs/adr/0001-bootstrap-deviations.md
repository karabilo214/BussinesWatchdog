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

### Store Verification API Issues Challenges Without External Proof Check

Specification target: domain verification proves exact hostname ownership through WordPress challenge or DNS before a store is considered verified.

Current implementation: `/api/v1/stores/{store}/verify` creates a pending challenge and `/api/v1/stores/{store}/verification` reads latest state. It does not yet perform DNS TXT lookup, WordPress challenge fetch, store `verified_at` update, or activation.

Reason: this D1 slice establishes the database shape, routing, tenant scoping, role checks, and safe DTO behavior before adding network-dependent verification workers.

Risk: the current endpoint must not be interpreted as proof of domain ownership.

Return plan: add DNS/WordPress external checks, explicit failure reason codes, and store activation rules before enabling browser checks or integrations.

## Tracking

Related docs:

- `docs/progress.md`
- `docs/implementation-step-01-checklist.md`
- `docs/implementation-step-06-checklist.md`
- `docs/implementation-step-11-checklist.md`
