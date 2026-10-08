# Implementation Step 32 Checklist: Reconciliation Runs And Findings Foundation

Stage: D4 reconciliation foundation
Spec references: `spec/Business-Watchdog-TZ.md` sections 12, 14, 25.
Acceptance references: `ACC-29`, `ACC-31`, `ACC-32`, `ACC-33`.

This step adds the `reconciliation_runs`/`reconciliation_findings` tables and a per-order evaluation service that applies the core G/C/RW/RP money rules from section 12/14 on top of the allocation primitives from Steps 30–31.

## Scope

- [x] Add `reconciliation_runs` and `reconciliation_findings` tables, mirroring `spec/database/schema.sql` (composite tenant/store FKs, pgsql CHECK constraints, `findings_recent_idx`).
- [x] Add `ReconciliationRun` and `ReconciliationFinding` models with status/rule-code constants.
- [x] Add `OrderReconciliationService::evaluate(Order $order)`:
  - [x] Locks the order row and the relevant active allocation rows before computing sums (consistent read).
  - [x] `MONEY_UNSUPPORTED` when `orders.financial_support !== 'supported'`; short-circuits the other rules for that order.
  - [x] `MONEY_CAPTURE_MISSING` when the order has a Woo paid marker (`paid_marked_at`), a positive payable total, and zero active capture allocations; `pending` within a 30-minute grace from `paid_marked_at`, `mismatch` after.
  - [x] `MONEY_CAPTURE_AMOUNT` when there is a non-zero captured amount; `ok`/`mismatch` against the order total at zero tolerance.
  - [x] Skips capture findings entirely when there is no paid marker and nothing captured yet (nothing to reconcile).
  - [x] `MONEY_REFUND_MISSING` / `MONEY_REFUND_EXTRA` from Woo refunds marked `external_required` (RW) vs. active refund allocations (RP); `pending`/`mismatch` after a 60-minute grace; `ok` (under `MONEY_REFUND_MISSING`) when RW equals RP; skipped when both are zero.
  - [x] Bookkeeping-only Woo refunds (`external_required = false`) are correctly excluded from RW, so a manual refund is never counted as a verified provider refund (ACC-32).
  - [x] Every run is persisted with `status`, `algorithm_version`, `config_version`, `scope`, `coverage_snapshot`, `counters`, timestamps.
  - [x] Findings are append-only: replaying evaluation creates a new run with new findings; history is never overwritten.
- [x] Focused tests: capture missing pending/mismatch, capture amount ok/mismatch, no-op when nothing to reconcile, unsupported short-circuit, refund missing pending/mismatch, refund extra from a bookkeeping-only Woo refund, refund reconciled ok, and replay preserving finding history across two runs.

## Verification

- [x] `php -l` on all changed/added files (PHP 8.4).
- [x] `php vendor/bin/pint --test` on changed/added files: clean.
- [x] Focused reconciliation service tests: 10 tests, 32 assertions.
- [x] Full `php artisan test` (PHP 8.4): 114 tests passed, 369 assertions.
- [x] `php artisan migrate --force` against real PostgreSQL 18 (local Docker `postgres` service) applied cleanly, including the pgsql-only CHECK constraints.

## Not Done In This Step

- Public/manual reconciliation API (`POST /stores/{id}/reconciliations`, `GET /stores/{id}/findings`).
- Dirty-order coalescing (30s), grace-deadline re-check scheduling, and nightly 90-day sweep — this step is on-demand/synchronous per order only.
- `MONEY_PAYMENT_WITHOUT_ORDER`, `MONEY_MULTIPLE_CAPTURES`, `MONEY_CURRENCY_MISMATCH`, `MONEY_ORDER_CHANGED` rules — these need store-wide/payment-wide scanning (unmatched payments, per-payment capture counts, order-revision diffing) rather than a single-order evaluation, deferred to a follow-up step.
- `rule_configs` integration for versioned/tenant-configurable tolerance and grace windows — currently fixed constants (`tolerance=0`, `grace_capture=30m`, `grace_refund=60m`, `algorithm_version=v1`, `config_version=1`) on the service.
- Run-row persistence on mid-evaluation failure: `evaluate()` runs inside a single DB transaction, so a failure rolls back the run record too instead of leaving a `failed` run visible for diagnostics. Acceptable for this synchronous on-demand foundation; revisit once this is wired into the scheduler/nightly sweep.
- Incident engine correlation (`critical` severity escalation, auto-resolve) — this step only produces findings, not incidents/notifications.
