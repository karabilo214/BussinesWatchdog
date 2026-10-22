# ADR 0025: Shopware 6 connector

Status: decisions accepted by the owner (2026-10-11); spike parts 1–2 done, connector next

## Context

ADR 0023 puts Shopware 6 second (Germany/DACH). The research prefers a Shopware App with limited permissions, or as a start an Integration with a limited role (Settings → System → Integrations), never an administrator key.

## Spike part 1 — Admin API with a read-only Integration (2026-10-11)

`plugins/shopware-app/tests/spike/run.sh`: dockware/play with **Shopware 6.7.2.2** (newest image available; dockware has not published newer ones) and demo data. An ACL role with only `:read` privileges (order, order_transaction, order_transaction_capture, order_transaction_capture_refund, order_delivery, order_line_item, currency, payment_method, state_machine, state_machine_state, sales_channel, version) and an Integration with that role, created as an owner would. Orders placed through the Store API (guest, prepayment), then changed in the admin (paid, partially refunded, cancelled, deleted).

- **Auth**: access key `SWIA…` (26 chars) + secret (51 chars), OAuth `client_credentials`, token lifetime 600 s.
- **Reading**: `POST /api/search/order` with criteria (filters, sorting, paging, associations, `includes`) and `GET /api/order` both work with the read role. `includes` cuts a page from 54 KB to 6.6 KB and keeps personal data out. Without `includes` the order's `orderCustomer` (name, e-mail) is returned **even though the role has no customer privilege**; asking for the `orderCustomer` association explicitly is 403. Customers, users, products are 403.
- **Money**: `amountTotal`, `price.totalPrice`, transaction `amount.totalPrice` are JSON floats (19.99); the currency's `totalRounding.decimals` (2 for EUR) gives the precision. Conversion must go through the rounding decimals, never raw float arithmetic.
- **States**: order `stateMachineState.technicalName` (open, in_progress, cancelled, completed), transaction states (open, paid, refunded_partially, refunded, cancelled, …), payment method `technicalName`/`handlerIdentifier`. Captures/refunds entities (`order_transaction_capture`, `…_refund`) exist but are only filled by payment apps; core payment methods leave them empty.
- **Change detection**: a new order has `updatedAt = null`. A transaction state change updates only the transaction's `updatedAt`, **not the order's**. A delta query therefore needs `updatedAt ≥ T OR createdAt ≥ T OR transactions.updatedAt ≥ T` (tested, works).
- **Deletion**: deleted orders are 404 and disappear from `search-ids`; no trash. Same audit approach as WooCommerce.
- **Writes with the read role**: PATCH/DELETE order, `_action/sync` upserts, product create — 403. **But the state transition endpoints have no ACL** (`/api/_action/order/{id}/state/{t}`, `/order_transaction/{id}/state/{t}`, `/order_delivery/{id}/state/{t}` carry no `_acl` in Shopware 6.7.2.2): with the read-only key the spike moved an order open → in_progress, a payment to refunded and a delivery to shipped. The generic `/api/_action/state-machine/{entity}/{id}/state/{t}` route does check the `update` privilege (403 for the same key), so the three specific routes are inconsistent with it — a bug, not a design choice. These transitions can also send e-mails to customers. The endpoint that actually refunds money through the payment handler (`/api/_action/order_transaction_capture_refund/{id}`) is protected (`order_refund.editor`). So, like PayPal, **a Shopware key cannot be limited to reading**: it cannot move money or edit order data, but it can change order, payment and delivery statuses.
- **Telling a read-only key from a write key without writing**: not possible with an Integration. Validation runs before the ACL check (an impossible value gives 400 for both keys); a valid write gives 403 only for the read key but would write with a write key. Reading the integration's own privileges needs `integration:read` + `acl_role:read`, which also exposes the access keys and bcrypt secret hashes of every other integration — not acceptable to ask for.

## Decisions (owner, 2026-10-11)

