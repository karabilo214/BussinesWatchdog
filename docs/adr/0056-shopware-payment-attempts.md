# ADR 0056: Observing real payment attempts in Shopware stores

Status: accepted for now (owner's backlog item 2026-10-14: server-side observation of real Shopware payment attempts, whatever way the browser check uses). Details below were chosen by the implementer and are to be confirmed by the owner.

## Context

For WooCommerce the plugin observes checkout submissions and their outcomes and sends 5-minute aggregates per payment method (`checkout.payment_attempts`, ADR 0007 layer 1); the rule `CHECKOUT_PAYMENTS_FAILING` opens a `checkout_payment` incident after 3 non-successful outcomes in a row for one method. Shopware has no code of ours inside the shop: the App only declares read permissions and webhooks (ADR 0027), the integration key only reads (ADR 0026). But the service already reads every order with all its payment transactions (state, payment method, creation and change time) — on webhooks right away (App) and every 5 minutes (delta).

In Shopware, «Submit order» creates the order and an `order_transaction` before payment; the payment handler then moves the transaction (`paid`, `authorized`, `failed`, `cancelled`, …). When a customer retries with another method, Shopware adds a new transaction to the same order and cancels the previous one. Offline methods (prepayment, invoice, cash on delivery, direct debit) leave the transaction `open` by design.

## Decisions

1. **Attempt = one payment transaction** of a Shopware order, created after the order source was connected (older transactions are history, not observation). Method = the payment method's technical name. Derived by the service from the order reads it already makes; nothing new is read and no personal data is involved (transaction and order ids only).
2. **Outcome** from the transaction state:
   - `paid`, `paid_partially`, `authorized`, `refunded`, `refunded_partially`, `chargeback` → **paid** (the checkout worked);
   - offline method (`payment_prepayment`, `payment_invoice`, `payment_cashpayment`, `payment_debitpayment`, or a core prepayment/invoice/cash/debit handler) still `open` → **on_hold** (success, money expected later — as WooCommerce `on-hold`);
   - `failed` → **failed** (`status_failed`); `cancelled` → **failed** (`retried` when the order has a later transaction, otherwise `cancelled`);
   - otherwise (`open`, `in_progress`, `unconfirmed`, `reminded`) → pending; **pending_stuck** (`no_result`) 30 minutes after the attempt; a stuck attempt paid later becomes **late_success**.
3. **Windows**: each attempt is counted in the 5-minute UTC window of the time its outcome became known (state change time, or attempt + 30 min for stuck), per method; windows are recomputed from the attempts whenever an attempt changes, so repeated reads and replays change nothing. The same `payment_attempt_windows`, rule `CHECKOUT_PAYMENTS_FAILING` (3 in a row, any success resolves), incident texts, entitlement `payment_attempts_server` and coverage («observing», «no attempts seen», «no recent attempts») as for WooCommerce. `rejected_before_order` is always 0: Shopware does not tell the service about checkouts refused before an order exists — that is what the checkout script (layer 2) is for.
4. **Delay**: with the App, outcomes are seen within seconds (webhooks); with the integration key, within the 5-minute delta. A shop without a webhook for `in_progress`/`unconfirmed` still converges at the next delta.
5. **Declines**: as for WooCommerce without a provider, a bank decline and a broken integration look the same (`failed`); the notification says so.

## Not decided / for the owner

- 30 minutes before an unanswered payment counts as stuck (same as WooCommerce).
- Which plugin payment methods are offline: only the four core methods are known; a plugin's offline method would count as stuck after 30 minutes until added to the list.

## Verification

`tests/Feature/Providers/ShopwarePaymentAttemptsTest.php` and `plugins/shopware-app/tests/attempts/attempts-e2e.sh` on Shopware 6.6.10.6 and 6.7.2.2 (integration key, real order reads, payments failed and paid by state transitions) — PASS.
