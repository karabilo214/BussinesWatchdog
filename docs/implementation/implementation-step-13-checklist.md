# Step 13 Checklist: Integration HMAC Authentication Foundation

Stage: D2 ingress bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 26, 27, 33, 36; `contracts/openapi.yaml` HMAC parameters; `spec/ACCEPTANCE.md` ACC-05.

This step adds reusable HMAC authentication for connector-originated requests. It protects a minimal heartbeat endpoint only; durable event inbox/outbox and event schema processing are later slices.

## Implementation

- [x] Add `integration.hmac` middleware alias.
- [x] Resolve `integration_credentials` by `X-BW-Key-Id`.
- [x] Require `X-BW-Timestamp`, `X-BW-Nonce`, `X-BW-Signature`, and `X-BW-Signature-Version: 1`.
- [x] Reject signed requests with query strings.
- [x] Verify timestamp tolerance of 300 seconds.
- [x] Verify canonical string `v1\n{timestamp}\n{nonce}\n{METHOD}\n{pathname}\n{hex_sha256(raw_body)}`.
- [x] Use decoded 32-byte HMAC secret from encrypted credential storage.
- [x] Compare signatures with timing-safe comparison.
- [x] Store nonce in cache after signature verification with 10 minute TTL.
- [x] Reject nonce replay.
- [x] Reject inactive/revoked credentials.
- [x] Add protected `POST /api/v1/ingest/heartbeat`.

## Tests

- [x] Signed heartbeat is accepted and updates integration heartbeat time.
- [x] Missing signature headers are rejected.
- [x] Altered body signature is rejected.
- [x] Nonce replay is rejected.
- [x] Stale timestamp is rejected.
- [x] Revoked credential is rejected.
- [x] Query string is rejected for signed ingest.
- [x] Run `php artisan test`; 49 tests passed with 150 assertions.

## Not Done In This Step

- `POST /api/v1/ingest/events` durable event inbox.
- Event schema validation and quarantine.
- Ingestion request size limits.
- Per-integration/IP route throttling.
- Audit log entries for authentication failures.
- Support for draining rotated keys.
