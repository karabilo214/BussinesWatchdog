# Step 14 Checklist: Durable Event Ingress Inbox Foundation

Stage: D2 ingress bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 11, 24, 26, 27, 28, 36; `contracts/openapi.yaml` `/api/v1/ingest/events`; `spec/database/schema.sql` `event_inbox`.

This step adds durable signed event intake into `event_inbox`. It does not normalize events into projections, publish outbox messages, or run full JSON Schema Draft 2020-12 validation yet.

## Implementation

- [x] Add `event_inbox` migration aligned with the reference schema.
- [x] Add `EventInbox` model.
- [x] Add HMAC-protected `POST /api/v1/ingest/events`.
- [x] Derive tenant/store/integration from the verified credential context.
- [x] Reject invalid batch envelopes without committing records.
- [x] Accept 1..100 events per batch.
- [x] Validate required event envelope fields and known event/aggregate types.
- [x] Reject unsupported schema versions per record.
- [x] Reject future event timestamps beyond 5 minutes per record.
- [x] Persist accepted events with canonical payload hash.
- [x] Treat identical duplicate event IDs as `duplicate`.
- [x] Treat same event ID with different payload hash as `conflict`.
- [x] Return `202` when every record is accepted/duplicate.
- [x] Return `207` for mixed per-record invalid/conflict results.

## Tests

- [x] Signed event batch is committed to inbox.
- [x] Identical duplicate event is reported as duplicate.
- [x] Same event ID with different payload is conflict.
- [x] Mixed valid/invalid batch returns per-record results.
- [x] Invalid batch envelope rejects without commit.
- [x] Unsupported schema version is per-record invalid.
- [x] Run `php artisan test`; 58 tests passed with 185 assertions.

## Not Done In This Step

- Full JSON Schema Draft 2020-12 validation against `contracts/event.schema.json`.
- Durable projection worker and outbox publication.
- Request size limit enforcement at 1 MiB.
- Per-integration ingress throttling.
- Quarantine persistence for semantically invalid records.
- Clock-skew quarantine rows instead of per-record invalid result.
- Audit log entries for ingest conflicts and invalid records.
