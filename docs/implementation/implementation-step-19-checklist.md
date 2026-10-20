# Implementation Step 19 Checklist: Event Inbox Processor Skeleton

Stage: D2 ingress bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 28, 35.2; `spec/database/schema.sql` `event_inbox`, `domain_outbox`.

This step replaces the `event_inbox.received` no-op outbox handler with the first durable inbox processor. It marks accepted inbox events as processed inside a database transaction before the outbox message is acknowledged.

## Scope

- [x] Add `EventInboxProcessor`.
- [x] Process `received` inbox events into `processed`.
- [x] Treat already `processed` inbox events as idempotent success.
- [x] Fail and retry outbox messages when the referenced inbox event is missing or not processable.
- [x] Keep unsupported outbox topics on the existing retry path.
- [x] Add feature coverage for successful processing, idempotent processed events, missing inbox events, unsupported topics, and console dispatch.

## Verification

- [x] Run PHP syntax checks for changed backend files.
- [x] Run `php artisan test`; 72 tests passed with 232 assertions.

## Not Done In This Step

- WooCommerce/order/payment/refund projections.
- Full JSON Schema Draft 2020-12 validation.
- Projection-specific idempotency tables.
- Long-running worker loop, sweeper, exponential backoff with jitter, and manual replay UI.
