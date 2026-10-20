# Implementation Step 35 Checklist: Order And Payment Detail Reads

Stage: D4 reconciliation foundation
Spec references: `spec/Business-Watchdog-TZ.md` sections 13, 24, 26.
Spec references: `spec/contracts/ui-api-catalog.md` section 5 (`/orders/{id}`, `/payments/{id}`, `/stores/{id}/unmatched-payments`).

This step closes the three read-only detail endpoints explicitly deferred in Step 34 as "not allocation or reconciliation". It's pure composition over data that already exists from Steps 25–34: no new domain logic, no new money rules.

## Scope

- [x] `GET /orders/{id}`: order fields plus latest finding per `rule_code`, captures (via active-and-revoked payment allocations), Woo-side refunds (direct `order_id`), refund transactions (via refund allocations), all allocations, and full revision history. Tenant-scoped, 404 across tenants. Role: any store-read role.
- [x] `GET /payments/{id}`: payment fields plus latest finding per `rule_code`, all transactions for that payment, payment allocations, and refund allocations reached through this payment's refund transactions. Same scoping/role as orders.
- [x] `GET /stores/{id}/unmatched-payments`: for each succeeded capture with no active allocation (no 24h grace here — this is a browsing/review tool, not an anomaly flag, so it intentionally shows orphans of any age), suggests:
  - `exact_candidate`: an order in the same store whose `transaction_ref` matches the payment's `intent_ref`/`charge_ref`.
  - `manual_review`: up to 5 same-store, same-currency, same-`total_minor` orders (excluding the exact candidate), ranked by closeness of `source_updated_at` to the capture's `occurred_at` — ranking done in PHP after a narrow, already-filtered DB fetch, not via DB-specific date arithmetic (SQLite and PostgreSQL don't share that syntax — the Step 34 cursor bug was a direct lesson here).
  - `reason` is `no_candidate_found` when neither produced a hit, `review_required` otherwise. This is a pure suggestion list; nothing here writes a finding or claims a confirmed match.
  - Cursor-paginated by `id` via the same `UuidCursor` helper now used by `findings`.
- [x] Extracted `App\Support\Api\UuidCursor` (encode/decode) out of `ReconciliationController` and reused it in the new `UnmatchedPaymentController`, instead of copying the same 10 lines a second time.
- [x] New DTOs: `OrderDto`, `RefundDto`, `OrderRevisionDto` (orders), `PaymentDto`, `FinancialTransactionDto` (payments) — the existing `PaymentAllocationDto`/`RefundAllocationDto`/`ReconciliationFindingDto` from Step 34 are reused as-is for the allocation/finding sub-lists.
- [x] Focused tests: order detail with captures/allocations/revisions/findings, order detail with refunds/refund-transactions/refund-allocations, cross-tenant 404; payment detail with transactions/allocations, cross-tenant 404; unmatched-payments exact-candidate, manual-review-by-amount, no-candidate, and already-allocated-is-excluded.

## Verification

- [x] `php -l` on all new/changed files (PHP 8.4).
- [x] `php vendor/bin/pint --test`: clean.
- [x] Full `php artisan test` (PHP 8.4): 156 tests passed, 498 assertions.
- [x] No schema change in this step, so no additional PostgreSQL migration verification was needed.

## Not Done In This Step

- `GET /stores` list-level inclusion of coverage/order summaries — out of scope, this step is only the two single-resource detail reads plus the candidate-suggestion list.
- Any write path: `unmatched-payments` never creates a `payment_allocations` row itself; an operator still has to call `POST /payment-allocations` (Step 34) to act on a suggestion.
- Confidence tuning (amount tolerance, currency-mismatch-but-reference-matches handling) beyond the two categories the contract names (`exact_candidate`/`manual_review`).
