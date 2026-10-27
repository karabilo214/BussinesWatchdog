# ADR 0022: PayPal Connector (spike)

Date: 2026-10-10

Status: spike done (parts 0–2); connector implemented in Step 70. Owner review 2026-10-10: refund link by unique amount — accepted for now (to be revisited with real data); coverage per gateway/provider — accepted; metadata matching — not supported for PayPal, shown as such in the dashboard.

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
- Fees are in `seller_receivable_breakdown` / `seller_payable_breakdown` (informational, never mixed with captured amounts; shown since Step 279, ADR 0083).
- Card payments through the API were refused for this sandbox business account (`PAYEE_NOT_ENABLED_FOR_CARD_PROCESSING`); PayPal-wallet payments were used.

- **Limiting the app (owner, 2026-10-10):** with every optional feature unticked (Save payment methods, Subscriptions, Invoicing, Payment links and buttons, Payouts, disputes, Log in with PayPal) and Transaction Search ticked, the token lost `payouts`, `subscriptions`, `vault/payment-tokens/*`, `disputes/*`, but **kept `payments/refund`, `payments/payment/authcapture` and `api.paypal.com/v1/payments/.*`**; accepting payments cannot be switched off. Conclusion: **PayPal credentials cannot be limited to reading.** Per spec §10.2 the dashboard MUST warn the owner, ask for a separate app with only Transaction Search ticked (fewer rights than the default app), and the adapter calls only an allowlist of GET endpoints (plus the OAuth token request), enforced by a test. No write call was made with the Watchdog app.
- **Transaction Search** works once ticked (`reporting/search/read` appears). The first call reported `last_refreshed_datetime` about 1.5 hours behind and no transactions yet: listing lags (PayPal documents up to about 3 hours), so the connector cannot rely on it for fast detection.

## Spike part 2 — the plugin on a real checkout (2026-10-10)

`plugins/woocommerce-watchdog/tests/matrix/paypal-gateway-spike.sh`: WooCommerce 11.2 + PayPal Payments 4.1.3 on the sandbox, real browser checkout with the sandbox buyer; results table in `docs/compatibility.md`.

