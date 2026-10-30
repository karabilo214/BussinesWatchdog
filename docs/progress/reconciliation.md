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
- `docs/implementation/implementation-step-20-checklist.md`.

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
- `docs/implementation/implementation-step-21-checklist.md`.

Verification:

- PHP syntax checks passed for order deleted projector, inbox processor, and dispatcher tests.
- User ran `php artisan test`; 77 tests passed with 252 assertions.

Remaining:

- Add refund/payment/transaction/allocation projections, same-revision hash conflict handling, full JSON Schema validation, reconciliation rules, financial cleanup semantics, and projection-specific idempotency tests.

### Step 22: Order Deleted Tests Complete

Status: complete

Added:

- Recorded the completed Docker test run for Step 21.
- `docs/implementation/implementation-step-22-checklist.md`.

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
- `docs/implementation/implementation-step-23-checklist.md`.

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
- `docs/implementation/implementation-step-24-checklist.md`.

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
- `docs/implementation/implementation-step-25-checklist.md`.

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
- `docs/implementation/implementation-step-30-checklist.md`.

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
- `docs/implementation/implementation-step-31-checklist.md`.

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
- `docs/implementation/implementation-step-32-checklist.md`.

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
- `docs/implementation/implementation-step-33-checklist.md`.

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
- `docs/implementation/implementation-step-34-checklist.md`.

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
- `docs/implementation/implementation-step-35-checklist.md`.

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
- `docs/implementation/implementation-step-36-checklist.md`.

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
- ADR 0002 marked accepted by the owner as-is; `docs/adr/0003-scheduler-and-dirty-reconciliation.md`; `docs/implementation/implementation-step-38-checklist.md`.

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
- `docs/implementation/implementation-step-48-checklist.md`; ADR 0006 updated.

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
- `docs/adr/0009-connector-freshness.md`, `docs/implementation/implementation-step-50-checklist.md`.

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

### Step 209: PayPal Refunds Made From the Store Are Read at Once; Order Number in Money Incident Titles

Status: complete in main

Found in the live Shopware run (Step 206): a partial refund of order 10003 made in the Shopware admin was completed at PayPal (refund 120.00 €), but the service did not see it — Transaction Search lags up to hours and the pointer path only read captures it did not know yet. After the refund grace the order would have got a false MONEY_REFUND_MISSING.

- `PayPalSync::syncPointers`: an order with a known capture is read again while its latest MONEY_REFUND_MISSING is pending or mismatch (within the pointer window, with the same retry interval). A refund PayPal has shown stops the re-reads.
- `IncidentDto`: money incidents carry `order_id` and `order_display_number` of the latest linked finding (`contracts/openapi.yaml`); the cabinet shows «<title> — заказ 10004» in the list and on the incident page.
- Tests: `PayPalIntegrationTest` (fails without the fix), `IncidentApiTest`, customer `incidents.test.ts`.

Verification: backend SQLite 677 passed + 4 skipped, PostgreSQL 18 681/681; customer vitest 141/141, vue-tsc ok.

### Step 210: Store Payment State, «Payment Failed, Order Open», Payments Without Order Since Connection (ADR 0072)

Status: complete in main; ADR 0072 accepted by the owner on 2026-10-20

Found in the live Shopware run (Step 206): order 10005 (PayPal refused, shop «open / in progress») raised nothing and was invisible to the customer; the owner panel showed only the order status; «payments without order» of a new store listed the PayPal account's older history.

