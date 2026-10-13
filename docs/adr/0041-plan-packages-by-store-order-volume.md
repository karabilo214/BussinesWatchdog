# ADR 0041: Plan packages by store order volume (pricing option)

Status: partly implemented (Step 114: order counting, package order limit, grace period, notice, recommendation, individual-price package — all configured in the owner panel). Prices and limits below are examples to be checked against cost; retention by plan is not implemented yet. The owner accepted the history rule and the pause after the grace period (2026-10-12); prices and boundaries are the owner's to set.

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

## Implemented (Step 114)

1. **Counting**: `store_order_volumes (store, month, orders)`. An order is counted once, when its projection is first created from a non-synthetic event, in the UTC month it was placed (`source_created_at`). Re-syncs, new revisions, status changes and refunds never count. Orders placed before the store was added in the cabinet (`stores.created_at`) are the history import and do not count — the server has no other marker for the plugin's backfill (accepted by the owner 2026-10-12).
2. **Average**: the last three complete calendar months; a month counts only if the store existed for the whole of it, so a partial first month does not lower the average; a store younger than one complete month is not evaluated. The current month is shown, not used.
3. **Package settings in the owner panel** (plan version editor): the limit `max_orders_per_month` (orders a month per store; "unlimited" for none; at least 1), `order_volume_grace_days` (0–365, default 30) and `contact_sales` (individual price: publishable without prices, shown on the website as «Individual» with a contact button, cannot be bought in the account). A new version copies them.
4. **Crossing the limit**: `billing:order-volume` (daily 04:10 UTC) stores every store's average, marks `order_volume_over_since` when the average exceeds the package limit and emails the organisation owners once (ru/en/de): the average, the limit and the date by which to choose a package (over since + grace days of the subscribed package version). Falling back within the limit clears the mark. Nothing is switched off and nothing is charged. **After the grace period** (owner's decision 2026-10-12, Step 115): the subscription belongs to the organisation, so the whole organisation is paused (`tenants.order_volume_paused_at`): `EntitlementCalculator` reports `paused_billing` with the reason `order_volume_exceeded`, every protection feature stops (checks, reconciliation, payment-attempt monitoring, notifications), store data is still received and nothing is deleted; the owners get a second email. It resumes only when a package covers the volume of every store — at once after a purchase or plan change (`SubscriptionProjector`) or temporary access from the owner panel, otherwise on the next nightly check — and every store's orders are re-queued for reconciliation.
5. **Recommendation**: the smallest published, non-placeholder package other than the current one whose limit covers the average; an individual-price package only when no priced one does.
6. **Where it shows**: the cabinet billing page (block «Order volume»: this month, average, package limit per store, the over-the-package notice with the date and the recommended package; the order limit in every plan card; the individual package card), the owner panel organisation card (stores tab: orders this month, average / limit, «over the package since»), the public pricing page (orders per store row, the individual package).
7. **Not changed**: `monthly_event_budget` keeps metering events (it is not the order counter); pilot mode (`WATCHDOG_BILLING_ENFORCEMENT=pilot`) evaluates nothing because limits are not enforced.

Still open: the check-frequency add-on (a new price kind next to the extra store, through Paddle checkout and plan change), retention by plan, and the owner's decisions on prices, boundaries and grace period.

## Faster checks add-on (Step 116)

- **Owner panel**: a plan version has `faster_check_interval_minutes` (5–1440, empty — no add-on) and prices of the kind `faster_checks` (quantity 1 at Paddle, one per period and currency like the base price). Publishing a version with such a price requires the interval, and it must be shorter than the package's `min_check_interval_minutes`.
- **Buying**: checkout and plan change take `faster_checks`; the Paddle subscription gets one more item. Adding it raises the recurring amount, so it is charged at once with proration (like an upgrade); removing it lowers the amount, so it applies at the end of the paid period (like a downgrade) and the add-on stays active until then. Offered only by the package's own price in the same currency and period (`faster_checks_unavailable` otherwise).
- **Effect**: the contract and the subscription record the add-on; `EntitlementCalculator::withFasterChecks` lowers the plan's minimum check interval to the add-on's interval (never raises it, never limits a package without a minimum), so check scheduling and the scenario form follow it without other changes. Discounts of the next period include the add-on amount.
- **Shown**: cabinet plan cards (a checkbox with interval and price, included in the total), subscription block («Faster checks: every N min»), public pricing page (add-on line).
- Verified with tests only (Paddle fixtures); not yet run against the Paddle sandbox.

