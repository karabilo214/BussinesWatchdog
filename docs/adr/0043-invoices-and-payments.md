# ADR 0043: Invoices and payments of our subscription

Status: implemented (Step 119); points 5–6 are assumptions for the owner's review

## Context

spec/panels/PUBLIC-SUBSCRIPTION-FRONTEND.md §9 (`GET /billing/invoices`, download with a private expiring URL, provider portal for the payment method) and OWNER-PANEL (payments section, KPI). Paddle is the merchant of record: it issues the invoices, collects the tax and keeps its fee; transaction events were already received and sanitized (ADR 0030) but not kept as a list.

## Decisions

1. **Record**: `billing_transactions` — one row per provider transaction of our subscriptions (organisation, contract, status, origin, invoice number, currency and exponent, subtotal, discount, tax, total charged, provider fee, earnings — all minor units —, billing period, billed time). Filled from verified transaction events (the event payload now also keeps discount, grand total, invoice number, billed and created time; no customer details) and by reading a subscription's transactions (`billing:paddle-transactions`, also run by «Refresh from Paddle» in the owner panel). An older read never overwrites a newer state; a transaction without our organisation is not recorded.
2. **Invoice document**: not stored; `GET /api/v1/billing/invoices/{id}/download` asks Paddle for a short-lived link to the PDF (only billed, paid, completed or past-due transactions, 409 otherwise) and returns it with `no-store`.
3. **Payment method**: `POST /api/v1/billing/payment-method` asks Paddle for the subscription's payment-method update transaction and the cabinet opens it with Paddle.js like a checkout; nothing is charged.
4. **Cabinet**: billing page block «Invoices and payments» for the owner of an organisation with a subscription — date and invoice number, period, amount with the tax and discount in it, status, «Invoice PDF», «Change payment method» for a paid subscription.
5. **Owner panel «Payments»** (assumption on the figures): every transaction of the current mode with the organisation and a status filter; «This month» per currency for paid and completed transactions billed in the calendar month (UTC): charged, tax, Paddle fee and what is left (earnings); fee and earnings show «not known yet» while a transaction has none; currencies are never added up. The organisation card shows its last 20 transactions.
6. **What is shown to customers** (assumption): drafts and not yet billed transactions are hidden from the cabinet; cancelled and unpaid ones are shown.

Not in this step: refunds of our subscription (with approval), MRR and other KPIs in «Overview», billing reminder emails.