- `order.snapshot` optional `payment_state` (`contracts/event.schema.json` and the backend copy, `EventPayloadValidator`, `OrderSnapshotProjector` → `metadata.payment_state`, `Order::PAYMENT_STATES`); `ShopwareOrderMapper` maps the transaction state. `OrderDto` and `contracts/openapi.yaml` expose it.
- `OrderReconciliationService`: MONEY_PAYMENT_FAILED_ORDER_OPEN (grace 120 minutes, expected = order total, found = 0); provider payments of an order are found by intent/charge reference, by a provider operation with the store's transaction reference, or by the provider order reference. `MoneyIncidentCorrelator`: component `payment_failed_order_open`, amount = order total.
- `UnmatchedPaymentScanner::since()` and the unmatched-payments list: only captures since the store was created.
- Customer cabinet: rule name, title, advice, the difference in words. Owner panel: «Order status» and «Payment status» columns and in the order details.
- Tests: `OrderReconciliationServiceTest` (pending → mismatch → ok, store marked failed, another capture), `UnmatchedPaymentScannerTest`, `ShopwareIntegrationTest`, fixtures with backdated stores; customer `reconciliation.test.ts`, control `tenants.test.ts`.

Verification: backend SQLite 680 passed + 4 skipped, PostgreSQL 18 684/684; customer vitest 142/142, control vitest 69/69, vue-tsc ok, Pint ok.

### Step 211: Which Store a Provider Payment Belongs To (ADR 0073)

Status: complete in main; ADR 0073 accepted by the owner on 2026-10-21

Found in the live run: Woo-Test and Shopware-Test share a PayPal sandbox account, so each saw the other's payments (they would become payments without order).

- `PayPalOrderMapper`: `provider_store_transaction_ref` (SwagPayPal `custom_id` JSON) and `provider_invoice_ref`; `ShopwareOrderMapper`: `store_transaction_ref`; schema (`contracts/event.schema.json` + backend copy), `EventPayloadValidator`, projectors.
- `ExactReferenceMatcher`: SwagPayPal payments link to the order with the same Shopware transaction id (`verified_metadata`), also when the order arrives later; `OrderReconciliationService` finds the order's provider payments by it too.
- `StorePaymentAttribution` + migration `payments.foreign_store_reason` (`spec/database/schema.sql`): other store's Shopware transaction, other site origin, other invoice prefix. `UnmatchedPaymentScanner` skips them; `GET /stores/{id}/unmatched-payments?scope=other_stores` lists them (`contracts/openapi.yaml`); the cabinet shows a collapsed «Payments of other stores on this account».
- Tests: `UnmatchedPaymentApiTest` (three reasons, own site and unmarked stay), `PayPalIntegrationTest` (SwagPayPal link before the capture id), `ShopwareIntegrationTest`; customer `reconciliation.test.ts`.

Verification: backend SQLite 682 passed + 4 skipped, PostgreSQL 18 686/686; customer vitest 143/143, vue-tsc ok, Pint ok.

### Step 232: Incidents Explained for the Store Owner

Status: complete; deployed 2026-10-22

- Goal (owner): an incident card that says what is established, what is not known yet and where to start — built from the findings, orders, amounts, provider ids and check results the service already has, not a new system. No AI: templates by rule, reason, connector and data completeness.
- `IncidentExplainer` (backend) returns codes and values only; `GET /api/v1/incidents/{id}` carries it as `explanation` (OpenAPI updated). Five scenarios:
  - **capture_unconfirmed** (`MONEY_CAPTURE_MISSING`, `MONEY_PAYMENT_NOT_MATCHED`): order, paid mark, payment reference (from the order or missing), the provider payment by that reference and its state, capture not confirmed, last provider sync, completeness. Causes fit the case (authorized but not captured, still processing, declined yet marked paid, marked paid early, other id, no id, incomplete provider data only when it is incomplete). Steps differ with and without a reference; lost provider access puts «restore access» first.
  - **capture_not_recorded**: the confirmed capture (amount, operation id), no paid mark, the store payment state; never charge again.
  - **refund_unconfirmed**: store refund (amount or state only), provider refund, and the connector's ability: with `provider_refunds_synced = false` (Stripe Payment for Shopware, ADR 0025) the step is «refund in Stripe» instead of «send it through the store»; never a second refund automatically.
  - **browser_check_stopped** (`CHECKOUT_FLOW_FAILED`): stopped step, last step passed, reason, screenshot available, last passed check, a link to the run.
  - **source_unavailable** (connector freshness): source, reason, last signal, last successful sync; causes and steps by reason (rejected key → replace the key).
