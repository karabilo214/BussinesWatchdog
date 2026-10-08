# Step 15 Checklist: Domain Outbox Foundation

Stage: D2 ingress bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 11, 24, 28, 36; `spec/database/schema.sql` `domain_outbox`.

This step adds the durable domain outbox table and creates a pending outbox message when a new signed event is accepted into `event_inbox`. It does not dispatch, lease, publish, or process projections yet.

## Implementation

- [x] Add `domain_outbox` migration aligned with the reference schema.
- [x] Add `DomainOutbox` model.
- [x] Define `event_inbox.received` outbox topic.
- [x] Create an outbox message in the same transaction as new accepted inbox events.
- [x] Use `event_inbox.id` as the outbox dedupe key for accepted events.
- [x] Do not create outbox messages for duplicate retries.
- [x] Do not create outbox messages for invalid/conflict records.

## Tests

- [x] Accepted event creates pending domain outbox row.
- [x] Identical duplicate event does not create duplicate outbox rows.
- [x] Mixed valid/invalid batch creates outbox only for accepted records.

## Not Done In This Step

- Outbox leasing/dispatcher.
- Projection worker.
- Dead-letter transitions.
- Retry/backoff scheduling beyond initial `next_attempt_at`.
- Consumer idempotency tests.
