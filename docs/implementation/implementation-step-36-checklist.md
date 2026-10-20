# Implementation Step 36 Checklist: Incident Engine Foundation

Stage: D5 incident engine
Spec references: `spec/Business-Watchdog-TZ.md` sections 14, 22, 25–27.
Acceptance references: `ACC-26`, `ACC-27`.

This step layers the incident engine (section 22) on top of the money reconciliation foundation from Steps 32–35. Scope is deliberately limited to the `money` family, since that is the only detector that exists; checkout/sales-drop correlation from the same section needs the browser worker and metrics pipeline, which do not exist yet.

## Scope

- [x] Migration for `signals`, `incidents`, `incident_signals`, `incident_activity`, `suppressions`, mirroring `spec/database/schema.sql` exactly (composite tenant/store FKs, the partial-unique `incidents_active_fingerprint` index limiting one active incident per fingerprint, pgsql CHECK constraints).
- [x] Models: `Signal`, `Incident`, `IncidentSignal`, `IncidentActivity`, `Suppression`.
- [x] `MoneyIncidentCorrelator::correlate(ReconciliationRun $run)`:
  - [x] A `mismatch` finding opens a new incident for its `(family=money, component, entity)` fingerprint, or attaches to the existing active one (bumps `revision`, updates `last_seen_at`/`title_code`, writes a `signal_linked` activity).
  - [x] A fresh `ok` finding for an entity with an active incident on the matching fingerprint auto-resolves it (`last_good_at`, `resolution_reason = auto_resolved_fresh_reconciliation_ok`).
  - [x] A new mismatch within 24h of a fingerprint's last resolution reopens that same incident instead of creating a duplicate; past 24h it creates a separate one.
  - [x] **Real bug caught by testing, not by inspection**: component grouping cannot be the raw `rule_code`. Fixing a `MONEY_CAPTURE_MISSING` problem by actually capturing money makes the next finding `MONEY_CAPTURE_AMOUNT` (same order, different rule), so a rule-code fingerprint would never see a matching `ok` finding to auto-resolve against. Fixed by grouping rule codes into a coarser `component` (`capture`, `refund`, etc.) for fingerprinting, while the specific rule code still lives on `title_code` (kept current on every attach) and on each `Signal`.
  - [x] `Signal` creation is deduplicated per finding via `dedupe_key = reconciliation_finding:{finding_id}` (`firstOrCreate`), so correlating the same findings twice does not create duplicate evidence rows.
  - [x] Wired into `ReconciliationController::store()`: every `POST /stores/{id}/reconciliations` call runs `correlate()` on the run(s) it just produced — not inside `OrderReconciliationService`/`UnmatchedPaymentScanner` themselves, keeping "compute findings" and "manage incidents" as separate concerns per the spec's own module list.
- [x] `IncidentLifecycleService`: `acknowledge` (idempotent on repeat, rejects on a resolved incident), `resolve` (reason required, rejects a repeat resolve), `comment` (append-only, does not touch `revision`/`last_seen_at`, matching "Comment не обновляет first/last failure times"), `snooze` (creates a `Suppression`, window must be in the future and ≤30 days), `revokeSuppression` (rejects a repeat revoke).
- [x] Public API: `GET /incidents` (filters: `store_id`/`state`/`severity`/`family`/`currency`, cursor-paginated by `id`), `GET /incidents/{id}` (incident + linked signals + full activity timeline), `POST /incidents/{id}/acknowledge` and `/resolve` and `/comments` (operator+), `POST /incidents/{id}/snooze` and `POST /suppressions/{id}/revoke` (admin+, matching the role matrix in section 7/26).
- [x] Focused tests: correlator (open/attach/auto-resolve/reopen/separate-after-24h), lifecycle service (all five actions and their rejections), HTTP layer (trigger-then-list, detail with signals/activity, role gating per action, cross-tenant 404s).

## Verification

- [x] `php -l` on all new/changed files (PHP 8.4).
- [x] `php vendor/bin/pint --test`: clean.
- [x] Full `php artisan test` (PHP 8.4): 181 tests passed, 583 assertions.
- [x] `php artisan migrate --force` against real PostgreSQL 18 (local Docker) applied the new migration cleanly, including the partial-unique index and all CHECK constraints.

## Not Done In This Step

- Checkout/sales-drop incident families and their specific correlation rules (section 22's "2 site failures + corroborating signal", "2 consecutive good checks", sales-anomaly auto-resolve) — these need the browser worker and metrics pipeline, neither of which exists yet.
- Critical severity escalation — every money incident is `warning` by default, per spec's explicit "по умолчанию financial findings warning без скрытой границы"; a configurable per-currency critical threshold is deferred rather than invented.
- `data_quality` on signals is currently always `{}` — no detector yet reports coverage/completeness context to attach there.
- Notifications on incident creation/transition (section 23) — nothing emits email/Telegram yet; this step only produces the incident record itself.
- `check_run_id`/`baseline_id` columns on `signals` are unused (nullable, wired for when browser checks/P2 baselines exist).
- Dirty-order coalescing driving automatic re-correlation — incidents only update when a client explicitly calls `POST /stores/{id}/reconciliations`; there is no background scheduler yet (same gap already noted in Steps 32–33).
