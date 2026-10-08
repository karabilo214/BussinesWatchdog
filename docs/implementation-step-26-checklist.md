# Implementation Step 26 Checklist: Projection Failure Reason Codes

Stage: D3 Woo projections bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 28, 35.2, 36.

This step replaces generic projection failures with explicit reason codes that can be stored on failed outbox attempts and later used by dead-letter/manual replay tooling.

## Scope

- [x] Add `EventProjectionResult`.
- [x] Let projectors return explicit failure reason codes.
- [x] Record projector failure code in `EventInboxProcessor`.
- [x] Propagate projector failure code to `DomainOutboxResultRecorder`.

## Verification

- [x] Run PHP syntax checks for changed backend files.
- [ ] Run `php artisan test` inside the backend container.

## Not Done In This Step

- Admin dead-letter UI.
- Manual replay dry-run summaries.
- Localized operator-facing reason code descriptions.