- Problem and data are separate: `status` detected / unverifiable (found earlier, cannot be re-checked now, e.g. provider access lost) / resolved_confirmed / closed_by_user (closing by hand is not a confirmation); `observation` fresh / partial / unavailable with the reason and the last update; `outcome` what the last check changed (confirmed and closed automatically, still present, could not re-check, closed by a user).
- Amounts are left out when the owner hides them or they are unknown — the wording changes (`order_only`, `confirmed_no_amount`), no empty gaps. `IncidentExplainer::TEXT_CODES` lists every cause/step/warning/closing code.
- Cabinet: `IncidentExplanation.vue` replaces the single «Что проверить» advice for these scenarios (others keep the advice): headline, established fact («расхождение, а не потеря денег»), problem vs data state, outcome, evidence table, possible causes marked as not established, numbered steps, warning, closing condition, technical details collapsed. Texts ru/en/de under `incident.explain` (212 keys each).
- E-mail/Telegram: «С чего начать: …» — the explanation's first step worded for the case (payment id, provider) — instead of the general «Что проверить» line; a silent connector keeps the general advice (site, plugin and scheduler at once) unless the key was rejected. Template version `incident.v2`.

Verification: `IncidentApiTest` — capture with a known reference (evidence, only the fitting cause, steps, observation, technical ids), without a reference and with lost provider access (unverifiable, restore access first), closed by hand and hidden amounts, refund with a payment app without refunds, a silent source with a rejected key, a stopped browser check; every returned code is in `TEXT_CODES`. `IncidentNotificationFlowTest` checks the first step in the e-mail; `ConnectorFreshnessTest` keeps the scheduler hint. Customer `incidents.test.ts` — texts with and without amount/provider, problem vs data state, manual close, browser step and run link, every `TEXT_CODES` entry has ru/en/de. Backend SQLite 722 passed + 4 skipped, Incidents + Notifications on PostgreSQL 18 84/84, Pint ok; customer typecheck, 157/157, build. Not checked in a real browser before deploy.

Production (2026-10-22, `23bd828` + `7863646`): explanations of the test stores' incidents build without errors — auto-resolved captures read «resolved, confirmed by new data» with the reason, a manual close «closed by a user», open ones «still present». Follow-up deployed: once a fix is confirmed the card hides causes and steps (a manual close keeps them). Not covered yet and still on the general advice: `MONEY_REFUND_EXTRA` (refund only at the provider — the most frequent open incident on the test stores), `MONEY_CAPTURE_AMOUNT`, `MONEY_MULTIPLE_CAPTURES`, `MONEY_PAYMENT_WITHOUT_ORDER` and the other checkout incidents.

### Step 233: Four More Incident Explanations

Status: complete; deployed 2026-10-22

- `IncidentExplainer` covers four more money rules (texts ru/en/de, `TEXT_CODES` extended, first step in e-mail/Telegram):
  - **refund_not_recorded** (`MONEY_REFUND_EXTRA`, the most frequent open incident on the test stores): the provider refund (amount, refund operation ids from the payments linked to the order), the store refund state; «the money is already back — record it in the store without sending it again» (WooCommerce manual refund, Shopware state). A partial store refund with a full provider refund has its own cause.
  - **capture_amount** (`MONEY_CAPTURE_AMOUNT`): order total, captured amount, difference, capture operations, whether the order changed after payment; causes and steps differ for less (partial capture, next capture pending — settle without charging again) and more (refund the excess once if confirmed).
  - **multiple_captures** (`MONEY_MULTIPLE_CAPTURES`): the number and ids of separate successful captures — different operations, not repeated messages; split payments may be intended; refund a duplicate once, never automatically.
  - **payment_without_order** (`MONEY_PAYMENT_WITHOUT_ORDER`): the capture (provider, operation id, amount, time) with no order found; «the payment is not lost, only the link is missing»; link it on the unmatched payments page.
- Amounts take the finding's currency, so an explanation without an order still shows them.

