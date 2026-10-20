# Progress: Projections, reconciliation and incidents

Order/refund/payment/transaction projections, allocations, reconciliation rules and runs, incident engine, scheduler and dirty-order processing, provider coverage, connector freshness, parallel sync and reconciliation at scale.

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

### Step 155: Provider Capture Not Recorded by Store (Spec §10, §14, §43.1; ACC-29)

The Stripe test-mode chain showed that an independently confirmed capture with no WooCommerce paid marker produced `MONEY_CAPTURE_AMOUNT:ok` alone. Reconciliation v2 adds `MONEY_CAPTURE_NOT_RECORDED`: pending for 30 minutes after the earliest linked provider capture, mismatch afterwards, and ok once the store records payment. The incident has its own component so an amount-ok finding cannot accidentally resolve it; it auto-resolves only on the store-state finding becoming ok. The result remains unknown if provider coverage or store freshness is missing. Customer and owner labels plus notification facts were added in ru/en/de. No migration or API schema change was needed: finding codes are strings in the existing tables and OpenAPI. This is a targeted reconciliation rule; the broader §43 obligation engine, deadlines across fulfillment/email providers, and full FIN registry remain open.

### Step 162: Financial Mode Isolation (ADR 0018; Spec §24; ACC-29, ACC-35)

Findings and money incidents now record the reported provider mode (`live`, `test`, or null when unverified). The reconciliation result hash includes mode, and the incident fingerprint includes mode, so repeated findings and resolution cannot cross modes. The overview groups active verified discrepancy amounts by mode, currency and component; the customer overview and incident screens label the mode explicitly. Existing linked findings and incidents are backfilled from order/payment mode during migration; an order whose connector marked `mode_reported=false` stays unverified, as do historical incidents with mixed evidence. The API and schema contract expose mode. SQLite and PostgreSQL tests cover separation and unreported mode.

### Step 200: FIN-01…24 Registry Through the Real Path (ADR 0068 proposed)

Status: complete as a registry; 6 deviations wait for the owner's decision (ADR 0068)