1. **Accepted. Start with the Integration path** (like the WooCommerce key): the owner creates a role from our checklist and an Integration, and enters the access key and secret. The service calls only `POST /api/search/order` (+ `search-ids` for the audit) and the OAuth token, from an allowlist, always with `includes` so no personal data is received.
2. **Accepted. Warn like PayPal**: the connection screen says the key can change order/payment/delivery statuses despite the read role (a Shopware limitation), cannot move money or edit orders, and must be kept secret; the owner acknowledges it.
3. **Accepted. Read-only cannot be verified** for an Integration: the service relies on the checklist and the acknowledgement. The **Shopware App** (next part) declares its permissions in the manifest, which the merchant approves at install — that is the verifiable path and also brings webhooks. Plan: Integration first, App as part 2/3.
4. **Accepted: report the missing ACL on state transitions to Shopware** through their security contact (responsible disclosure, not published). The owner sends it; the service does not contact third parties itself. Draft prepared outside the repository.
5. **Accepted: two supported versions, 6.6 and 6.7**; more on demand. Shopware 6.6.10.6 (dockware) behaves exactly like 6.7.2.2 in part 1, including the missing ACL.

## Spike part 2 — payment plugin data (source of SwagPayPal 10.8.5, GitHub 2026-10-08; not executed yet)

