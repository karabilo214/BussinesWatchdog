# ADR 0044: Refunds of our subscription

Status: implemented (Step 120); points 6–8 accepted by the owner on 2026-10-13

## Context

spec/panels/OWNER-PANEL (payments, refunds with approval) and PANELS-ACCEPTANCE EXT-19/EXT-20. Paddle is the merchant of record: a refund is a Paddle adjustment (`POST /adjustments`, action `refund`) on a completed transaction, may first wait for Paddle's own review (`pending_approval`) and can also be made by hand in the Paddle dashboard. Payments of our subscriptions are already kept in `billing_transactions` (ADR 0043).

Owner's decisions (2026-10-13): staff with payments access request, the platform owner approves; a refund does not touch the subscription, cancellation is a separate choice.

## Decisions

1. **Record**: `billing_refunds` — organisation, payment, mode, full/partial, amount in minor units of the payment currency (tax included), reason, «cancel the subscription» flag, status, Paddle adjustment id, Paddle status, refunded tax, error code, who requested and who decided with the decision reason and time.
2. **Who**: `refunds.request` — platform owner and billing staff; `refunds.approve` — platform owner only, confirmed with a reason and an authenticator code (`step_up_failed` is audited as denied). The owner may request and approve the same refund. Every step is in the platform audit.
3. **Only completed payments** of the current mode can be refunded. Under a lock of the payment row, requested + being sent + pending + done + unknown refunds together never exceed the payment total; a rejected, declined or failed refund frees its amount. A request beyond it is refused with the amount still available.
4. **Sending**: nothing goes to Paddle before approval. A refund that covers the whole untouched payment is sent as `full`; anything else as `partial`, spread over the payment's line items in their order, each up to its total (tax included — checked in the Paddle sandbox: 1.00 € on a line item came back as 0.84 € net + 0.16 € tax).
5. **Statuses**: Paddle `pending_approval` → «Paddle reviewing», `approved` → «Refunded», `rejected`/`reversed` → «Declined by Paddle». A refused request → «Failed» with the reason; a lost answer → «No answer received», settled by `billing:plan-changes` (every minute) from the payment's adjustments with the same amount, or marked failed after an hour. Adjustment events from Paddle update the status; refunds made in the Paddle dashboard are recorded without a requester. Credits and other adjustment kinds are ignored.

## Assumptions (accepted by the owner)

6. **A refund does not cancel or change the subscription and does not take back access.** The optional checkbox «also cancel at the end of the paid period» runs the usual cancellation right after the approval is sent (a failure of the cancellation is audited and does not undo the refund).
7. **What counts as refunded**: only refunds Paddle confirmed (`approved`). «Payments» shows refunded per payment and «Refunded» per currency for the month by the time Paddle confirmed it; the month's charged amount, tax, fee and earnings stay as Paddle billed them (not reduced by refunds). The customer sees «refunded X» under the invoice in the cabinet.
8. **No limits by time or amount** beyond the payment total (Paddle's own rules still apply: it can refuse a refund, for example a very old one).

Not in this step: customer-initiated refund requests, credit notes in the cabinet, refunds of payments from another mode.
