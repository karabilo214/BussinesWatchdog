# ADR 0041: Plan packages by store order volume (pricing option)

Status: proposed — an option for the owner to decide on; nothing is implemented and no published plan changes. Prices and limits below are examples to be checked against cost.

## Context

The subscription plans need a pricing model that keeps the monthly amount predictable for the customer while larger stores, which need more processing, pay more. Recorded 2026-10-12 as a variant to consider for plan packages (ADR 0029 entitlements, ADR 0037 plan change).

## Proposal

1. **Fixed packages by store size.** The monthly amount is known in advance; a larger processing volume is paid by a more expensive package, never per purchase. Preliminary grid (example):

   | Package | Orders per month per store | Subscription |
   |---|---|---|
   | Start | up to 1 000 | 19 € |
   | Business | up to 5 000 | 39 € |
   | Growth | up to 20 000 | 79 € |
   | Enterprise | more than 20 000 | individual |

   The price is still tied to the number of orders — taking that link away entirely while also accounting for volume is not possible — but for the customer the difference is substantial: with 300 or with 900 orders they pay the same 19 €, with no charge per purchase.
2. **What defines a package.** Core protection features stay in every paid package; the package price is set by order volume; the number of stores, the frequency of browser checks and the history retention are explicit limits in the package terms; more frequent checks are sold as an add-on. Reason: browser checks may cost more than order processing — two stores checked every 15 minutes create similar browser load even with very different sales.
3. **Crossing a boundary.** Protection is never switched off suddenly and no extra invoice is issued. The customer is notified and given time to move. A one-off spike is served within an agreed grace period; sustained growth requires the next package, with the new price confirmed by the customer. Store size is measured as the average number of orders over three months.
4. **What is counted.** Only unique new real orders. Repeated syncs, status changes, refunds and the initial import of history do not increase the counter.
5. **Entry price.** The universal 19 € can stay as the entry price, limited by the size of the store it serves.

## Relation to the current implementation

- Plans already carry per-store limits (`max_active_stores`, `max_extra_stores`, `min_check_interval_minutes`, `retention_days`, `manual_runs_per_day`, `monthly_event_budget`; ADR 0029) and plan change with confirmation of the new price exists (ADR 0037), so the packages could be published as plans with these limits.
- The current usage meter (`UsageMeter::STORE_EVENTS`, `monthly_event_budget`) counts every accepted store event, including re-syncs and status changes; this proposal would need a separate counter of unique new real orders per store (first appearance of a non-synthetic order, excluding the history import), a three-month average, and the notice / grace-period flow instead of a hard limit.
- Open for the owner: final prices and boundaries after the cost check, the grace period length, the price of more frequent checks, and whether Start keeps 19 € as the entry package.
