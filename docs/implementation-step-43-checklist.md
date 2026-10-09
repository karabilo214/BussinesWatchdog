# Implementation Step 43 Checklist: Scoped Projection FKs And PostgreSQL Test Runs

Stage: ADR 0001 gap closure (database)
Spec references: `spec/database/schema.sql` (order_revisions / financial_transactions event FKs), `spec/Business-Watchdog-TZ.md` sections 25–27 (tenant isolation, constraints).
Closes: ADR 0001 "Test Migrations Use SQLite-Compatible Fallbacks" and "Projection Event FKs Are Simplified During Bootstrap".

## Scope

- [x] Whole suite run on PostgreSQL 18 (`business_watchdog_test`); `make backend-test` (SQLite) and `make backend-test-pgsql` targets.
- [x] Real bug found by the PostgreSQL run and fixed: idempotency reservation caught a unique violation inside the request transaction (aborts the PG transaction → 500 on replay). Now `insertOrIgnore` (`ON CONFLICT DO NOTHING`).
- [x] Migration replacing simplified event FKs with scoped composite FKs `ON DELETE SET NULL (column)` (PostgreSQL only); verified migrate → rollback → migrate.
- [x] `PostgresConstraintsTest` (PG-only): cross-tenant event reference rejected, deleting an inbox event clears only the reference, CHECK constraints (incident state, currency format, store status), partial unique active incident per fingerprint.

## Verification

- [x] SQLite: 263 tests, 259 passed, 4 PostgreSQL-only skipped.
- [x] PostgreSQL 18: 263 tests passed (also via `make backend-test-pgsql`).
- [x] Migration applied to the local dev database.

## Not Done In This Step

- CI pipeline running both databases (no CI configured in the repo yet).
