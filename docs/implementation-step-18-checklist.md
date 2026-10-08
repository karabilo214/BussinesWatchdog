# Step 18 Checklist: Domain Outbox Dispatcher Skeleton

Stage: D2 ingress bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 28, 33, 36; `spec/database/schema.sql` `domain_outbox`.

This step connects outbox leasing and result recording into a dispatcher skeleton. It executes no external side effects; known `event_inbox.received` messages are acknowledged by a no-op handler.

## Implementation

- [x] Add `DomainOutboxDispatcher`.
- [x] Lease due outbox messages through `DomainOutboxLeaser`.
- [x] Publish known `event_inbox.received` messages through no-op handler.
- [x] Mark unsupported topics as failed with safe error code.
- [x] Add `outbox:dispatch` console command.
- [x] Register the console command.
- [x] Return dispatch counters for leased/published/failed.

## Tests

- [x] Dispatcher publishes known due messages.
- [x] Dispatcher schedules retry for unsupported topics.
- [x] Console command dispatches due messages and reports counters.
- [x] Run `php artisan test`; 70 tests passed with 225 assertions.

## Not Done In This Step

- Real projection processing for `event_inbox.received`.
- External side effects.
- Long-running worker loop.
- Exponential backoff with jitter.
- Consumer idempotency tests.