- SwagPayPal stores on the **order transaction's custom fields**: `swag_paypal_order_id` (PayPal order), `swag_paypal_resource_id` (the **capture id**, or the authorization id for authorize intent — same pattern as PayPal Payments for WooCommerce), `swag_paypal_is_sandbox` (test/live mode). These are readable with `order_transaction:read`.
- Refunds are made through the plugin's admin module against the PayPal API; Shopware then only moves the transaction state to `refunded` / `refunded_partially` (webhook `PAYMENT.CAPTURE.REFUNDED` as well). **No refund amount or refund id is stored in Shopware**; the core `order_transaction_capture_refund` entities stay empty.
- Consequence for reconciliation: in Shopware the store-reported refund is a payment state without an amount. The provider (PayPal/Stripe connector) has the amounts. Rules must work with "store says refunded / partially refunded" versus provider refunds — a modelling decision for the connector step (ADR to follow, owner review).
- Stripe for Shopware (Pickware's plugin) not examined: its source is not public; needs an installed copy.


## Spike part 3 — real PayPal sandbox money through SwagPayPal (2026-10-11, Step 87)

`plugins/shopware-app/tests/paypal/chain.sh` on Shopware 6.7.2.2 + SwagPayPal 10.8.5 and Shopware 6.6.10.6 + SwagPayPal 9.13.4 (composer; 10.x needs Shopware 6.7), configured with the owner's sandbox shop app. Guest orders through the Store API on the shop host, Shopware's `handle-payment`, the sandbox buyer approves in Chromium, PayPal returns to the shop and Shopware finalizes; refunds made through the plugin's admin route (`/api/_action/paypal-v2/refund-capture/…`, protected by `order_refund.editor`).

- Confirmed: the transaction's custom fields hold `swag_paypal_order_id`, `swag_paypal_resource_id` = the **capture id** and `swag_paypal_is_sandbox = true`. SwagPayPal sends PayPal `invoice_id` = Shopware order number and `custom_id` = JSON with the Shopware transaction id.
- A EUR payment to the sandbox account without EUR balance stays capture `PENDING` (`RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION`) and Shopware keeps the transaction `in_progress`, not paid — correct. USD payments complete and the transaction becomes `paid`.
- After a partial refund the transaction is `refunded_partially`, after a full one `refunded`; no amount, no refund id and no capture/refund entities in Shopware — as read from the source.
- Through the service: Shopware integration key + Watchdog PayPal app connected to one store, Shopware sync, PayPal capture-id pointers; all three captures linked by `exact_reference`, capture amounts ok; partial refund → `MONEY_REFUND_MISSING` `unknown` / `refund_amount_not_reported` with the provider's 5.00 shown; full refund → ok; no refund → no refund finding. PASS on both versions.
- Version note: see "Latest releases" below — 6.7.15.1 and 6.6.10.29 are now built and tested.


## Latest releases (2026-10-11, Step 94)

Dockware images stop at 6.7.2.2 / 6.6.10.6, so `plugins/shopware-app/tests/build-image.sh` builds exact releases the supported way (composer update of core, administration, storefront and elasticsearch together with a symfony/flex new enough, `system:update:finish`, assets, theme; the API must report the target version). Built and tested: **6.7.15.1** and **6.6.10.29** (newest on packagist).

- Integration key connector chain (`chain/chain-e2e.sh`): PASS on both.
- Real PayPal money through SwagPayPal (`paypal/run.sh`, SwagPayPal 10.x on 6.7, 9.x on 6.6): PASS on both.
- Shopware App (`app/app-e2e.sh`): PASS on 6.7.15.1. **Fails on 6.6.10.29 inside Shopware**: since 6.6.10.25 `Framework/DependencyInjection/app.xml` defines `shopware.app_system.trusted_url_resolver` with an empty `<argument/>`, which Symfony (7.2 and 7.4 alike) turns into `''`, while `TrustedUrlResolver::__construct(?Closure $dnsResolver)` needs a closure or null — the app registration request throws a TypeError. This breaks the installation of any app with a registration URL on 6.6.10.25–6.6.10.29 (checked against the upstream tags), not only ours. On those versions the integration key is the working path; the owner may report it to Shopware alongside the ACL finding.

## Spike part 4 — Stripe Payment for Shopware 6 (installed copy, 2026-10-22, Step 226)

The owner obtained the plugin and installed it on Shopware-Test: `StripeShopwarePayment` 1.17.4 (`stripe/shopware-payment`, vendor "Stripe Payments Europe, Ltd.", code copyright Pickware; proprietary licence — read for interoperability only, no code taken into this repository; the source stays on the test server under `custom/plugins/`). Read from the source; no Stripe keys configured yet, so nothing executed.

- **One flow for every method.** Card, Apple/Google Pay (digital wallets), SEPA, Klarna, iDEAL, P24, EPS, Bancontact all create a PaymentIntent (Stripe API version `2020-03-02`, automatic capture, `confirm=true`). The legacy Sources/Charges code is dead. Payment method technical names: `stripe_shopware_payment_payment_handler_{card,digital_wallets,sepa,klarna,ideal,p24,eps,bancontact}`.
- **Link written at Stripe:** PaymentIntent `metadata.shopware_order_transaction_id` = the Shopware **order transaction id** (32 hex) — the same value our Shopware mapper already sends as `store_transaction_ref`, and the same kind of link as SwagPayPal's `custom_id`. Also `metadata.platform_name`. No order id, order number or site URL in metadata.
- **Link written in Shopware:** order transaction custom field `stripe_payment_context.payment` with `payment_intent_id` (`pi_…`) and, once succeeded, `charge_id` (`ch_…`/`py_…`). Readable with `order_transaction:read` like `swag_paypal_resource_id`. **No test/live flag is stored** (unlike `swag_paypal_is_sandbox`).
- **Personal data at Stripe:** PaymentIntent `description` = `<customer email> / Customer <number> / Order <order number>`; a Stripe Customer per Shopware customer with name and email; `receipt_email` when enabled. The connector must keep reading only ids and the one metadata key (ADR 0020).
- `statement_descriptor_suffix` = `Ref. <order number> <descriptor or sales channel name>` (≤ 22 characters) — a weak human hint, not a link.
- **Keys per sales channel:** plugin configuration (secret key, webhook id/secret) can differ per sales channel, so one Shopware store may use several Stripe accounts.
- **Webhooks handled:** `payment_intent.succeeded|canceled|payment_failed`, `charge.succeeded|failed|expired` → transaction paid / cancelled / failed. SEPA and other delayed methods finish `processing` and become paid by webhook later.
- **No refunds at all:** there is no refund action in the administration and **`charge.refunded` is not subscribed**. A refund made in the Stripe Dashboard never reaches Shopware — the transaction stays `paid`. Conversely, setting a transaction to `refunded` in Shopware moves no money at Stripe.
- **Current state of our code:** `ProviderCoverage::providerForGateway()` maps every `stripe_*` technical name to Stripe, so such Shopware orders are already `financial_support = supported`, while the customer cabinet says Stripe orders in Shopware are "not supported". The order has no `transaction_ref` and the Stripe connector does not read `shopware_order_transaction_id`, so by the code (read, not run) a paid order with a Stripe integration connected would end as `payment_not_matched` after the grace — a false finding. No customer uses this combination yet.

Plan for the connector (needs owner decisions marked ⚑):

1. Shopware mapper: `transaction_ref` = `stripe_payment_context.payment.payment_intent_id` for Stripe transactions (exact link through `intent_ref`, existing code path).
2. Stripe mapper: `provider_store_transaction_ref` from `metadata.shopware_order_transaction_id` (32 hex only) — second, independent link; also gives store attribution of a shared Stripe account through the existing `other_store_transaction` rule (ADR 0073). Check that a charge snapshot without metadata does not clear the value set by the PaymentIntent snapshot.
3. ⚑ Mode: Shopware does not say test/live. Proposal: take the mode from the linked Stripe payment (`livemode`), and treat an unlinked order as unknown, never as live-mismatch.
4. ⚑ Refunds: every Stripe refund will look like "refund at the provider, store still paid", and every Shopware "refunded" without a Stripe refund is real money not returned. Both are true facts with this plugin. Decide whether the first is a finding (with a note that the plugin never syncs refunds) or informational only.
5. ⚑ Several Stripe accounts per store (per sales channel): one integration per store for now, or allow several.
6. Until 1–4 ship: keep `stripe_shopware_payment_*` as unsupported to match the cabinet text and avoid the false finding.
7. Verification as with PayPal (part 3): Stripe test keys in the plugin on Shopware-Test, webhook registered from the plugin, card / 3-D Secure / SEPA / failed card orders, a refund in the Dashboard; then the chain through the service.

Owner decisions (2026-10-22):

- Refunds (point 4): support them ourselves where possible, otherwise show information. The service stays read-only and never writes payment states into Shopware, so it cannot make the plugin sync refunds. What it does itself: it reads Stripe refunds directly (amount, time) and shows them on the order. "Refund at Stripe, Shopware still paid" with this plugin is **information** («возвраты не поддерживаются вашим платёжным плагином»), not a finding. Proposed default, to confirm: "Shopware refunded, no refund at Stripe" stays a finding, because the money was not returned.
- Mode (point 3): accepted — from the linked Stripe payment, unlinked = unknown.
- Accounts (point 5): one Stripe account per store.
- Test keys: the existing Stripe test account (`WATCHDOG_DEV_STRIPE_*`, also used by WooCommerce-Test). A shared account is also the real case of ADR 0073 attribution.

## Open

- Stripe plugin data for Shopware: read (part 4); connector and owner decisions open.
- Shopware App: manifest permissions, registration handshake, webhooks (order written, state changes), Cloud vs self-hosted.
- Load: paging large stores with the OR delta filter.

## Stripe in Shopware and other providers (owner, 2026-10-11)

- The common Shopware 6 Stripe plugin (Pickware "Stripe Payment") is downloadable only through a Shopware account registered for a company, which the owner does not have; the only public repository (shobcheye/ShopwareStripePayment, VIISON 2015) is a Shopware 4/5 plugin and not usable. **Stripe in Shopware is not supported for now**: such orders stay "payment method not supported" (no money check, no false discrepancies) and the Shopware panel says so. It will be researched with the first pilot customer who uses it (their staging, read-only), following the owner's plan: test orders, read both sides, find the exact link, then an adapter per plugin version.
- **Mollie and Shopify move to P2** (owner). Mollie notes for later: MolliePayments 4.23.0 is MIT and on packagist; it keeps the Mollie payment id (`tr_…`), order id (`ord_…`) and a third-party id in the transaction's `mollie_payments` custom fields; card holder data sits in the order's custom fields, which the connector never requests.
