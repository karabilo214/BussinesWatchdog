# ADR 0081: Disputes (chargebacks) as an informational signal

Date: 2026-10-26

Status: accepted (owner's decisions 2026-10-26; Stripe in Step 276, PayPal in Step 277)

## Context

Spec §12 lists `dispute_debit` and `dispute_credit` as financial operations and says disputes are separate informational signals: they are not part of the net comparison of an order without an explicit capability. ADR 0020 left "disputes as an informational signal" open. A dispute has a deadline. If the store does not answer at the provider in time, it loses the money, so the merchant must hear about it in time.

## Decisions

1. **Own event, not a money operation.** The provider adapter emits `dispute.snapshot` (aggregate `dispute`, revisioned) with:
   - payment, charge, mode, currency, amount;
   - status (Stripe's eight statuses, including the `warning_*` inquiries);
   - the reason code, the answer deadline and when the dispute was opened.

   The projector keeps the newest revision in `provider_disputes`, so duplicates and reordered deliveries change nothing. The dispute is linked to its payment when that payment is known, or later, when the payment arrives. No `financial_transactions` row is written, and the money reconciliation (captures, refunds, net) does not change. The contract kinds `dispute_debit` and `dispute_credit` stay unused for now.
2. **Stripe only in this step** (owner's decision):
   - polling reads `/v1/disputes` created in the last `WATCHDOG_STRIPE_DISPUTE_DAYS` (default 120) on every run, because a dispute's status changes long after it was created. Unchanged disputes emit nothing;
   - webhooks with a dispute object (`charge.dispute.*`) feed the same path;
   - only the listed fields are read. Dispute evidence, metadata and customer data are never read or stored.

   PayPal disputes are a separate backlog item.
3. **Permission is optional** (owner's decision). The restricted key instruction now asks for `Disputes: Read`, but connecting does not require it. A key without it keeps syncing payments. The integration health shows `sync.disputes.state = permission_missing`, and the cabinet shows a hint on the Stripe panel. It is not a connection error.
4. **One incident per dispute** (owner's decision):
   - family `dispute`, severity `warning`, title `DISPUTE_OPEN` (or `DISPUTE_INQUIRY` for a `warning_*` inquiry);
   - fingerprint per integration and dispute id, stored on the dispute;
   - it opens while the dispute is open (`needs_response`, `under_review` and the two inquiry states) and resolves itself on the outcome: `auto_resolved_dispute_won`, `_lost` or `_closed` (for `warning_closed` and `prevented`);
   - a dispute that is already closed the first time the service sees it (for example, the first sync of an existing account) opens no incident. It is still kept and shown on the order.
5. **Notifications** (owner's decision):
   - the channels' usual rules apply: e-mail or Telegram when the incident opens, with order, disputed amount, reason, deadline and what to do; the recovery notice names the outcome;
   - once, when the answer is due within 3 days, `disputes:remind` (hourly) asks for a reminder of the incident;
   - the daily digest counts the incidents like any other.
6. **Amounts follow `money.view`.** The dispute block of the incident API and the `disputes` lists of the order and payment cards return `amount_minor: null` without `money.view`. Signal evidence holds no amounts.
7. **Deletion.** `provider_disputes` is purged with the store (`StoreDeletion`) and the organisation (`OrganizationPurger`).

8. **PayPal (Step 277).** Same event, table, incident and notifications. Differences:
   - the list (`GET /v1/customer/disputes`, `update_time_after` = watermark minus the PayPal overlap, first run `WATCHDOG_PAYPAL_DISPUTE_DAYS`, default 180) only names disputes; every listed dispute is read by id (`GET /v1/customer/disputes/{id}`, at most 50 per run, 5 list pages). The webhook `CUSTOMER.DISPUTE.*` is only a hint: the dispute is read through the API, nothing from the body is stored;
   - the dispute names the capture (`disputed_transactions[].seller_transaction_id`); it is linked to the PayPal order through the existing capture → order lookup, so `payment_external_id` is the PayPal order id like the payment; without it the capture id is kept;
   - status mapping: `WAITING_FOR_SELLER_RESPONSE` → `needs_response` (`warning_needs_response` in the `INQUIRY` stage), other open states → `under_review` (`warning_under_review`); `RESOLVED` with `RESOLVED_BUYER_FAVOUR` or `ACCEPTED` → `lost`; in the inquiry stage otherwise → `warning_closed`; `RESOLVED_SELLER_FAVOUR`, `RESOLVED_WITH_PAYOUT` (PayPal paid the buyer itself) or `DENIED` → `won`; an unknown outcome → the new status `closed` (resolved, outcome not given);
   - the deadline is `seller_response_due_date`, only while the seller must answer; the reason is PayPal's code in lower case (translated in the cabinet and the letters); buyer data, messages and evidence are never read;
   - access: the app's Customer disputes add-on (developer dashboard: Features → Add-on services) is optional. Without it PayPal answers 403, the health shows `permission_missing` and the PayPal panel shows a hint. Any other error of the dispute calls (`unavailable`) never fails the payment sync. Enabling Disputes also grants the write scope `disputes/update-seller`, which the service lists among the app's write scopes and never uses.

## Consequences

- A lost dispute is not a discrepancy: the order stays "reconciled" if captures and refunds match. The incident and the order card say what happened.
- Existing Stripe connections see disputes once their key has `Disputes: Read`. Until then, the hint stays on the Stripe panel.
- Each run reads the newest disputes of the window up to the page budget. Disputes beyond it, the oldest ones, are not read. This is acceptable for the volumes expected; the sync health records `complete: false` when it happens.
