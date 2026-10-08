# Step 16 Checklist: Domain Outbox Leasing Foundation

Stage: D2 ingress bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 28, 33, 36; `spec/database/schema.sql` `domain_outbox`.

This step adds the first outbox lease service. It does not dispatch side effects, publish messages, or process projections yet.

## Implementation

- [x] Add domain outbox status constants.
- [x] Add `DomainOutboxLeaser`.
- [x] Lease due pending messages ordered by `next_attempt_at`, `created_at`, `id`.
- [x] Mark leased messages as `leased`.
- [x] Increment `attempts` on each lease.
- [x] Set `lease_until`.
- [x] Re-lease expired `leased` messages.
- [x] Ignore active leases.
- [x] Ignore terminal `published` and `dead_letter` messages.
- [x] Clamp lease batch size and lease duration to bounded values.

## Tests

- [x] Due pending messages are leased in order.
- [x] Active leases are not leased again.
- [x] Expired leases are leased again and attempts increment.
- [x] Terminal messages are ignored.
- [x] Run `php artisan test`; 62 tests passed with 199 assertions.

## Not Done In This Step

- Dispatcher command/worker loop.
- Side-effect execution.
- Publish/dead-letter result APIs.
- Backoff policy after failed execution.
- Consumer idempotency tests.