Verification: `IncidentApiTest` three new tests (refund only at the provider, smaller capture and several captures, payment without an order with a real capture operation); customer `incidents.test.ts` headlines of the new scenarios. Backend SQLite 725 passed + 4 skipped, Incidents + Notifications on PostgreSQL 18 87/87, Pint ok; customer typecheck, 158/158, build.

### Без номера шага: повторная проверка открытых денежных инцидентов на боевом (2026-10-27)

- Пункт «после выкладки правил v3 (Step 201) один раз запустить `reconciliation:recheck-open-incidents`, факт запуска не записан» снят. Команда уже выполнялась 2026-10-21 при хотфиксе `b92b8f2` (6 заказов, `progress/connectors.md`, Step 222).
- 2026-10-27 09:39 UTC на `017622a` команда выполнена повторно: `Orders queued for recheck: 3 of 3 behind open money incidents`. `reconciliation:process-dirty` разобрал очередь в ту же минуту. `reconciliation_subject_states.evaluated_at` всех трёх заказов — 09:39:15–16. Хеш результата не изменился, поэтому новых `reconciliation_runs` нет, а `evaluated_at` находок остался прежним (`ReconciliationResultRecorder`).
- Открытыми остались 5 денежных инцидентов, все `test`, уровень warning: BW — `MONEY_REFUND_EXTRA` (с 2026-10-23); Shopware-Test — `MONEY_REFUND_EXTRA` (заказ 10002, с 2026-10-21, реальное расхождение возврата из Step 222); Woo-Test — `MONEY_REFUND_EXTRA`, `MONEY_MULTIPLE_CAPTURES`, `MONEY_CAPTURE_AMOUNT` по одному заказу (с 2026-10-20). По текущим правилам это действительные расхождения тестовых данных, а не следы старых правил.


### Step 323: Задача 12 — агрегаты, аудит наблюдения и первая диагностика истории продаж (2026-10-30)

Готово локально, не выложено. По команде владельца исправлены A/B/E/F задачи 12: отношения агрегатных счётчиков вместо конверсии сеансов, никаких сырых событий/хешей сеанса, 5-минутные окна и малые группы на стороне магазина, ступени 7/14/28 дней. ADR 0090, ТЗ §17 и текущие сводки согласованы с решением 2026-10-30; юридические тексты не менялись.

Начато B1: сервис `SalesHistory` и внутренняя команда `sales:history --tenant= --store= [--from= --to= --mode=live|test]`. Читает существующие проекции за ≤28 суток, считает по UTC-часам и локальным меткам магазина отдельно создание заказов, paid marker и первый подтверждённый capture на заказ через allocations. Несколько captures не дублируют заказ; store markers, fees/refunds и pending не являются capture. Учитываются synthetic/deleted/live-test, foreign-store payments, tenant/store, дубли проекций после смены источника и DST. Нет новых покупательских данных, записей, инцидентов, cron или customer API.

Принципиальная граница ADR 0091: текущая свежесть не доказывает полноту прошлого. В отчёте `coverage`/baseline unknown, прогрев unverified_history, alerts_enabled=false; нулевое число наблюдений не выдаётся за остановку продаж. Пороги и оценка потерь не выдуманы. B1 остаётся открытым до журнала покрытия/исключений, ступенчатой нормы, калибровки и детектора. Срез не включает функцию в действующие тарифы.

B2 закрыт как аудит исходников: `docs/checkout-monitoring-audit.md` описывает слои WooCommerce/Shopware, модели/контракты и пробелы. WooCommerce отправляет агрегаты, Shopware пока передаёт отдельные технические отчёты и агрегирует на backend: для нового контракта агрегация должна произойти в магазине до передачи. Новый поток с сегментами через старые отдельные reports не включён. Малые группы нельзя восстанавливать вычитанием разбивок. Живые проверки новых окон и новых версий плагинов не выполнялись.

