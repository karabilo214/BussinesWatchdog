# Implementation Step 28 Checklist: Domain Outbox Worker Recovery

Stage: D3 durable ingestion and background processing
Spec references: `spec/Business-Watchdog-TZ.md` sections 11, 25, 28, 36.
Acceptance references: `ACC-09`, `ACC-11`.

This step makes the domain outbox dispatcher safer for worker-style execution by adding explicit recovery and retry scheduling behavior.

## Scope

- [x] Add exponential retry backoff with jitter for outbox failures.
- [x] Preserve explicit retry delay override for focused tests and controlled callers.
- [x] Add `DomainOutboxSweeper` to release expired leases back to pending.
- [x] Run the sweeper before each dispatcher lease pass.
- [x] Add bounded loop mode to `outbox:dispatch`.
- [x] Keep existing one-shot `outbox:dispatch` behavior compatible.
- [x] Add focused tests for backoff, sweeper recovery, and bounded loop dispatch.

## Verification

- [x] Run PHP syntax checks for changed outbox command/support/test files.
- [x] Run focused outbox result recorder tests.
- [x] Run focused outbox dispatcher tests.
- [x] Run focused outbox sweeper tests.
- [x] Run full `php artisan test`; 86 tests passed with 283 assertions.

## Not Done In This Step

- Scheduler integration to run dispatcher/sweeper periodically.
- Horizon worker process supervision.
- Manual replay/dead-letter admin tooling.
- Tenant fairness quotas across large backfills.
- Event inbox `processing` lease state; current projection path still processes from `received` transactionally.
