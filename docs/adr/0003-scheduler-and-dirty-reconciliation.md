# ADR 0003: Scheduler And Dirty-Order Reconciliation (Step 38)

Date: 2026-10-09

Status: accepted for the D5 background-processing slice.

Section 14 ("Расписание: dirty orders coalesce 30 секунд; повтор при grace deadline; nightly sweep recent 90 дней") and section 31 ("Scheduler должен иметь leader lock и unique due window keys") are implemented as follows.

## Decisions

### 1. Dirty subjects are a durable table (schema addition)

`spec/database/schema.sql` mentions `dirty_order` only as an emitted signal. Step 38 adds `reconciliation_dirty_subjects` with one row per `(tenant_id, subject_type, subject_id)`:

- `subject_type = order` — one order to re-evaluate;
- `subject_type = store_unmatched_payments` — the store-wide `MONEY_PAYMENT_WITHOUT_ORDER` scan.

A mark inserts the row with `due_at = now + 30s`, or bumps `mark_version` on the existing row and keeps the earlier `due_at` (coalescing never postpones a pending check). The processor leases due rows, evaluates, correlates incidents, and deletes the row only if `mark_version` did not change during processing; otherwise it releases it for another pass.

Marks are written inside the same transaction as: event projection (`EventInboxProcessor`), allocation create/revoke (`PaymentAllocationService`). Payment and transaction events mark every order that has an active allocation on that payment, plus the store scan.

### 2. Grace re-checks

Pending findings now carry `evidence.grace_deadline_at`. After every evaluation (scheduled or API-triggered) the earliest deadline re-marks the order with that `due_at`. For the store scan the deadline is the earliest unallocated capture still inside `ORPHAN_GRACE_HOURS` plus that grace.

### 3. Nightly sweep

`reconciliation:nightly-sweep` runs at 02:30 UTC and marks (insert-if-absent) every order whose `source_created_at` or `source_updated_at` is within 90 days, plus the store scan, for stores in `onboarding|active|degraded` of `active` tenants. **Paused and deleted stores are skipped by the sweep**; their incoming events still mark orders dirty. The sweep only finds IDs across tenants; evaluation runs per subject inside its tenant scope.

### 4. Leader lock and due windows

- `scheduled_job_windows (job, window_key)` unique: the nightly sweep uses the UTC date as window key, so a repeated tick, a second scheduler or a manual run on the same day is a no-op. A `failed` window, or one stuck `running` for more than 120 minutes, may be taken over.
- Per-minute jobs (`outbox:dispatch` every 10 s, `reconciliation:process-dirty` and `notifications:deliver` every 30 s) are safe to overlap because each claims rows with DB leases; Laravel `withoutOverlapping()` + `onOneServer()` (Redis cache locks) are an additional, non-authoritative guard. If Redis is down the DB leases still prevent duplicate processing.
- Docker Compose gets a `scheduler` service (`php artisan schedule:work`, profile `app`).

### 5. Failure handling

A subject whose evaluation throws is released with `error_code = dirty_subject_processing_failed` and exponential backoff (1 min doubling, max 1 h); the exception is reported to the log. There is no dead letter yet.

## Not covered

- Per-tenant fairness in claiming (rows are claimed by `due_at` only).
- Rate limiting of the on-demand API trigger.
- Stale-integration (heartbeat) and coverage checks feeding the scheduler.

## Tracking

- `docs/implementation-step-38-checklist.md`
- `docs/progress.md` (Step 38)