- The order's transaction id is the **capture id** — the exact reference the Watchdog plugin already sends (`transaction_ref`), so exact-reference matching works as for Stripe charges. While an order is only authorized it holds the **authorization id** and the store marks it on-hold without a paid date; on capture the plugin replaces it with the capture id.
- A pending PayPal capture (EUR to a USD account) leaves the order on-hold without a paid date: the plugin does not report it as paid. The store-reported paid marker therefore did not lie in this case; Watchdog still treats only capture `COMPLETED` as money.
- WooCommerce refunds carry no PayPal reference (the refund id goes only into the order's `_ppcp_refunds` list), so the Watchdog plugin cannot send `provider_ref` for PayPal refunds. PayPal refunds still link to the order through their capture (`up` link) and `custom_id`; a store refund can be matched to a provider refund of the same order by amount only as a suggestion, not automatically.
- `custom_id` = WooCommerce order id on order, capture and refund; `invoice_id` = per-site prefix + order number. These are the metadata for a verified-metadata rule like Stripe's `order_id` (there is no site URL in PayPal metadata; the invoice prefix is per installation).

## Recheck (2026-10-10, owner unticked every feature except Transaction Search)

The token still carries `payments/refund`, `payments/payment/authcapture`, `api.paypal.com/v1/payments/.*` and `wallet/mandates/write`; only some read-only client scopes disappeared. The conclusion stands.

## Transaction Search on real data (2026-10-10)

At 15:01 UTC `last_refreshed_datetime` was 13:29:59 (about 1.5 h behind). Rows: payments `T0006` with `transaction_id` = capture id, `custom_field` = WooCommerce order id; refund `T1107` with `transaction_id` = refund id and `paypal_reference_id` (type `TXN`) = the refunded capture. The pending EUR captures and the pending (eCheck) refund were not listed yet — they reach the service through the webhook or a later poll/audit.

## Decisions (Step 70, connector)

- **Connecting** (`POST /stores/{id}/integrations/paypal`): environment sandbox/live (stored as mode test/live), client id and secret of a dedicated REST app, optional webhook id, and `write_access_acknowledged = true` — without it nothing is sent to PayPal. The app must have Transaction Search (scope `reporting/search/read`, probed with a real search call). The write scopes found in the token are stored as `health.write_scopes` and shown to the owner. Credentials are keyring-encrypted (`paypal_client`, `paypal_webhook`).
- **Read-only by construction**: `PayPalClient` sends only the OAuth token request, `verify-webhook-signature`, and GETs matching an allowlist (transactions search, orders, captures, refunds, authorizations by id); anything else is refused before it leaves the service. A test runs connect, delta, audit and a webhook and checks every recorded request against the allowlist.
- **The unit of sync is the PayPal order**: every source (search row, webhook) is resolved to its PayPal order (capture `up` link, cached), and the whole order is read and mapped: one `payment.snapshot` (intent_ref = PayPal order id, charge_ref = first capture or authorization id, `provider_order_ref` = `custom_id`) plus captures and refunds as operations. Payment status: captured if any capture is COMPLETED/PARTIALLY_REFUNDED/REFUNDED, pending if a capture is PENDING, authorized for an open authorization, failed/cancelled otherwise. Only those capture statuses are captured money; a PENDING capture is not an operation. Amounts are converted as strings (HUF, JPY, TWD without decimals; unknown currencies skipped). Payer data is never read; webhook bodies are only hints and are not stored.
- **Polling**: delta every 15 minutes from the watermark minus 180 minutes; the watermark never passes `last_refreshed_datetime`. Windows of at most 31 days, a budget of 300 PayPal reads per run. The 90-day audit starts once a day and continues hourly from a cursor when the budget runs out. Event codes T00xx are payments (capture id), T11xx refunds/reversals (via `paypal_reference_id`); other rows are not used.
- **Webhook** (`POST /webhooks/paypal/{id}`): PayPal's verify-webhook-signature with the stored webhook id over the raw body, transmission time within 600 s and a PayPal certificate host; then the affected order is read through the API.
- **Refund link by unique amount (new rule, provider-neutral)**: WooCommerce refunds of PayPal orders carry no PayPal refund id. Inside an order already linked to a payment, when exactly one unlinked store refund without `provider_ref` and exactly one unlinked succeeded provider refund of that payment have the same amount and currency, they are linked with strategy `unique_amount` (evidence `unique_amount_v1`). Any ambiguity (two equal amounts on either side) links nothing; a person links it manually. Store refunds that carry `provider_ref` keep the exact rule only.
- **Coverage per gateway**: an order whose gateway belongs to a known provider (Stripe gateways → stripe, `ppcp-*` → paypal) is only checked when that provider is connected; otherwise it stays `unknown / provider_not_connected`. A store with only Stripe connected therefore does not report PayPal orders as missing captures. Orders of unknown gateways keep the store-wide rule.
- **Plugin**: PayPal Payments gateways (`ppcp-*`) are reported as supported; their test/live mode is read from PayPal Payments' own `_ppcp_paypal_payment_mode` (sandbox → test), not copied.
- **Metadata matching** (`verified_metadata`) is not supported for PayPal: PayPal metadata has no site URL to compare with the confirmed store domain. PayPal payments link by the exact capture id only; the PayPal tab says so (owner decision 2026-10-10).
- Verified on the owner's sandbox: connecting the real Watchdog app (write scopes capture, payments_v1, refund reported), audit sync over Transaction Search and reading the spike orders: captured/refunded, pending EUR captures, an authorization — all mapped as described.

## One-run chain (2026-10-11, Step 71)

`plugins/woocommerce-watchdog/tests/matrix/paypal-chain-e2e.sh latest`: local backend with the store's PayPal connected through the connector (the real Watchdog app, sandbox), WooCommerce 11.2 (HPOS) + PayPal Payments 4.1.3 (shop app) + the Watchdog plugin paired with that backend; the sandbox buyer pays 10.00 USD in the PayPal popup, WooCommerce refunds 3.00 through PayPal, the plugin delivers, the backend syncs, links and reconciles. Result PASS in one run: order mode test, gateway `ppcp-gateway`; capture linked by `exact_reference` (1000), the store refund without a PayPal id linked by `unique_amount` (300); `MONEY_CAPTURE_AMOUNT` and `MONEY_REFUND_MISSING` ok.

What the run shows:

- Transaction Search lagged about 2 h (run at ~06:30 UTC, `last_refreshed_datetime` 04:29:59), so the delta sync found only older sandbox payments of the same PayPal account (8 events; they become payments without an order in this store — one PayPal account means all its payments). The fresh payment reached the service through the capture → order path a webhook uses (`orderOfCapture` + `syncOrder`), driven here by the capture id from the store order. A real webhook delivery with PayPal's signature was **not** exercised (no public URL registered in the Watchdog app).
- Without a webhook a fresh PayPal payment is seen only after the Transaction Search lag (1.5–3 h); until then the order stays `MONEY_CAPTURE_MISSING` within its grace or later. Proposal: use the store-reported capture id of a `ppcp-*` order only as a pointer — accepted by the owner and implemented in Step 72 (below).
- Test-environment notes: a fresh matrix WordPress restarts order numbers and PayPal refuses a repeated invoice id (`DUPLICATE_INVOICE_ID`), so each run sets a unique PayPal Payments invoice prefix; pairing needs the store's `https://` base URL, the browser checkout runs over `http://` on the same host.

## Capture-id pointers (Step 72, owner decision 2026-10-11)

- Every minute (`paypal:sync --pointers`) the service takes the store's orders of `ppcp-*` gateways updated in the last 6 hours, in the integration's mode, whose `transaction_ref` has no PayPal operation yet (at most 50 per run), reads that capture at PayPal (`GET /v2/payments/captures/{id}`), follows it to its PayPal order and maps the whole order as usual. The store value is only an address: money exists only if PayPal returns it; an id PayPal does not know (an authorization id before capture, another account, a typo) produces nothing.
- Each pointer is read at most once per 15 minutes (pending captures, unknown ids); a linked capture is not read again. Older orders rely on Transaction Search and the daily audit. All calls stay inside the GET allowlist.
- Real check: the one-run chain now uses the pointer pass instead of a simulated webhook hint — PASS (Transaction Search refreshed to 04:59:59 at ~06:55 UTC; the fresh payment came through the pointer, 1 read, 3 events).

## Webhook confirmation for the merchant (2026-10-27, backlog 11.G)

`GET /v1/notifications/webhooks` is added to the GET allowlist: «Check set-up» lists the app's webhooks and looks only at the one with this connection's address — its ID equals the saved Webhook ID (`webhook_id_mismatch` otherwise) and it listens to the capture and order events. Other webhooks of the app are not stored. Any event that PayPal verifies counts as proof of delivery (`health.webhook`); an event with PayPal's headers that does not verify with the saved Webhook ID is kept as `health.webhook_rejected`. Simulator events are not used: PayPal cannot verify them. Saving a new Webhook ID clears both and the last check.

## Open

- ~~A real PayPal webhook delivery~~ — done 2026-10-27 (Step 283): sandbox `PAYMENT.CAPTURE.COMPLETED` verified with the saved Webhook ID on production.
