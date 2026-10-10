# ADR 0022: PayPal Connector (spike)

Date: 2026-10-10

Status: spike in progress (part 1 done on the owner's PayPal sandbox); no connector code yet.

## Context

Spec §10.2: PayPal is a P1 independent provider like Stripe. Before implementation a spike must show which permissions and APIs are needed for reading, whether credentials can be limited to reading, and what the official WooCommerce PayPal Payments plugin records on orders.

## Spike part 0 — plugin source (WooCommerce PayPal Payments 4.1.3)

See `docs/compatibility.md`: transaction id = capture id (authorization id while only authorized), PayPal order id in `_ppcp_paypal_order_id`, sandbox/live in `_ppcp_paypal_payment_mode`, refund ids only as an order-level list `_ppcp_refunds`, WooCommerce order id sent to PayPal as `custom_id`.

## Spike part 1 — REST API on the owner's sandbox (2026-10-10)

Two sandbox REST apps of one business account: "Watchdog" (reads) and "shop" (creates test payments, stands in for the store). The buyer approved payments in a real browser (Playwright, sandbox personal account); captures and refunds were made with the shop app only.

- **Credentials are not read-only.** A client-credentials token of a freshly created app carries, among others, `payments/refund`, `payments/payment/authcapture`, `payments/payouts`, `disputes/update-seller`, `subscriptions`, `vault/payment-tokens/readwrite`. There is no read-only scope in the default app. Still to check: whether unticking app features in the developer dashboard removes these scopes.
- **Transaction Search** (`GET /v1/reporting/transactions`, the only way to *list* transactions) answers `403 NOT_AUTHORIZED` until the app has the Transaction Search feature (scope `…/reporting/search/read` absent). Orders v2 and Payments v2 have no list endpoints — only reads by id.
- **Reads by id work** with the Watchdog app: `GET /v2/checkout/orders/{id}`, `/v2/payments/captures/{id}`, `/v2/payments/refunds/{id}`, `/v2/payments/authorizations/{id}`.
- **Stable link to the store order:** `custom_id` (the plugin sends the WooCommerce order id) is present on the order, the capture, the authorization **and the refund**; `invoice_id` too. A refund links to its capture via its `up` link.
- **A completed PayPal order does not mean captured money.** A EUR payment to a business account without a EUR balance: order `COMPLETED`, capture `PENDING` with reason `RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION` (the seller must accept it); no refund possible yet (422). The connector must treat only capture `COMPLETED` (and later `PARTIALLY_REFUNDED`/`REFUNDED`) as captured money.
- **Capture status changes after refunds** (`COMPLETED` → `PARTIALLY_REFUNDED`) while its amount stays the gross captured amount; the refund is a separate object. A refund can stay `PENDING` (reason `ECHECK` in the sandbox).
- **Authorization**: status `CREATED`, expiry about 29 days; amount is not captured money.
- Fees are in `seller_receivable_breakdown` / `seller_payable_breakdown` (informational, never mixed with captured amounts).
- Card payments through the API were refused for this sandbox business account (`PAYEE_NOT_ENABLED_FOR_CARD_PROCESSING`); PayPal-wallet payments were used.

- **Limiting the app (owner, 2026-10-10):** with every optional feature unticked (Save payment methods, Subscriptions, Invoicing, Payment links and buttons, Payouts, disputes, Log in with PayPal) and Transaction Search ticked, the token lost `payouts`, `subscriptions`, `vault/payment-tokens/*`, `disputes/*`, but **kept `payments/refund`, `payments/payment/authcapture` and `api.paypal.com/v1/payments/.*`**; accepting payments cannot be switched off. Conclusion: **PayPal credentials cannot be limited to reading.** Per spec §10.2 the dashboard MUST warn the owner, ask for a separate app with only Transaction Search ticked (fewer rights than the default app), and the adapter calls only an allowlist of GET endpoints (plus the OAuth token request), enforced by a test. No write call was made with the Watchdog app.
- **Transaction Search** works once ticked (`reporting/search/read` appears). The first call reported `last_refreshed_datetime` about 1.5 hours behind and no transactions yet: listing lags (PayPal documents up to about 3 hours), so the connector cannot rely on it for fast detection.

## Spike part 2 — the plugin on a real checkout (2026-10-10)

`plugins/woocommerce-watchdog/tests/matrix/paypal-gateway-spike.sh`: WooCommerce 11.2 + PayPal Payments 4.1.3 on the sandbox, real browser checkout with the sandbox buyer; results table in `docs/compatibility.md`.

- The order's transaction id is the **capture id** — the exact reference the Watchdog plugin already sends (`transaction_ref`), so exact-reference matching works as for Stripe charges. While an order is only authorized it holds the **authorization id** and the store marks it on-hold without a paid date; on capture the plugin replaces it with the capture id.
- A pending PayPal capture (EUR to a USD account) leaves the order on-hold without a paid date: the plugin does not report it as paid. The store-reported paid marker therefore did not lie in this case; Watchdog still treats only capture `COMPLETED` as money.
- WooCommerce refunds carry no PayPal reference (the refund id goes only into the order's `_ppcp_refunds` list), so the Watchdog plugin cannot send `provider_ref` for PayPal refunds. PayPal refunds still link to the order through their capture (`up` link) and `custom_id`; a store refund can be matched to a provider refund of the same order by amount only as a suggestion, not automatically.
- `custom_id` = WooCommerce order id on order, capture and refund; `invoice_id` = per-site prefix + order number. These are the metadata for a verified-metadata rule like Stripe's `order_id` (there is no site URL in PayPal metadata; the invoice prefix is per installation).

## Open

- Transaction Search on real data (delay, 31-day window, which events appear, how pending captures show), whether its rows carry `custom_id`/`invoice_id`.
- Transaction Search delay and window limits on real data; webhooks; part 2 with the plugin in the WooCommerce matrix (what it records for capture, authorization, pending capture and refunds).
