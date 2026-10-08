# Implementation Step 27 Checklist: Projection And Ingest Hardening

Stage: D3 durable ingestion and projection hardening
Spec references: `spec/Business-Watchdog-TZ.md` sections 11, 12, 25, 26, 28, 36.
Acceptance references: `ACC-06`, `ACC-07`, `ACC-08`, `ACC-09`, `ACC-10`, `ACC-11`, `ACC-29`, `ACC-30`.

This step tightens the existing durable ingestion slice before moving into allocation and reconciliation work.

## Scope

- [x] Move duplicated projection value normalization helpers into `ProjectionValueNormalizer`.
- [x] Add contract-aware event payload validation for supported event types.
- [x] Reject malformed JSON with `400 malformed_json`.
- [x] Reject request bodies larger than 1 MiB with `413 request_too_large`.
- [x] Persist quarantinable invalid events with `status=quarantined` and no projection outbox message.
- [x] Preserve non-persistable invalid record behavior for records without enough identity.
- [x] Detect same order revision hash conflicts.
- [x] Detect same refund revision hash conflicts.
- [x] Detect same payment source timestamp hash conflicts.
- [x] Add focused tests for ingest limits, quarantine, and projection conflicts.

## Verification

- [x] Run PHP syntax checks for changed backend files.
- [x] Run focused ingest tests.
- [x] Run focused outbox/projection tests.
- [x] Run full `php artisan test`; 83 tests passed with 275 assertions.

## Not Done In This Step

- External JSON Schema library integration.
- Admin quarantine/dead-letter UI.
- Manual replay dry-run summaries.
- Payment/refund allocation tables and services.
- Reconciliation runs/findings.
- Outbox sweeper and long-running worker loop.
