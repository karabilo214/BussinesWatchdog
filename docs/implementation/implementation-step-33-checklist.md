# Implementation Step 33 Checklist: Remaining Reconciliation Rules

Stage: D4 reconciliation foundation
Spec references: `spec/Business-Watchdog-TZ.md` sections 12, 14, 25.
Acceptance references: `ACC-29`, `ACC-31`, `ACC-33`, `ACC-35`.

Step 32 covered the core net-consistency rules on a single order (capture/refund missing/amount, unsupported). This step adds the remaining four rule codes from the section 14 table. Three of them (`MONEY_MULTIPLE_CAPTURES`, `MONEY_CURRENCY_MISMATCH`, `MONEY_ORDER_CHANGED`) turned out to be evaluable per-order after all — they only need data already scoped to the order (its own active allocations, its own `transaction_ref`, its own `order_revisions`), so they were added to `OrderReconciliationService` rather than requiring a separate store-wide pass. Only `MONEY_PAYMENT_WITHOUT_ORDER` genuinely needs a store-wide scan (it looks for captures with no order at all), so it got its own `UnmatchedPaymentScanner` service.

## Scope

- [x] `OrderReconciliationService`: extract `activeCaptureAllocations()` so capture-amount, multiple-captures, and order-changed rules share one locked read instead of three.
- [x] `MONEY_MULTIPLE_CAPTURES`: fires when an order's active capture allocations reference more than one distinct `financial_transactions` row; `mismatch`, evidence lists the distinct capture transaction IDs.
- [x] `MONEY_CURRENCY_MISMATCH`: when the order has a `transaction_ref`, look up a `payments` row in the same store with a matching `intent_ref`/`charge_ref`; if its currency differs from the order's, `mismatch`. Skipped when the order has no `transaction_ref` or no matching payment is found.
- [x] `MONEY_ORDER_CHANGED`: find the order's earliest active capture's `occurred_at`, find the `order_revisions` snapshot that was current at that time, and compare its `total_minor` to the order's current `total_minor`; `mismatch` when they differ. Skipped when there is no capture yet or no revision history reaches back that far.
- [x] Add `ReconciliationFinding::RULE_MULTIPLE_CAPTURES` / `RULE_CURRENCY_MISMATCH` / `RULE_ORDER_CHANGED` / `RULE_PAYMENT_WITHOUT_ORDER` constants.
- [x] Add `UnmatchedPaymentScanner::scan(Store $store)`: store-wide, one `reconciliation_runs` row per scan; finds succeeded `capture` transactions older than a 24-hour orphan grace with no active `payment_allocations` row, and writes one `MONEY_PAYMENT_WITHOUT_ORDER` / `pending` finding per orphan (`order_id` null, `payment_id` set), capped at 100 findings per run.
- [x] Focused tests: multiple captures present/absent, currency mismatch present/absent (including the no-`transaction_ref` case), order-changed present/absent, orphan capture reported past grace, not reported within grace, not reported once allocated.

## Verification

- [x] `php -l` on all changed/added files (PHP 8.4).
- [x] `php vendor/bin/pint --test` on changed/added files: clean.
- [x] Full `php artisan test` (PHP 8.4): 123 tests passed, 387 assertions.
- [x] No new migration in this step (no schema change), so no additional PostgreSQL migration verification was needed.

## Not Done In This Step

- `rule_configs`-backed versioned tolerance/grace (orphan grace is still a fixed 24h constant on `UnmatchedPaymentScanner`, like the capture/refund grace constants on `OrderReconciliationService`).
- Dedup/coalescing of repeated `UnmatchedPaymentScanner` scans — each call creates a fresh run and fresh findings for the same still-orphaned capture; nightly-sweep scheduling and incident-level dedup are deferred to the scheduler step.
- Public API for triggering a scan or reading findings — this step is service-layer only (see Step 34).
- `GET /stores/{id}/unmatched-payments` candidate-suggestion endpoint (confidence `exact_candidate`/`manual_review`) — `UnmatchedPaymentScanner` only reports that a capture is orphaned, it does not suggest which order it might belong to.
