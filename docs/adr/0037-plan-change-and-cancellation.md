# ADR 0037: Plan change and cancellation in the customer dashboard

Status: implemented (Step 108); points 3, 4, 6 and 7 are commercial assumptions waiting for the owner's review

## Context

spec/panels/PUBLIC-SUBSCRIPTION-FRONTEND.md §7 and §9, CLIENT-PANELS.md §5, OWNER-PANEL.md §5, ADMIN-BACKEND.md §3–§4: upgrade with a provider proration preview and a confirmation token (10 minutes, re-checked against the current subscription version), no access before the provider confirms; downgrade at the period end; cancel at the period end and resume; when the new plan allows fewer stores the owner chooses the stores that stay, otherwise `capacity_choice_required` pauses scheduled browser jobs — nothing is deleted or picked automatically. Paddle adapter from ADR 0030, discounts from ADR 0036.

## Facts from the Paddle sandbox (2026-10-12, real subscriptions)

- `PATCH /subscriptions/{id}/preview` returns `immediate_transaction` (with proration lines), `next_transaction`, `update_summary`; nothing changes.
- `prorated_immediately` Start + 1 store (28 €) → Pro + 1 store (56 €) 31 days before renewal: 27.82 € charged now (credit −27.84 €, charge 55.66 €), the next renewal 56.00 €. Totals are gross for a German buyer.
- Paddle has **no scheduled item change** (scheduled changes are only cancel/pause/resume). `do_not_bill` and `full_next_billing_period` change the items at once without a charge; the next renewal bills the new items.
- The proration charge carries **no subscription discount**; the subscription's discount for the next period (ours is a flat amount computed on the old price) stays on the subscription after an item change (Start with 2.80 € planned → 16.00 € next instead of 19.00 − 10 %).
- `POST /subscriptions/{id}/cancel {effective_from: next_billing_period}` keeps the subscription `active` with `scheduled_change.action = cancel`; `PATCH {scheduled_change: null}` resumes. Items can still be changed while a cancellation is scheduled. `discount: null` and `on_payment_failure: prevent_change` are accepted.

## Decisions

1. **Endpoints** (owner only): `POST /billing/plan-change-preview` → `POST /billing/plan-change` (preview token + Idempotency-Key), `POST /billing/plan-change/cancel`, `POST /billing/cancel` (`at_period_end: true`, optional reason kept only in the audit log), `POST /billing/resume` (optional `expected_version`), `PUT /billing/retained-stores`. Every change is a `billing_plan_changes` row (from/to price and extra stores, kind, status, amounts, retained stores, contract version, effective time, idempotency key, error); every action is audited.
2. **Immediate or next period**: a change whose recurring price (base + extra stores, our stored prices) is not lower takes effect at once (`prorated_immediately`, `on_payment_failure: prevent_change` — a declined charge leaves the subscription unchanged); a lower one takes effect at the next period. Same currency and billing interval only for now (`currency_change_unsupported`, `interval_change_unsupported`).
3. **Next-period change without Paddle support** (assumption): the items are changed at Paddle at once with `do_not_bill`, so the renewal bills the new price without any later call from us; our projection keeps the paid plan and stores until `effective_at` (the period end) and `billing:plan-changes` (every minute) switches it then. While a cheaper plan is scheduled only another cheaper plan or withdrawing it is possible (withdrawing puts the paid items back with `do_not_bill`); an upgrade first needs the withdrawal — otherwise Paddle would prorate from the already lowered items and charge too much.
4. **Proration charge without discounts** (assumption): Paddle does not apply the subscription discount to the immediate charge; the preview says so. The next periods are re-planned (point 5).
5. **Discounts after a change**: the not yet billed next period is planned again on the new price — its uses are given back, the old combined Paddle discount is removed from the subscription and the new one set (ADR 0036 ledger). Unsettled re-planning is retried by `billing:plan-changes`.
6. **Plan-restricted discounts** (assumption): a discount limited to other plans is not applied while the subscription is on a plan it does not cover and its periods are not used up meanwhile; it applies again after a change back.
7. **Store capacity**: when the new plan allows fewer stores than the organisation has, the preview requires the stores to keep (at most the new limit). The others get `capacity_paused_at` when the change takes effect — scheduled and manual browser checks stop, history and setup stay, nothing is deleted; the owner can change the choice (`retained-stores`). If more stores run than the limit and no choice was made, the organisation is `capacity_choice_required` and its browser checks pause until the owner chooses. Store ingestion is not limited by this (spec: entitlement policy). In pilot mode there is no limit and nothing is paused.
8. **Unknown outcome**: a lost Paddle answer leaves the change `unknown` (502 `provider_outcome_unknown`); it is never retried automatically — `billing:plan-changes` reads the subscription and marks it applied/scheduled or failed.
9. **Cancellation**: access stays until the paid period ends, no refund, nothing deleted; repeating it changes nothing; resume only while a cancellation is scheduled. A scheduled plan change survives a cancellation and a resume.
10. **Dashboard**: plan cards of a paid subscription offer "switch to this plan" / "change number of stores"; a panel shows when it takes effect, the charge now, the next payment before and after discounts and the store choice; scheduled change and scheduled cancellation are shown with withdraw/resume; cancelling first explains what happens (until when, what stays, no refund) and asks for an optional reason; a store card shows a capacity pause.

## Open

- Billing interval and currency change, provider payment-method portal, invoices.
- Owner review of points 3, 4, 6 and 7.
