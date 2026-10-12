# ADR 0032: Usage counter for count-bound limits

Status: implemented (Step 101); owner decisions on points 4 and 6 recorded 2026-10-12

## Context

Owner (Step 98): some functions are limited by the number of uses. TZ §29 and spec/panels/ADMIN-BACKEND.md §2: `usage_entries` is an idempotent ledger of quota changes, `usage_buckets` the aggregate; counters increment atomically and deduplicate by a domain operation id, so retries and webhook replays never count twice. The catalog (ADR 0029) already has two count-bound limits, `manual_runs_per_day` and `monthly_event_budget`, both per store (multiplied by the number of stores) and so far not enforced.

## Decisions

1. **Tables** as in the reference schema: `usage_buckets` (tenant, metric, period start/end, value ≥ 0) and `usage_entries` (unique tenant + metric + operation key, delta, reason, time).
2. **`UsageMeter::consume`**: one transaction, the period's bucket row locked, an already recorded operation is a no-op success, otherwise the limit is checked and the entry and the bucket change together. A rejected attempt records nothing.
3. **Periods**: calendar day and calendar month **in the tenant's timezone** (the customer's "today" and "this month"), stored as UTC bounds; daylight saving changes are handled.
4. **Manual check runs** (`manual_runs_per_day`, tenant-wide): counted when the run is created, after the eligibility, per-minute and active-run checks, so refused attempts are free; retries with the same `Idempotency-Key` replay the first answer. Over the limit: 429 `usage_limit_reached` with `errors[0]` = {limit, value, used, resets_at} and `Retry-After`. **Owner 2026-10-12:** a run cancelled by the user or ending `inconclusive` because of our worker is not returned to the counter.
5. **Store events** (`monthly_event_budget`): counted, not limited. Counted: events accepted from store connectors (plugin, WooCommerce REST, Shopware); not counted: duplicates, invalid/quarantined events, synthetic events from our own checks, and payment provider data (needed for money correctness).
6. **Event budget is a soft limit** (owner 2026-10-12): events are never refused or dropped because of the budget, money monitoring continues. The subscription page shows the month's usage, a warning above 80 % and, above 100 %, that events are still accepted but the plan is sized for less, with the offer to choose a larger plan or add stores. The hard variant from TZ §29 (429, backlog in the plugin, recovery by backfill) is not used.
7. **Counting runs in pilot mode too**; limits apply only with `WATCHDOG_BILLING_ENFORCEMENT=enforce`. `GET /api/v1/entitlements` returns `usage` (used and period) next to `limits`.
