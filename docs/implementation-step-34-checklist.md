# Implementation Step 34 Checklist: Public Allocation And Reconciliation API

Stage: D4 reconciliation foundation
Spec references: `spec/Business-Watchdog-TZ.md` sections 13, 14, 26, 27.
Spec references: `spec/contracts/ui-api-catalog.md` section 5 (`/payment-allocations`, `/refund-allocations`, `/stores/{id}/reconciliations`, `/stores/{id}/findings`).
Acceptance references: `ACC-36`, `ACC-29`.

This step exposes the allocation (Steps 30–31) and reconciliation (Steps 32–33) services over HTTP, scoped to exactly what was asked: allocation create/revoke and reconciliation trigger/read. It also adds the `Idempotency-Key` mechanism the contract requires for these mutations, since nothing in the repo implemented it yet.

## Scope

### Idempotency-Key infrastructure (new, reusable)

- [x] `idempotency_keys` table: unique `(tenant_id, route, idempotency_key)`, `request_hash`, nullable `response_status`/`response_body` (raw text, not jsonb, so a `204` with an empty body round-trips correctly), `expires_at` (24h TTL).
- [x] `EnsureIdempotencyKey` middleware (alias `idempotency`): reserves the key by inserting the row *before* running the request, inside one DB transaction covering the whole request. A concurrent request with the same key blocks on the unique index until the first transaction resolves:
  - first request's insert succeeds → runs the handler → on a 2xx response, fills in the reservation with the response; on a non-2xx response, deletes the reservation (so the caller can safely retry, e.g. after a transient 409) → on an exception, the whole transaction (including the reservation) rolls back.
  - second concurrent/retried request's insert raises `UniqueConstraintViolationException` → re-reads the now-committed row → same request hash replays the cached response; different hash → `409 idempotency_key_conflict`.
  - missing header → `400`.
- [x] Applied only to the three create/trigger mutations that the contract marks `Idempotency-Key обязателен` (`payment-allocations`, `refund-allocations`, `stores/{id}/reconciliations`); not applied to revoke, which is already safe to retry on its own (a repeat revoke deterministically 409s via `ERROR_ALREADY_REVOKED`).

### Allocation API

- [x] `POST /payment-allocations`: validates `order_id`/`payment_id`/`capture_transaction_id` resolve inside the caller's tenant (404 otherwise), rejects a request-body `currency` that doesn't match the capture transaction's actual currency (422) before calling `PaymentAllocationService::allocateCapture`, writes an `audit_log` row (`payment_allocation.created`) with the reason, returns `201` with the allocation DTO. Role: owner/admin.
- [x] `POST /payment-allocations/{id}/revoke`: reason required, 404 across tenants, `204` on success.
- [x] `POST /refund-allocations` / `POST /refund-allocations/{id}/revoke`: same shape for refund allocations.
- [x] `AllocationRejected::httpStatus()`: maps `allocation_already_revoked` / `allocation_amount_exceeds_*` / `allocation_has_active_refund_allocations` to `409` (state conflict), everything else (scope/capture/refund/currency/order mismatch, missing reason) to `422`.
- [x] Closed a real gap found while wiring this up: `PaymentAllocationService::allocateRefund` did not check that the refund's `order_id` matches the payment allocation's `order_id`, so a refund for order A could be linked against a capture allocation that belongs to order B. Added `ERROR_ORDER_MISMATCH` and a test for it — this was fixed at the service layer (Step 31 code), not just the new controller, since it is a real money-safety gap, not only an API-layer concern.

### Reconciliation API

- [x] `POST /stores/{id}/reconciliations`: accepts `order_ids` (≤100, each validated to belong to the store/tenant, otherwise `422 order_not_found` with *nothing* evaluated — fail closed, not partial) or, when `order_ids` is omitted, runs the store-wide `UnmatchedPaymentScanner`. `dry_run: true` returns `422 dry_run_not_supported` (explicit rejection — neither service supports a real dry-run yet, so silently ignoring the flag would be worse than refusing it). Response `202 {data: [RunSummary, ...]}` (always a list, even for the single store-wide scan, so the shape is consistent regardless of which path ran). Role: owner/admin/operator.
- [x] `GET /stores/{id}/findings`: filters `from`/`to`/`status`/`rule_code`/`currency`; cursor pagination. Role: any store-read role (owner/admin/operator/viewer).
- [x] Cursor design: encodes only the finding's UUIDv7 `id` (base64), not `evaluated_at`. An earlier version tried a compound `(evaluated_at, id)` keyset cursor and failed under test: SQLite truncates `timestampTz` columns to whole-second precision while the in-memory Carbon value round-trips with a formatted microsecond component, so the encoded cursor string never matched the stored value on the equality branch. Since every finding's `id` is a time-ordered UUIDv7 generated at the same instant as `evaluated_at`, `id` alone is already a correct, portable, driver-independent sort/cursor key — so the compound key was dropped instead of patched.

## Verification

- [x] `php -l` on all new/changed files (PHP 8.4).
- [x] `php vendor/bin/pint --test` on all new/changed files: clean.
- [x] Full `php artisan test` (PHP 8.4): 147 tests passed, 453 assertions.
- [x] `php artisan migrate --force` against real PostgreSQL 18 (local Docker) applied the `idempotency_keys` migration cleanly.
- [x] Confirmed Laravel's `UniqueConstraintViolationException` is raised correctly on both drivers used by this repo (SQLite via message-pattern match, PostgreSQL via SQLSTATE `23505`), which the mutex design depends on.

## Not Done In This Step

- `GET /orders/{id}`, `GET /payments/{id}`, `GET /stores/{id}/unmatched-payments` — order/payment detail and candidate-suggestion reads; not allocation or reconciliation endpoints, out of this step's explicit scope.
- `POST /stores/{id}/reconciliations` with a `from`/`to` date-window bulk selection — only `order_ids` or the full store-wide unmatched-payment scan are supported; a windowed "all orders touched since X" trigger is deferred.
- True `dry_run` support (evaluate without persisting) in either reconciliation service.
- Cursor signing (`cursor signed, включает tenant/filter snapshot` per spec) — the cursor is unsigned; it stays safe because the main query is already tenant/store-scoped independently of whatever the cursor contains, but a forged cursor isn't cryptographically rejected, just ineffective.
- Rate limiting on `POST /stores/{id}/reconciliations` ("on-demand API with rate limit" per spec) and the `manual checks 1/мин/store` family of limits in general.
- Idempotency key cleanup job for rows past `expires_at` (TTL is recorded but nothing purges expired rows yet).
