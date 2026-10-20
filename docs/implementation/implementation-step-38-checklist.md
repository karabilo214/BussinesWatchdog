# Implementation Step 38 Checklist: Scheduler And Dirty-Order Reconciliation

Stage: D5 background processing
Spec references: `spec/Business-Watchdog-TZ.md` sections 14 (schedule), 28 (outbox/sweeper), 31 (scheduler leader lock, due windows), 27 (cross-tenant scheduler only finds IDs).
Acceptance references: supports `ACC-09` (recovery after crash) and keeps `ACC-26`/`ACC-27`/`ACC-28` flows running without manual triggers.
Decisions: `docs/adr/0003-scheduler-and-dirty-reconciliation.md`. ADR 0002 marked as owner-accepted.

## Scope

- [x] Migration: `reconciliation_dirty_subjects` (unique per tenant/type/subject, composite store FK, type CHECK) and `scheduled_job_windows` (unique job/window, status CHECK).
- [x] `DirtySubjectMarker`: 30 s coalescing, `mark_version` bump, never postpones an earlier `due_at`; bulk insert-if-absent for the sweep.
- [x] Marking inside existing transactions: `EventInboxProcessor` via `EventDirtyMarker` (order/refund events → order; payment/transaction events → allocated orders + store scan), `PaymentAllocationService` create/revoke (order + store scan).
- [x] `DirtySubjectProcessor` + `php artisan reconciliation:process-dirty`: DB leases, tenant-scoped evaluation, incident correlation (so notifications follow automatically), delete-or-release by `mark_version`, backoff on failure, skip for vanished subjects.
- [x] Grace re-checks: `evidence.grace_deadline_at` on pending capture/refund findings; `GraceRecheckScheduler` re-marks orders and the store scan at the deadline — from the processor and from `POST /stores/{id}/reconciliations`.
- [x] `NightlyReconciliationSweep` + `php artisan reconciliation:nightly-sweep`: 90-day lookback, active stores/tenants only, once per UTC day through `ScheduledWindowGuard` (failed/stale windows retakeable).
- [x] `routes/console.php`: `outbox:dispatch` (10 s), `reconciliation:process-dirty` (30 s), `notifications:deliver` (30 s), `reconciliation:nightly-sweep` (02:30 UTC), all `withoutOverlapping()->onOneServer()`.
- [x] `docker-compose.yml`: `scheduler` service running `schedule:work` (profile `app`).

## Verification

- [x] `php -l` on all new/changed files (PHP 8.4).
- [x] `php vendor/bin/pint` on new/changed files (also normalized pre-existing style in the touched `EventInboxProcessor`, `PaymentAllocationService`, `DomainOutboxDispatcherTest`); `DomainOutboxDispatcher.php` still left as at HEAD.
- [x] Full `php artisan test` (PHP 8.4): 228 tests passed, 789 assertions (15 new).
- [x] `php artisan migrate --force` against real PostgreSQL 18 (local Docker) applied the new migration cleanly.
- [x] Smoke run of all four commands against the local PostgreSQL (empty data): each completed, second nightly sweep on the same day was skipped.
- [ ] `schedule:work` inside the Docker `scheduler` container not started in this step (host PHP has no Redis extension; `schedule:list` verified with `CACHE_STORE=array`).

## Not Done In This Step

- Per-tenant fairness when claiming dirty subjects; dead letter for subjects failing repeatedly.
- Rate limiting of on-demand reconciliation API.
- Stale-integration detection (missed heartbeats) and coverage/unknown signals from the scheduler.
- Domain-outbox sweeper cadence beyond what `outbox:dispatch` already does on every tick.
- Cleanup jobs (`idempotency_keys` expiry, old `scheduled_job_windows`).
