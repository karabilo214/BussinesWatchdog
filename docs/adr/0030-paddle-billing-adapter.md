# ADR 0030: Paddle billing adapter

Status: implemented in sandbox (Step 98); owner decisions on points 6–8 recorded 2026-10-11

## Context

ADR 0028 chose Paddle as merchant of record; ADR 0029 added plans, extra-store prices and entitlements. The adapter must follow spec/panels/ADMIN-BACKEND.md §3–§5: a local checkout record before the provider call, no database transaction held during it, access only from verified provider state, webhook over raw bytes into a sanitized inbox, processing by fetching the current provider state, recovery when webhooks are missed.

## Facts from the sandbox (2026-10-11, real calls and a real test-card payment)

- A product per plan version; prices carry amount in minor units as a string, a monthly cycle and quantity bounds (base 1..1, extra store 1..`max_extra_stores`). `tax_mode: account_setting` came back as `location`.
- A transaction needs a default payment link in the account (Checkout settings); it may be any https URL, the overlay checkout runs on our page by transaction id.
- Paying the transaction (19 € + 2 × 9 €) produced a customer, a subscription `active` for one month with both items and quantities, and `custom_data` copied from the transaction to the subscription.
- For a German buyer the 37 € were **gross**: 5.90 € VAT inside, Paddle fee 2.30 €, earnings 28.80 €.
- The event log (`GET /events?order_by=id[ASC]&after=evt_…`) lists every event in id order, so missed webhooks can be recovered from it.
- A scheduled cancellation keeps the subscription `active` with `scheduled_change.action = cancel` until the period end.

## Decisions

1. **Checkout**: `POST /api/v1/billing/checkout` (owner only) takes our base price id and the number of extra stores; we create a Paddle transaction with our tenant and checkout ids in `custom_data`, return the client-side token and the transaction id, and the browser opens Paddle.js. A new checkout cancels the previous pending one (also at Paddle). One live subscription per tenant: a second purchase is 409 (plan change comes later).
2. **Who owns a subscription**: only a transaction created by our server (`origin: api`) for one of our checkout sessions links a subscription to a tenant. Custom data alone is not trusted — anyone holding the public client token could open a checkout with someone else's tenant id. Unlinked subscriptions are quarantined.
3. **Events**: webhook (signature `ts:body` HMAC-SHA256, 300 s) and the event log feed one inbox, deduplicated by event id; same id with different content is a conflict and is not applied. Only ids, states, price ids and quantities, periods, totals and our custom data are kept — no names, emails, addresses, payment data or tokens.
4. **Processing**: every event that names a subscription makes us read the subscription from Paddle as it is now (event order does not matter; an older read never overwrites a newer one). Paddle `active/trialing/past_due/paused/canceled` → contract `active/trial/past_due/unpaid/cancelled`; an unknown price makes the contract `unknown` and changes nothing in access. The tenant's subscription row (ADR 0029) takes the live contract; `paid_until` is Paddle's current billing period end, not "paid at + 30 days".
5. **Recovery**: `billing:paddle-events` reads the event log every minute (cursor kept two minutes behind now), `billing:process-events` every 30 s; failures retry with backoff, then dead letter.
6. **Prices are gross for consumers** (owner 2026-10-11: keep gross for now, to be checked with a tax adviser; changing later means new price versions): with Paddle's location tax mode the price we set is what an EU consumer pays including VAT; Paddle handles reverse charge for businesses with a VAT id. The alternative is net prices plus VAT on top.
7. **Trial — our own, without a payment method** (owner 2026-10-11: no payment up front attracts customers). `POST /api/v1/billing/trial`: tenant owner with a verified email, once per tenant (`tenants.trial_consumed_at`, set under the tenant lock and kept when the row is replaced), for the plan version's `trial_days`; a purchase replaces the trial, an unpaid trial ends in `paused_billing`. Paddle trials are not used. Open: one trial per tenant only — a person can create several organisations; owner 2026-10-11: limit trials by store address (domain) — to be implemented next.
8. **Legal acceptance** (owner 2026-10-11: decide when the pages exist): the spec's checkout records accepted policy versions; our terms and privacy pages do not exist yet, so the request has no `policy_version_ids` until they do. Paddle shows its own buyer terms as merchant of record.

## Not verified yet

- A real webhook delivery from Paddle (needs a public URL; the event log path was verified instead). The signature format is tested against Paddle's documented scheme.
- Live account, live domain approval, payouts.