Проверки PHP 8.4 Homebrew: `artisan test --filter=SalesHistoryTest` — 11/11, 42 assertions на SQLite и PostgreSQL 18; полный `artisan test` — 877 пройдено + 4 PostgreSQL-only пропуска (881 всего), 9147 assertions; Pint по трём новым PHP-файлам — passed. Проверены раздельный смысл paid/capture, повторные captures и смена источника, revoked/foreign/test/synthetic, tenant fail-closed, stale/paused и неизвестное историческое покрытие при свежем heartbeat, DST, `[from,to)`, невалидные даты/диапазоны и отсутствие DB writes команды. `spec/validate_spec.py`, локальные Markdown-ссылки и `git diff --check` — passed. Полный PostgreSQL suite, нагрузка и live-калибровка не запускались. Миграций и API/event-contract изменений нет; код фронтенда/плагинов не менялся.

Traceability: задача 12.A/B/E/F, ADR 0007/0090/0091, ТЗ §12/15–17/25/37, ACC-44/45/46/47/48 (новый диагностический evidence, незакрытые сценарии статистических тревог не закрываются). Ops: `docs/ops/sales-monitoring.md`. Следующий срез — историческое покрытие, исключения baseline и нормализованное определение показателей; далее контракт сегментированных окон, плагины и приём. Production, правовые документы, публикация ZIP/App не затронуты.


### Step 324: Подтверждённое покрытие истории заказов и исключения локальных дней (2026-10-30)

Готово локально, не выложено. Продолжение задачи 12.B / ADR 0090–0092: `SalesCoverage` получает доказательства от полных created_at-чтений WooCommerce recent/audit и Shopware audit. Delta и heartbeat не считаются подтверждением; partial, skipped objects и исчерпание полного прохода не добавляют интервалы. Истинная верхняя граница — начало чтения. WooCommerce after сдвинут на секунду назад при прежнем dates_are_gmt, чтобы строгая нижняя граница REST date query не теряла заказ в начале доказательства; заявленный from не расширен. Локально прочитан соответствующий исходник WooCommerce 9 на стенде, новых внешних запросов к магазинам не выполнялось.

Применение проекций — обязательный барьер: received/processing/quarantine/dead-letter блокируют новое покрытие; отдельная проверка provider_object_states ↔ orders.source_revision ловит отклонённое событие без inbox-строки. Разрешённые интервалы объединяются только без разрывов, повторы не увеличивают значения; истории до первого наблюдения не приписывается прогрев. `sales:history` остаётся read-only: v2 добавляет metric_coverage. Только orders_created может быть complete; paid-маркеры и capture остаются unknown. Кандидат baseline_eligible — не принятая статистическая норма, alerts_enabled всё ещё false.

Миграция `2026_10_30_120000_add_sales_coverage_journal`: nullable JSONB integrations.sales_coverage и stores.sales_baseline_exclusions. Новых store-owned таблиц нет: старый StoreDeletion блокирует неизвестные таблицы. Добавочные nullable-поля не мешают старым reader/writer/purge; данные удаляются вместе с прежними строками. DDL обновлён в spec/database и database/reference. API/event-контракты и внешний payload не менялись.

Локальные дни promotion/holiday/pause/data_gap имеют собственные UTC-границы/таймзону и live/test; инциденты исключают затронутые дни целиком. Текущие паузы магазина/capacity/организации/доступа фиксируются в пометках и сохраняются после возобновления; прошлые неизвестные паузы не выдумываются. Scheduler раз в минуту запускает sales:finalize-coverage (leader/overlap guards), ждёт проекции, сжимает/чистит 28-дневный журнал и пометки. Межtenant выбор — только ID, обработка в TenantContext. От владельца новых ручных серверных шагов не требуется. Customer API/кнопки акций/праздников, DE calendar и ступени нормы остаются открытыми.

