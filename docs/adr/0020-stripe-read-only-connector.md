# ADR 0020: Stripe Read-Only Connector

Date: 2026-10-10

Status: backend implemented in Step 65 and tested with Stripe-shaped fixtures only; shown to the owner for review. The compatibility spike with a real Stripe sandbox and the WooCommerce Stripe Gateway (spec §10) is still required before P1.

## Decisions

- **Key.** Only restricted keys (`rk_test_…`, `rk_live_…`) are accepted; a full secret key answers `stripe_secret_key_refused`. The mode comes from the key prefix. Before anything is stored, read access to payment intents, charges and refunds is probed (`limit=1`); the account is read when the key allows it, and `expected_account_id` must match it. The key and the optional webhook secret are stored encrypted with the keyring (`integration_credentials` kinds `stripe_api`, `stripe_webhook`); only the last four characters of the key are shown. One active Stripe integration per store. Connecting re-queues the store's reconciliation window.
- **API version** pinned in config (`2026-09-30.endive`, the official library's version) and stored on the integration. Calls are GET only.
- **Mapping to normalized events** (the same inbox → projection → reconciliation path as the plugin):
  - `payment.snapshot` comes from the PaymentIntent (`succeeded` → captured, `requires_capture` → authorized, `canceled` → cancelled, `processing`/`requires_action`/`requires_confirmation` → pending, `requires_payment_method` → failed when there is a last payment error, else pending); a failed retry charge can therefore never override a successful one. A charge without a PaymentIntent gives its own snapshot.
  - A **capture** operation (`transaction.observed`, kind capture, id = charge id) is emitted only once the charge is `succeeded` and `captured`, with `amount_captured`. An authorization alone is only the payment status.
  - A **refund** operation (id = refund id) follows the refund status.
  - Objects of the other mode (`livemode`) are skipped; currencies whose Stripe minor unit is unconfirmed (HUF, ISK, TWD, UGX) are skipped until the spike; zero- and three-decimal currencies follow the Stripe list.
- **Idempotency.** Each object's normalized content is hashed; an event is emitted only when it changed. The event id is derived from the previous and the new content, so a webhook and a poll that race produce one event, and returning to an earlier state is still a new event.
- **Provider operation lifecycle (changes the "operations are immutable" rule for independent-provider operations).** An operation keeps its identity — kind, amount, currency, payment — but its status may change (pending → succeeded, succeeded → failed for a bounced refund). The projector accepts only a status change of an independent-provider operation, in observation order, and keeps the history in `metadata.status_history`; any other difference stays a conflict. Store-reported operations stay immutable. An operation that arrives before its payment is attached when the payment arrives.
- **Exact-reference matching (spec §13 rule 1).** A succeeded capture is linked to an order only when exactly one order of the same store, mode and currency has the PaymentIntent, charge or capture id as its transaction reference; the whole captured amount is allocated (`exact_reference`, evidence `exact_reference_v1`). A succeeded provider refund is linked to the one store refund of that order whose `provider_ref` is the refund id (amount = the smaller of the two). Several candidates, or a rejected allocation, link nothing — the payment stays in "Payments without an order" for a person. Amount and time never link automatically.
- **Polling is the guarantee, webhooks only speed it up.** `stripe:sync` every 15 minutes reads objects created since each resource's watermark minus 60 minutes; `stripe:sync --audit` daily at 03:45 UTC re-reads 90 days. Lists are newest-first and page-bounded (20 × 100); an unfinished window is kept as a backlog and continued on the next run instead of advancing the watermark. Manual `POST /integrations/{id}/sync` (delta or audit), at most twice a minute.
- **Webhook** `POST /api/v1/webhooks/stripe/{integration}`: the signature is checked by the official `stripe/stripe-php` library over the raw body (tolerance 300 s); only payment intent, charge and refund objects are used, through the same change-detecting path.
- **Rejected key** (HTTP 401): calls stop, the integration becomes `degraded` with `health.last_error`, the store window is re-queued, and money checks fall back to `unknown / provider_not_connected` until the owner reconnects. Other failures are kept in health and retried.

## Spike, part 1 — real Stripe test account (2026-10-10)

Run against the owner's Stripe test-mode restricted key (kept only in the git-ignored `apps/backend/.env` as `WATCHDOG_DEV_STRIPE_RESTRICTED_API_KEY`), on a temporary store that was removed afterwards. Only ids, statuses, amounts, currency and mode were printed — no customer data.

- Reads of `/v1/payment_intents`, `/v1/charges`, `/v1/refunds` work with the pinned API version and the real response shapes match the mapper (`latest_charge`, `status`, `currency` lower-case, `livemode`).
- `/v1/account` answers **403** for a restricted key without the Account permission: confirmed that connecting must not require it; the integration is created with the account "unverified" (as implemented). `/v1/invoices` is not readable either and is not used.
- The owner's test invoice marked **paid** left one PaymentIntent of 2.00 EUR in status `canceled` with no charge — an invoice marked paid outside Stripe moves no money through Stripe. The connector recorded a cancelled payment and **no capture**, which is the intended semantics (a store-side "paid" marker is not capture evidence).
- Full path verified on real data: connect → audit sync (1 event) → inbox processed → payment projected; a second sync emitted nothing.

## Spike, part 2 — real captures, refunds, failures and an authorization (2026-10-10)

Test-mode payments created by the owner in the Stripe Dashboard: a 5.00 EUR card payment with a 2.00 EUR partial refund, two declined payments (card 4000 0000 0000 0002), a 5.00 EUR payment with "capture funds later" left uncaptured, and the earlier invoice marked paid. Run on a temporary store with an order of 5.00 EUR whose transaction reference is the PaymentIntent id and a store refund of 2.00 EUR naming the refund id; everything was removed afterwards.

| Stripe | Connector result |
|---|---|
| PaymentIntent `succeeded`, charge captured 500 | payment `captured`; capture operation 500 EUR |
| Refund `succeeded` 200 (partial; charge `refunded: false`, `amount_refunded: 200`) | refund operation 200 EUR, linked to the payment |
| Two PaymentIntents `requires_payment_method` with failed charges | payments `failed`; no capture |
| PaymentIntent `requires_capture`, charge `captured: false`, `amount_captured: 0` | payment `authorized`; no capture (authorization is not money received) |
| Invoice marked paid → PaymentIntent `canceled`, no charge | payment `cancelled`; no capture |

- 7 events, all processed; a second sync emitted nothing.
- Exact-reference matching linked the capture (500) to the order and the provider refund (200) to the store refund.
- Reconciliation of the order: `MONEY_CAPTURE_AMOUNT` ok (G = C = 500), `MONEY_REFUND_MISSING` ok (RW = RP = 200).
- Metadata of Dashboard-created objects is empty, as expected; gateway metadata can only be seen with WooCommerce orders.

## Spike, part 3 — WooCommerce Stripe Gateway (2026-10-10)

`plugins/woocommerce-watchdog/tests/matrix/stripe-gateway-spike.sh`: WordPress 7.1 + WooCommerce 11.2 (HPOS) from the matrix, the official WooCommerce Stripe Gateway **11.0.1** in test mode with the owner's test keys (read from the git-ignored `apps/backend/.env`, passed only to the test container), a classic-checkout order paid with the Stripe test payment method `pm_card_visa`, then a partial refund through WooCommerce (`refund_payment = true`).

- **Order transaction id = the charge id** (`ch_…`), not the PaymentIntent; the PaymentIntent id is in order meta `_stripe_intent_id`. The exact-reference matcher accepts the charge through the payment's `latest_charge` — covered by a backend test with a charge reference.
- **Refund**: `_stripe_refund_id` (`re_…`) on the WooCommerce refund, `refunded_payment = true`. The Watchdog plugin did not send `provider_ref` at all, so automatic refund linking could never work for real refunds — fixed: the plugin now sends `_stripe_refund_id` as `provider_ref` (only that confirmed key).
- **Stripe metadata written by the gateway** on the PaymentIntent and the charge: `order_id`, `order_key`, `site_url`, `signature`, `payment_type`, … plus `customer_email` and `customer_name`. The adapter keeps no metadata, so no customer data reaches Watchdog. The second matching rule (`verified_metadata`: `order_id` + `site_url` equal to the store origin) can be built on `order_id`/`site_url` only — not implemented yet.
- Order meta also has `_stripe_charge_captured = yes`, `_stripe_fee`, `_stripe_net` (fees stay out of the money comparison, spec §12).
- The spike created one test payment of 10.00 EUR with a 3.00 EUR refund in the owner's Stripe test account.

## Verified-metadata matching (spec §13 rule 2, Step 67)

- The adapter takes exactly three values from Stripe metadata: `order_id` (as `provider_order_ref`, `[A-Za-z0-9_-]{1,255}`), the origin of `site_url` (`provider_site_origin`, scheme + host + port, lower-case) and the SHA-256 of `order_key` (`provider_order_key_hash`; the key itself is never stored or sent on). They are optional fields of `payment.snapshot` (event schema extended) and are kept in `payments.metadata`. Customer email/name in the same metadata are never read.
- Rule order: the exact reference first; only when **no** order of the store has the reference, metadata may link: the order whose store id equals `provider_order_ref`, same mode and currency, and only if the store's domain is **confirmed** and its origin equals `provider_site_origin`. Several candidates or an unconfirmed domain link nothing. Strategy `verified_metadata`, evidence `verified_metadata_v1` with the order ref and origin. After a capture is linked, the provider refunds of that payment are tried as well.
- **Guards against reused order numbers (found in the one-run chain, see below).** Metadata never links an order that already carries its own transaction reference (that order names a different payment). When the payment carries an order-key hash, the order must carry the same hash (`order.snapshot` gains optional `order_key_hash`, sent by the plugin as SHA-256 of the WooCommerce order key, kept in `orders.metadata`); an order without a hash (older plugin) is then not linked by metadata. Without these guards a store whose order ids repeat (a restored backup, a recreated test site) would get old Stripe payments attached to a new order.
- Matching is retried when an order arrives after its payment (order snapshot), right after a store domain is confirmed, and in the nightly sweep for every unlinked provider capture.
- Verified on real data: the payment created by the WooCommerce Stripe Gateway in spike part 3 (metadata `order_id = 12`, `site_url = https://shop-latest.example.test`) linked by metadata to order 12 that had no transaction reference; its refund linked by `_stripe_refund_id`; reconciliation ok for capture and refund.

## Payment mode from the plugin

- Assumption (owner to review): the plugin records the Stripe mode of an order once, when it is paid or authorized (`woocommerce_payment_complete`, or a status change to processing/completed/on-hold), from the gateway setting `woocommerce_stripe_settings.testmode` at that moment, into order meta `_bw_payment_mode`, and sends it as `mode` with the order snapshot. Only orders of the Stripe gateways are labelled; others keep no mode (the backend default stays `live`). Switching the gateway to live mode later does not relabel orders that were paid in test mode. Without this, test-mode orders were projected as `live` and never matched test-mode Stripe payments.

## One-run full chain (`tests/matrix/stripe-chain-e2e.sh`)

- One script on the owner's real Stripe test account: backend + WooCommerce 11.2 (HPOS) + Stripe Gateway 11.0.1 → verified store, Stripe connected with the restricted key → plugin paired → real classic checkout (`pm_card_visa`, 10.00 EUR) → partial refund 3.00 through WooCommerce → plugin delivers its events → Stripe audit sync → matching → reconciliation.
- Result: order mode `test`, transaction ref the charge id; capture linked `exact_reference` 1000, refund linked `exact_reference` 300 by the plugin's `provider_ref`; `MONEY_CAPTURE_AMOUNT` ok, `MONEY_REFUND_MISSING` ok. The Stripe integration and its credentials are revoked at the end.
- The first full run failed: the test account already held payments from earlier runs with the same metadata (`order_id = 12`, same site), and metadata linked them to the new order (capture amount and multiple-captures mismatches). Fixed by the guards above; covered by a backend test.

## Not done

Disputes and fees (informational signals), multicapture (a growing `amount_captured` would be a conflict), Stripe Connect / OAuth, PayPal (§10.2), the dashboard screen (Step 66).