- `tests/Feature/Reconciliation/FinRegistryTest.php` reads `spec/examples/reconciliation-cases.json` and runs each of the 24 control examples of TZ §35.1 end to end. The store order/refund snapshots go through `EventIngestor` from a store connector; the Stripe objects go through `StripeSync` with HTTP faked by JSON fixtures (no real Stripe); then the outbox/projections, `ExactReferenceMatcher`, `OrderReconciliationService` and `MoneyIncidentCorrelator`. From the findings the test derives the fixture's terms: the result, the capture/refund/net differences of §12 (final findings only) and whether a money incident with an amount was published.
- Result: 18 of 24 match. Deviations (kept in the test's `DEVIATIONS` list, which fails as soon as one starts to match):
  - FIN-08: a provider refund without a store refund is never allocated, so it is invisible (no MONEY_REFUND_EXTRA);
  - FIN-15: an unmatched reference is published as MONEY_CAPTURE_MISSING with an amount;
  - FIN-17: a store refund with unknown `external_required` is ignored;
  - FIN-19: a cancelled paid order without a refund is ok, with no review result;
  - FIN-20: after a total change MONEY_CAPTURE_AMOUNT is published, and ORDER_CHANGED carries an amount;
  - FIN-21: a currency mismatch also publishes MONEY_CAPTURE_MISSING with an amount.
  The older unit tests did not show these because they create allocations by hand.
- `docs/reconciliation-registry.md`: the table per example. ADR 0068 (proposed): a fix for each deviation, with choices for the owner on FIN-15 and FIN-19. `spec/ACCEPTANCE.md` ACC-29 evidence updated (still partial), `docs/readiness.md`.
- The registry test skips itself when `spec/` is not mounted (container runs). The reconciliation code was not changed.

Verification: `FinRegistryTest` 25/25 (24 examples + coverage check). Backend SQLite 660 passed + 4 skipped, PostgreSQL 18 664/664, Pint ok.

### Step 201: Reconciliation Rules v3 — the FIN Registry Matches 24 of 24 (ADR 0068)

Status: complete; ADR 0068 accepted by the owner on 2026-10-20 ((b) for FIN-15 and FIN-19); not deployed yet

- `OrderReconciliationService` → `ALGORITHM_VERSION` v3:
  - FIN-15: a paid order with C = 0 past the grace, whose reference/metadata matches no provider payment, is `MONEY_PAYMENT_NOT_MATCHED` (mismatch, no amount). MONEY_CAPTURE_MISSING with the amount stays for a known payment without a capture.
  - FIN-21: with the referenced payment in another currency, MONEY_CAPTURE_MISSING is `unknown` (`currency_mismatch`); only MONEY_CURRENCY_MISMATCH remains.
  - FIN-20: when the total changed after capture, MONEY_CAPTURE_AMOUNT is `unknown` (`order_changed_after_capture`), keeping the difference as a fact.
  - FIN-19: new `MONEY_CANCELLED_PAID` — a cancelled order with a paid marker, a capture and no store or provider refund, after the 60-minute refund grace; an ok finding is written only once such a finding existed, so the incident can close.
  - FIN-08: RP also counts the part of a succeeded provider refund that no store refund claims, on a payment allocated to this order alone.
  - FIN-17: a store refund with `external_required = null` makes the refund result `unknown` (`refund_external_unknown`).
- `MoneyIncidentCorrelator`:
  - `MONEY_PAYMENT_NOT_MATCHED` → component `capture`, severity warning; `MONEY_CANCELLED_PAID` → component `cancelled_paid`, severity warning (owner raised it from info, so default channels are notified).
  - No-amount rules (not matched, order changed, cancelled paid) never carry a verified discrepancy, also when they replace an amount in the same incident.
  - An `unknown` result with `order_changed_after_capture` / `currency_mismatch` withdraws an amount an earlier version published (incident stays open, no notification).
- `php artisan reconciliation:recheck-open-incidents`: queues every order behind an open money incident once, after the release (`docs/ops/maintenance.md`).
- Texts: notification facts and «what to check» ru/en/de; rule labels, incident titles and actions in the cabinet and the owner panel; the cabinet's rule filter lists both new codes.
- Tests: `FinRegistryTest` has no deviations left (24/24). `MoneyIncidentCorrelatorTest` covers: the unmatched warning without an amount replacing an earlier amount; the cancelled-paid review closed by a refund; order changed without an amount; amount withdrawal plus the recheck command. Test order helpers now give paid orders a known provider payment (`tests/Concerns/KnownProviderPayment`), since a paid order without one is now «not matched».

Verification: backend SQLite 664 passed + 4 skipped, PostgreSQL 18 668/668, Pint ok; customer cabinet 134/134 and owner panel 67/67 with typecheck and builds. Stripe only through JSON-faked HTTP; no real provider in this step.

### Step 208: Amounts and Provider Transaction Ids for «Capture Not Recorded» (ADR 0071)

Status: complete in main; ADR 0071 accepted by the owner on 2026-10-20; not deployed yet

Found in the live Shopware run (Step 206): MONEY_CAPTURE_NOT_RECORDED findings and incidents showed «unknown» in every amount column and had no incident amount.

- `OrderReconciliationService`: MONEY_CAPTURE_NOT_RECORDED and MONEY_CANCELLED_PAID fill `expected_minor` (order total) and `actual_minor` (captured at the provider); the difference stays empty. Capture-related findings (capture amount, capture not recorded, multiple captures, cancelled paid, refund missing/extra) keep `evidence.capture_operation_ids` — the provider's capture ids.
- `MoneyIncidentCorrelator`: the incident amount of MONEY_CAPTURE_NOT_RECORDED is the captured amount, so it counts in the money at risk.
- Customer cabinet `FindingsTable`: the provider transaction under the check name; for «capture not recorded» the difference column states the store state in words; «—» instead of «unknown» in rules without an amount by meaning (unknown/unsupported results still say «unknown»). The incident card now shows the status of each finding.
- Tests: `OrderReconciliationServiceTest`, `MoneyIncidentCorrelatorTest`, `ShopwareIntegrationTest` (evidence compared order-independently for PostgreSQL), customer `reconciliation.test.ts`, `incidents.test.ts`.

Verification: backend SQLite 676 passed + 4 skipped, PostgreSQL 18 680/680; customer vitest 140/140, vue-tsc ok.