Проверки PHP 8.4 Homebrew: полный `artisan test` — 891 пройдено + 4 PostgreSQL-only пропуска (895 всего), 9210 assertions. Адресный PostgreSQL 18: SalesHistoryTest/SalesCoverageTest, WooCommerceApiIntegrationTest/ShopwareIntegrationTest и OrganizationDeletionTest — 51/51, 424 assertions. Покрыты inbox/revision barrier, rejected event без inbox, отсутствующая/partial выдача, непересекающиеся интервалы и заполнение разрыва, повтор чтения, tenant isolation, режимы, DST-день 25 ч, инцидент на весь день, сохранение пауз и очистка старых пометок; HTTP-faked интеграционные тесты подтверждают вызов журнала из реальных адаптеров и финализацию после dispatcher. Pint, `spec/validate_spec.py`, Markdown-ссылки и `git diff --check` — прошли. SQL/migration/deletion проверены SQLite/PostgreSQL; production rollout/rollback и новые live-чтения/нагрузка не выполнялись. Фронтенд и плагины не менялись.

Traceability: задача 12.B, ADR 0090/0091/0092, ТЗ §15–17/25/28/34/37, ACC-44/46/48 и регрессия ACC-42; статистические ACC-45/47 не закрываются этим слоем. Ops обновлён: `docs/ops/sales-monitoring.md`. Следующее — пригодные дни и ступени прогрева, интерфейс исключений/DE calendar, затем калибровка и detector; новые оконные контракты/плагины остаются отдельными пунктами.


### Step 325: Диагностические ступени прогрева по пригодным локальным дням (2026-10-30)

Готово локально, не выложено. Задача 12.B / ADR 0093: `SalesWarmup` проверяет 28 завершённых локальных дней до конца запроса. Полный день должен иметь непрерывное покрытие после фактического первого наблюдения соответствующего источника; backfill, текущий день, пропуски и известные исключения не ускоряют прогрев. UTC-границы следуют часовому поясу магазина, 23/25-часовые дни DST дают один день. Количество пригодных дней определяет предварительную ступень collecting / stoppage_only / major_drop / full на границах 7/14/28. Остаток до следующей ступени выражен в пригодных днях, не сроке ожидания.

`sales:history` v3 добавляет `order_history_warmup` с датами, покрытием/исключениями, eligible_days и следующей границей. Начало короткого почасового отчёта не обрезает историю прогрева. Общий warmup_stage остаётся unverified_history, baseline unknown и alerts_enabled=false; даже full не разрешает детектор до календаря DE/интерфейса пометок, достаточной выборки и калибровки. Исторические paid/capture не получают покрытие заказов. Известная текущая пауза не отменяет прежние пригодные дни; сохранённые дни пауз исключаются.

Исправлена граница очистки журнала: начало локального дня минус 28 календарных дней вместо «сейчас минус 28 суток». Иначе финализатор ежедневно обрезал самый ранний день, делая 28 полных дней недостижимыми. Сохраняются 28 завершённых дней и текущая часть дня; версия JSONB v1 и совместимость со старым reader/writer не меняются. Миграций и API/event-контрактов нет. Новых команд/переменных окружения и действий владельцу не требуется; ops обновлён.

Проверки PHP 8.4 Homebrew: адресные SalesWarmupTest/SalesCoverageTest/SalesHistoryTest — 31/31, 150 assertions; полный backend SQLite — 899 passed + 4 PostgreSQL-only skipped (903 всего), 9270 assertions. PostgreSQL 18: эти три класса и WooCommerceApiIntegrationTest/ShopwareIntegrationTest — 51/51, 337 assertions. Новые тесты проверяют все границы ступеней, отсутствие ускорения от backfill, дырку внутри дня, отдельные live/test-пометки, оба DST-перехода, сохранение 28 дней после финализатора, read-only отчёт и tenant fail-closed. Pint, spec validator, локальные Markdown-ссылки и git diff --check — passed. Живые магазины, нагрузка и production не проверялись; фронтенд, плагины, юридические страницы и уведомления этим срезом не менялись.

Traceability: задача 12.A/B, ADR 0090–0093, ТЗ §15–17/25/37, ACC-44/46/47 (диагностическое evidence; статистические acceptance остаются открытыми). Следующее — интерфейс исключений и календарь DE, затем статистическая норма и калибровка; новые агрегатные окна/плагины остаются отдельными пунктами.
