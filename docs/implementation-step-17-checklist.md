# Step 17 Checklist: Domain Outbox Result Recording Foundation

Stage: D2 ingress bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 28, 33, 36; `spec/database/schema.sql` `domain_outbox`.

This step records processing outcomes for leased outbox messages. It does not execute side effects or run a dispatcher loop.

## Implementation

- [x] Add `DomainOutboxResultRecorder`.
- [x] Mark active leased messages as `published`.
- [x] Clear lease and error state on publish.
- [x] Mark failed active leases back to `pending` before max attempts.
- [x] Set retry `next_attempt_at`.
- [x] Preserve safe `error_code`.
- [x] Move failed messages to `dead_letter` at max attempts.
- [x] Reject stale lease results after `lease_until`.
- [x] Reject results for non-leased messages.

## Tests

- [x] Active lease can be marked published.
- [x] Failed active lease schedules retry before max attempts.
- [x] Failed active lease becomes dead letter at max attempts.
- [x] Stale lease results are rejected.
- [x] Non-leased messages reject result recording.

## Not Done In This Step

- Dispatcher command/worker loop.
- Side-effect execution.
- Exponential backoff with jitter.
- Consumer idempotency tests.
- Manual replay/dead-letter resolution.
