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

## What the system supports today (checked against the code, 2026-10-12)

Supported:

- Fixed packages with a known price per period: plan versions with prices per interval and currency, published to Paddle (ADR 0035/0036); the pricing page shows them (ADR 0039).
- Moving to a bigger package with the new price confirmed by the customer: upgrade now with proration, downgrade at period end, store choice when the store limit drops (ADR 0037).
- Package terms as limits: number of stores (`max_active_stores`, extra stores as a paid add-on), minimum browser check interval (enforced by `CheckScheduler`), manual checks per day (enforced), features per plan; all paid packages can carry the same protection features.
- A grace mechanism exists in principle: temporary access by the owner (ADR 0038) and plan change preview.

Not supported:

1. **Counting orders per store.** `UsageMeter::STORE_EVENTS` counts every accepted non-synthetic event of a store-reported connector per organisation (each status change, refund, payment snapshot and the plugin's history backfill count; provider-only integrations do not), not unique new real orders per store. The server cannot tell history import from new orders: events carry no origin marker (the plugin's `BackfillJob` sends ordinary snapshots).
2. **Monthly volume and the three-month average** per store: not stored anywhere.
3. **A package boundary by orders per month**: no such limit; `monthly_event_budget` is metered but never enforced (limit `null`).
4. **Crossing the boundary**: no notification to the customer, no grace period, no "move to the next package" prompt. (Nothing is cut off either, since the budget is not enforced.)
5. **More frequent checks as an add-on**: the only add-on price kind is an extra store; check frequency comes only from the plan.
6. **History retention as a package term**: `retention_days` is in the plan but data is not deleted or hidden by it.
7. **Enterprise "individual"**: a plan without a public price is simply not shown on the pricing page; there is no "contact us" package card.

## What implementing it would take

1. Order volume counter: count an order once, when its projection is first created from a non-synthetic event, per store and calendar month, excluding orders whose source creation date is before the store was connected (or a backfill marker sent by connectors), with a monthly figure and a three-month average.
2. Plan limit `max_orders_per_month` (per store) shown on the pricing page and in the cabinet.
3. Boundary flow: notice to the owner when the average (or a sustained month) exceeds the package, a configurable grace period, then a recommendation and a confirmed plan change — never automatic charging or switching off.
4. Check-frequency add-on as a new price kind next to the extra store, applied in `EntitlementCalculator`.
5. Optional: retention enforcement by plan; an "individual" package card linking to the contact form.

Open for the owner: final prices and boundaries after the cost check, the grace period length, the price of more frequent checks, and whether Start keeps 19 € as the entry package.
