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

## Still needs the compatibility spike, part 2 (real captures/refunds + WooCommerce Stripe Gateway)

- Which id the gateway stores as the order transaction id (PaymentIntent or charge) in the tested gateway versions — the matcher accepts both, but this must be confirmed.
- Order metadata written by the gateway (order id, site) for the second matching rule (`verified_metadata`), not implemented yet.
- Whether a restricted key can read `/v1/account`; without it the account id stays unverified (shown as such).
- Real field shapes for authorize/capture/refund/failure in the pinned API version; minor units of HUF/ISK/TWD/UGX.

## Not done

Disputes and fees (informational signals), multicapture (a growing `amount_captured` would be a conflict), Stripe Connect / OAuth, PayPal (§10.2), the dashboard screen (Step 66).
