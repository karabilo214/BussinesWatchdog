# Progress: Connectors

WooCommerce plugin and REST API connector, Stripe, PayPal, Shopware integration and App, compatibility spikes and real-payment chains.

### Step 45: WooCommerce Plugin Foundation And Compatibility Matrix

Status: complete for the plugin foundation; owner chose the floor PHP 7.4 / WP 5.9 / WC 6.0

Added:

- `plugins/woocommerce-watchdog`: capability-detecting compat layer, local tables, secret storage, pairing (admin/REST/WP-CLI), signed heartbeat with rotation and suspension, public challenge endpoint, diagnostics, uninstall.
- Docker matrix (6 pinned targets, legacy + HPOS, classic + blocks) with in-WordPress integration tests and an e2e against the backend.
- `docs/compatibility.md` filled with executed combinations; `docs/adr/0005-woocommerce-plugin-compatibility.md`; `docs/implementation-step-45-checklist.md`.

Verification:

- PHP 7.4 lint; integration 8/8 and e2e pass on floor, wc7-legacy, wc7-hpos, wc8, wc9, latest. Backend suite green.

Remaining:

- Order/refund snapshots + revisions + outbox delivery (Step 46), rescan/backfill, capabilities/deployment events.

### Step 46: WooCommerce Order And Refund Snapshots

Status: complete for capture into the local outbox

Added:

- Revisions (schema v2), outbox writes, minor-unit conversion, order/refund snapshot builders, shutdown-time capture from hooks, deletion/trash handling, cache busting.
- The matrix found real version differences (WC 7.9 HPOS fires no hook on refund deletion and keeps stale caches after trash/refund deletion; `checkout-draft` absent in WC 6.0; refund deletion hooks differ between 8.9/9.9/11.2); each is handled and documented in `docs/compatibility.md`.
- `docs/implementation-step-46-checklist.md`.

Verification:

- Integration 20/20 and e2e (events validated by the backend) on all six targets; PHP 7.4 lint; backend suite green.

Remaining:

- Outbox delivery, rescan/backfill, capabilities/deployment events (Step 47).

### Step 47: Plugin Delivery, Rescan, Backfill And Environment Events

Status: complete; the WooCommerce plugin now delivers end-to-end to the backend

Added:

- Signed batch delivery with per-event results, dead letter, suspension, Retry-After and jittered backoff; atomic delivery lock.
- 48 h rescan every 15 min, 90-day resumable backfill after pairing and daily, capture gated on connection.
- Capabilities and deployment events; WP-CLI deliver/rescan/backfill; diagnostics.
- `docs/implementation-step-47-checklist.md`.

Verification:

- Integration 29/29 and e2e with real delivery + backend projections on all six WooCommerce targets; PHP 7.4 lint; backend suite green.

Remaining:

- Backlog degradation flag/limits, diagnostics download, browser-check support, telemetry (P2).

### Step 49: Payment Attempt Monitoring, Layer 1 (Spec §17.1, ADR 0007)

Status: complete; real customer payment attempts are observed server-side and a payment method that stops working opens an incident

Added:

- Contract `checkout.payment_attempts` (aggregate `checkout`): closed 5-minute windows with per-method outcome counters, `trailing_failures` and failure classes; no customer data.
- Plugin schema v3: `bw_payment_attempts`, `AttemptLog`, `AttemptHooks` (Classic + Store API/Blocks, outcomes from payment completion and status changes), `PaymentAttemptsJob` (60 s; re-check, 30-minute `pending_stuck`, once-per-window reporting in one transaction), WP-CLI `payment-attempts`.
- Plugin fix: delivery re-encoded events through PHP arrays, turning `{}` into `[]` (would have quarantined windows without failures).
- Backend: `payment_attempt_windows`, semantic validation, `PaymentAttemptsProjector`, `PaymentAttemptMonitor` (`CHECKOUT_PAYMENTS_FAILING`, family `checkout_payment`, open/attach/auto-resolve/reopen), family-aware notifications (ru/en/de).
- Matrix e2e now places real HTTP checkouts (Classic and Store API) with a declining test gateway (`tests/matrix/mu-plugins`), an invalid e-mail and bank transfer, then checks the local attempts and the backend windows.
- ADR 0007 records the outcome semantics and thresholds chosen for the owner to confirm; `docs/implementation-step-49-checklist.md`.

Verification:

- Backend SQLite 273 passed + 4 skipped; PostgreSQL 18 277 passed.
- Plugin integration and e2e on the six WooCommerce targets — see `docs/compatibility.md`.

Remaining:

- Order-pay page, statistical success-rate rule, dashboard coverage, entitlement gating, layer 2 checkout script.

### Step 65: Stripe Read-Only Connector — Backend (ADR 0020)

Status: complete for the backend; tested with Stripe-shaped fixtures only (no real Stripe account yet)

Added:

- `POST /stores/{id}/integrations/stripe` (restricted key only, full secret keys refused, read probes for payment intents/charges/refunds, optional account check and expected account, encrypted key and webhook secret, one Stripe integration per store, window re-queued), `PUT /integrations/{id}/webhook-secret`, `POST /integrations/{id}/sync` (delta|audit), `POST /webhooks/stripe/{integration}` (signature checked by the official `stripe/stripe-php` 22.0 library).
- Mapping: PaymentIntent → `payment.snapshot`; captured charge → capture `transaction.observed`; refund → refund operation; other mode and unconfirmed currencies skipped. Change detection per object (`provider_object_states`) with content-derived event ids — re-syncs and webhook/poll races emit nothing new.
- Shared `EventIngestor` (plugin ingress and server adapters use the same validation, authority check, idempotency and outbox).
- Independent-provider operations may change status (pending → succeeded → failed) in observation order with history; operations that arrive before their payment are attached later.
- `ExactReferenceMatcher`: captures linked to the single order whose transaction reference is the PaymentIntent/charge id; provider refunds linked to the store refund naming them; ambiguity links nothing.
- `stripe:sync` every 15 min (delta with 60 min overlap, page budget with backlog), `stripe:sync --audit` daily (90 days). A rejected key degrades the integration, stops calls and turns money checks back to unknown.

Verification:

- Backend: SQLite 334 + 4 skipped, PostgreSQL 18 338 passed; 7 new end-to-end tests (connect rules, sync → allocation → reconciliation without discrepancy, refund lifecycle and refund link, rejected key → unknown, skipped mode/currency, webhook signature, page-budget backlog).
- Not tested with real Stripe: the compatibility spike (Stripe sandbox + WooCommerce Stripe Gateway) is still required — see ADR 0020.

### Step 66: Stripe Connection in the Dashboard

Status: complete; one unexplained smoke failure (see below)

Added:

- Store page "Payment provider" panel: connection form with step-by-step Stripe instructions (restricted key only, a full `sk_` key is refused in the UI and the API), optional webhook secret and expected account; connected state with mode, account (verified or not), key `rk_••••last4`, last sync and its result, last error, webhook state; "Sync now", webhook secret form with the endpoint URL, disconnect with confirmation; a rejected key is shown as a separate alert.
- Smoke: a fake Stripe API (`tests/e2e/fake-stripe.php`) and a background `outbox:dispatch` loop; the smoke refuses a secret key, connects with a restricted key, syncs (2 events) and finds the Stripe capture among payments without an order.

Verification:

- `make frontend-check` green (customer 70 tests).
- `make frontend-smoke`: 38/38. One earlier run failed once at "incident page in German at mobile width"; four reruns did not reproduce it. The step now waits for the Resolved tab to be selected (URL `tab=resolved`) before clicking; seven consecutive runs passed after that.
- Backend unchanged since Step 65: SQLite 334 + 4 skipped, PostgreSQL 18 338 passed.
- Not tested with real Stripe (fake API only).

### Stripe Compatibility Spike — Part 1 (real test account)

Status: done for reading; captures/refunds and the WooCommerce gateway are still open

- With the owner's test-mode restricted key: payment intents, charges and refunds are readable; `/v1/account` and `/v1/invoices` answer 403 (account stays "unverified", as designed).
- The owner's invoice marked paid produced a cancelled PaymentIntent without a charge; the connector projected a cancelled payment and no capture — correct, since no money moved through Stripe.
- Connect → audit sync → processing → re-sync without new events verified on real data (temporary store, removed after the run). Details in ADR 0020.
- Next for the spike: a real test-card payment, a partial refund and a failed payment (Stripe Dashboard or the WooCommerce Stripe Gateway on the test site) to confirm capture/refund shapes, the order transaction reference and gateway metadata.

### Stripe Compatibility Spike — Part 2 (real payments)

Status: done; only the WooCommerce Stripe Gateway part remains

- Owner's test-mode data: captured payment with a partial refund, two declined payments, an uncaptured authorization, an invoice marked paid. The connector produced captured / failed / failed / authorized / cancelled payments, one capture (500) and one refund (200); no capture for failures, the authorization or the invoice.
- Exact-reference matching linked the capture to the test order and the refund to the store refund; reconciliation gave capture ok (500 = 500) and refund ok (200 = 200). Re-sync emitted nothing. Temporary data removed. Details in ADR 0020.

### Stripe Compatibility Spike — Part 3 (WooCommerce Stripe Gateway)

Status: done

- `tests/matrix/stripe-gateway-spike.sh`: WooCommerce 11.2 + Stripe Gateway 11.0.1 in test mode, classic checkout with `pm_card_visa`, partial refund through WooCommerce. Order transaction id is the charge id; refund id is in `_stripe_refund_id`; gateway metadata includes `order_id` and `site_url` (and customer email/name, which Watchdog never stores).
- Fixed: the Watchdog plugin now sends the refund's `_stripe_refund_id` as `provider_ref` (it sent none, so refunds could never be linked automatically). Plugin tests: floor and latest 44/44; PHP 7.4 lint ok.
- Backend test for an order that references the charge id (as the gateway does). Backend: SQLite 335 + 4 skipped, PostgreSQL 18 339 passed.
- Open: `verified_metadata` matching by `order_id` + `site_url`; one-run full chain (plugin → backend → Stripe).

### Step 67: Verified-Metadata Matching (ADR 0020)

Status: complete

- `payment.snapshot` gains optional `provider_order_ref` and `provider_site_origin` (event schema in `contracts/`, `spec/contracts/` and the backend copy); the Stripe adapter fills them from metadata `order_id` and the origin of `site_url` only.
- Matcher rule 2: when no order has the exact reference, link the order named by `provider_order_ref` if the store domain is confirmed and equals `provider_site_origin` (same mode and currency, exactly one order). Retried when an order arrives after its payment, after domain confirmation and in the nightly sweep; provider refunds of a newly linked payment are tried right away.
- Tests: 4 new end-to-end tests (metadata link only on the confirmed domain, other site / exact reference wins, late order via a real order snapshot, metadata kept without customer data). Backend: SQLite 338 + 4 skipped, PostgreSQL 18 342 passed.
- Real check: the gateway-created Stripe payment linked to order 12 by metadata, its refund by the refund id, reconciliation ok; temporary store removed.

### Step 68: One-Run Stripe Chain (ADR 0020)

Status: complete

- `plugins/woocommerce-watchdog/tests/matrix/stripe-chain-e2e.sh` + `backend-e2e.php` actions `stripe-chain-setup|check|cleanup`: backend, WooCommerce 11.2 + Stripe Gateway 11.0.1, owner's real Stripe test account; checkout, partial refund, plugin delivery, Stripe sync, matching and reconciliation in one run. Result PASS: capture and refund linked by exact reference, `MONEY_CAPTURE_AMOUNT` and `MONEY_REFUND_MISSING` ok.
- Plugin: `PaymentMode` records the Stripe test/live mode once at payment/authorization (`_bw_payment_mode`) and sends it as `mode`; the order snapshot carries `order_key_hash` (SHA-256 of the order key, never the key). Plugin tests: floor and latest 45/45; PHP 7.4 lint ok.
- Backend: the first chain run linked old Stripe payments with the same metadata `order_id` to the new order. Metadata no longer links an order that has its own transaction reference, and requires the order-key hash to match when Stripe metadata has `order_key` (`payment.snapshot.provider_order_key_hash`, `order.snapshot.order_key_hash`; event schema in all three copies). New backend test. SQLite 339 + 4 skipped, PostgreSQL 18 343 passed.
- The mode assumption and the guards are recorded in ADR 0020 for the owner.

### PayPal Compatibility Spike — Parts 1–2 (ADR 0022)

Status: done; Transaction Search on accumulated data still to check

- Part 1 (REST API, owner's sandbox): app tokens carry refund/capture scopes even with every optional feature unticked — PayPal credentials cannot be limited to reading. Reads by id work; listing needs Transaction Search (403 until ticked, then lags). `custom_id` links order, capture and refund to the store order. A EUR payment to a USD account: order `COMPLETED`, capture `PENDING`.
- Part 2 (`tests/matrix/paypal-gateway-spike.sh`, WooCommerce 11.2 + PayPal Payments 4.1.3, real browser checkout with the sandbox buyer): transaction id = capture id; authorize-only keeps the authorization id until capture; pending capture leaves the order on-hold; WooCommerce refunds have no PayPal refund id. Results in `docs/compatibility.md`.
- No connector code yet; no write call with the Watchdog app.

### Step 70: PayPal Read-Only Connector (ADR 0022)

Status: complete; decisions await owner review

- Backend: `App\Support\Providers\PayPal\*` — client with a GET allowlist (plus OAuth token and webhook verification), connector with mandatory write-access acknowledgement and Transaction Search probe, order-centric mapper, sync over Transaction Search (lag-aware watermark, 31-day windows, request budget, daily audit with cursor), webhook controller (PayPal-side signature verification, order re-read), `paypal:sync` command and schedule, `POST /integrations/{id}/sync` dispatches per provider. Shared `ProviderEventEmitter` extracted from the Stripe ingestor. Migration: credential kinds `paypal_client`, `paypal_webhook`; refund allocation strategy `unique_amount`.
- Matching and reconciliation: provider-neutral refund link by unique amount inside an exactly matched order; provider coverage per order gateway.
- Plugin: `ppcp-*` gateways supported; mode from `_ppcp_paypal_payment_mode`. Plugin tests floor/latest 46/46, PHP 7.4 lint ok.
- Dashboard: "Payment provider" block with Stripe/PayPal tabs (status on each tab, the connected one opens first); PayPal tab with app setup steps, the write-access warning that must be acknowledged, rights shown after connecting, Transaction Search freshness, webhook id. ru/en/de.
- Tests: 9 backend PayPal tests (acknowledgement, Transaction Search probe, exact capture link + unique-amount refund, late store refund, pending capture and authorization, watermark and ambiguous refunds, request allowlist, webhook verification, per-gateway coverage and rejected credentials, currency conversion). Backend: SQLite 355 + 4 skipped, PostgreSQL 18 359 passed. Customer unit tests 80. `make frontend-smoke` 41/41 in the last three consecutive runs (two earlier runs failed once each on unrelated late steps — German order page, sign out — while other jobs were loading the machine).
- Real check on the owner's sandbox: the real Watchdog app connected through the connector, audit sync found the plugin and API payments via Transaction Search, and orders mapped correctly (captured with refunds, pending EUR, authorization). Store "PayPal Sandbox Shop" left in the local smoke tenant for viewing.

### Step 71: One-Run PayPal Chain (ADR 0022)

Status: complete

- `plugins/woocommerce-watchdog/tests/matrix/paypal-chain-e2e.sh` + `backend-e2e.php` actions `paypal-chain-setup|check|cleanup`: backend with PayPal connected (real Watchdog app, sandbox), WooCommerce 11.2 + PayPal Payments 4.1.3 + Watchdog plugin, real sandbox buyer checkout, partial refund through PayPal, plugin delivery, PayPal sync (Transaction Search delta and the capture → order read a webhook uses), matching and reconciliation in one run. Result PASS: capture `exact_reference:1000`, refund `unique_amount:300`, `MONEY_CAPTURE_AMOUNT` and `MONEY_REFUND_MISSING` ok.
- `paypal-spike-configure.php` takes `BW_PAYPAL_INVOICE_PREFIX` (fresh WordPress reuses order numbers and PayPal refuses duplicate invoice ids).
- Transaction Search lagged ~2 h; the fresh payment came only through the capture → order path. A real signed webhook was not exercised. Proposal recorded in ADR 0022: use the store-reported capture id of PayPal orders as a read pointer to remove the lag without a webhook.

### Step 72: PayPal Capture-Id Pointers (ADR 0022)

Status: complete (owner accepted the proposal from Step 71)

- `PayPalSync::syncPointers()` + `paypal:sync --pointers` every minute: recent (6 h) store orders of `ppcp-*` gateways in the integration's mode whose `transaction_ref` has no PayPal operation yet are read at PayPal by that capture id and mapped from the PayPal order; at most 50 per run, each id at most once per 15 minutes. Config `watchdog.paypal.pointer_*`.
- New backend test (pointer read and link; old, other-mode, other-gateway orders ignored; unknown capture retried only after the interval; only allowlisted GETs). SQLite 356 + 4 skipped, PostgreSQL 18 360 passed.
- The one-run chain (`paypal-chain-e2e.sh latest`) now checks the pointer path instead of a simulated hint: PASS on the owner's sandbox.

### Step 73: Plugin Safety (ADR 0023)

Status: complete

- Owner decisions on the platforms research: Germany/DACH, WooCommerce REST API-first, plugin safety first (ADR 0023).
- Plugin 0.2.0: local queue limit 100 000 with skip-before-revision and backfill recovery below 90 000; heartbeat `outbox_full`/`skipped_events`; emergency stop (settings page, `wp business-watchdog stop|resume`) and `BUSINESS_WATCHDOG_DISABLED` constant before the autoloader; 7-day backlog flag; uninstall removes the new options; README with emergency instructions.
- Backend: a full plugin queue makes the connector `partial` / `local_queue_full`; reason text ru/en/de in the dashboard.
- Release: `bin/build-release.sh` (package without tests, PHP 7.4 lint), `tests/matrix/release-checks.sh` on the shipped ZIP — 27 checks PASS on floor and latest (upgrade/rollback, checkout with the service unreachable, switches, no WooCommerce, offline deactivation and repeated uninstall).
- Tests: plugin integration floor/latest 48/48; backend new freshness test.

### Step 76: WooCommerce REST API Connector (ADR 0024)

Status: complete (backend); dashboard screen next; assumptions await owner review

- Spike on the matrix (6.0 … 11.2, without the plugin): pretty permalinks required up to 9.9, HTTPS only, `_fields` keeps billing/customer data out, refunds bump the order only from 7.9, trash/deletion behaviour, writes refused for a Read key, a write-free probe that tells a Read key from a Read/Write key.
- Backend `App\Support\Providers\WooCommerce\*`: SSRF-safe client (store's own HTTPS origin, pinned DNS, no redirects, GET allowlist on order routes + the connect-time probe), connector (`POST /stores/{id}/integrations/woocommerce-api`; Read key only; one order source per store — pairing the plugin is refused while the API is the source and vice versa), mapper to the plugin's events (`order.snapshot`, `refund.snapshot`, `order.deleted`), sync (delta every 5 min, recent re-read hourly, 90-day audit daily with deletion check by `include=`), `woocommerce-api:sync [--recent|--audit]`, freshness by last successful sync, rejected key → degraded and no more polling. `ProviderEventEmitter` gives revisioned drafts a per-object revision (`provider_object_states.revision`). Credential kind `woocommerce_api`.
- Tests: 8 backend tests (connect rules, origin/SSRF, mapping without personal data, revisions, late and deleted refunds, audit deletion, rejected key and freshness, pairing refusal, amounts). Backend SQLite 368 + 4 skipped, PostgreSQL 18 372 passed (tree including the parallel export work). Real check: `tests/matrix/rest-api-chain-e2e.sh floor latest` PASS. The matrix WordPress port is published on 127.0.0.1 (random port) so the backend can reach it; `WATCHDOG_DEV_WC_API_ENDPOINT` is honoured only in local/testing.

### Step 77: Owner Decisions on the REST API Connector (ADR 0024)

Status: complete

- Owner accepted: one order source, Read key only, audit for deletions; DNS verification stays for now (proposal: confirm the store by a working Read key on its own origin); late-refund delay on old WooCommerce becomes a warning on the connection screen.
- Payment mode without the plugin: `mode_reported` in the order projection and API; money checks against a test-mode-only provider are `unknown` / `payment_mode_needs_plugin`, and test-mode payments without an order in an API-connected store too. New backend test.
- Fix: the spec copy of the OpenAPI contract (`spec/contracts/openapi.yaml`) missed the REST API connect operation in Step 76 (its copy test failed on that commit); both copies are identical again.

### Step 78: Store Confirmed by a Read Key (ADR 0024)

Status: complete

- Owner decision: a working Read key on the store's own HTTPS origin confirms the store. `StoreVerificationService::confirmByApiKey()` records a verification with method `api_key` (pgsql constraint extended) on connect, activates an onboarding store, writes `store.verified` to the audit log and re-runs exact matching like the other methods; a store already verified for its URL is left alone. `api_key` cannot be requested through `POST /stores/{id}/verify`. OpenAPI (both copies): method enum. Two backend tests; SQLite 373 + 4 skipped, PostgreSQL 18 377 passed.

### Step 79: Order Source Screen (ADR 0024)

Status: complete

- Store page: "Order source" block (`OrderSource.vue`) with tabs "REST API key" (`WooCommerceApiPanel.vue`: steps to create a Read key, access details, requirements, warnings about late refunds and payment mode, store confirmation by the key, connected key ck_••••, read-only, last sync, sync now, key-rejected alert, disconnect with a reminder to delete the key in WooCommerce) and "Plugin" (the former connector panel, plugin integrations only). One source: the other tab explains that the current source must be disconnected first.
- Verification: the plugin method only for a paired plugin; hint that a REST API key confirms the store; method `api_key` named. Coverage: "Order source" with the last sync of an API connection (`coverage.connector.last_sync_at`, backend + OpenAPI). Order page: "test/live mode: plugin needed" when the store did not report the mode. Incident facts for `api_key_rejected`, `api_sync_failing`, `api_not_synced_yet`. Error texts for every REST API connector code (ru/en/de, checked by a test).
- Tests: customer unit tests 87 (new `orderSource.test.ts`); `make frontend-check` green; `make frontend-smoke` 41/41 in the last three runs (smoke checks the plugin tab of a paired store and the recommended key tab of a new store). Backend SQLite 373 + 4 skipped, PostgreSQL 18 377.

### Step 80: Shopware 6 Spike, Part 1 (ADR 0025)

Status: complete; decisions await the owner

- `plugins/shopware-app/tests/spike/run.sh` on dockware/play Shopware 6.7.2.2: read-only ACL role + Integration, orders through the Store API, admin changes. Findings: criteria search and GET work with the read role; `includes` keeps personal data out (without it `orderCustomer` comes back despite no customer privilege); money as floats with currency rounding decimals; new orders have no `updatedAt` and payment state changes touch only the transaction, so the delta needs an OR over order created/updated and transaction updated; deleted orders vanish (404).
- Security finding: the order/transaction/delivery state transition endpoints have no ACL in 6.7.2.2 — a read-only key changed an order and a payment status (money-moving refunds stay protected). A read-only key cannot be verified without writing or reading all integrations' secrets. Proposal in ADR 0025: Integration first with a PayPal-style warning, App (manifest permissions, webhooks) next; responsible disclosure to Shopware is the owner's call.

### Step 81: Shopware Decisions and Spike Part 2 (ADR 0025)

Status: complete

- Owner accepted: Integration first with a PayPal-style warning, App later, read-only not verifiable for an Integration, responsible disclosure to Shopware (the owner sends the report), supported versions 6.6 and 6.7.
- Spike part 1 repeated on Shopware 6.6.10.6: identical results. The generic state-machine route checks the update privilege while the order/transaction/delivery routes do not (delivery shipped with the read-only key) — added to `verify_acl.py`.
- SwagPayPal 10.8.5 source: capture id, PayPal order id and sandbox flag in the order transaction's custom fields; refunds leave only the transaction state (no amount, no id) in Shopware — reconciliation model to be decided with the connector.

### Step 85: Shopware Integration Connector (ADR 0026)

Status: complete (backend); dashboard screen next

- Owner decision on refunds kept only as a payment state: `full` must equal the provider's refunds of the captured amount; `partial` shows the provider's refunded amount and is `unknown` unless the provider refunded nothing or everything; provider refunds with store state `none` are extra refunds. New optional `order.snapshot.refund_state` (event schema, all copies) and a state-based branch in the refund rules.
- `App\Support\Providers\Shopware\*`: client (allowlist: OAuth token, order search, order search-ids; never state transitions), connector (`POST /stores/{id}/integrations/shopware`, risk acknowledgement required, key confirms the store, one order source), mapper (float amounts only when exact, effective transaction, SwagPayPal capture id and sandbox flag, `swag_paypal_*` → PayPal coverage, refund state), sync (OR delta over created/updated/transaction updated, daily audit with deletion check), `shopware:sync [--audit]`. Shared `StoreOriginHttp` (SSRF-safe store origin requests, now also used by the WooCommerce client) and `OrderSources` (plugin, WooCommerce key or Shopware key — one per store). Stores can be created with `platform` `woocommerce` or `shopware`. PayPal pointers also follow `swag_paypal_*` orders.
- Tests: 5 backend Shopware tests (connect rules and allowlist, mapping without personal data, audit deletion, six refund-state cases against PayPal provider data, float conversion). SQLite 380 + 4 skipped, PostgreSQL 18 384. Real chain `plugins/shopware-app/tests/chain/chain-e2e.sh` on Shopware 6.6.10.6 and 6.7.2.2: PASS.

### Step 86: Shopware Connection Screen (ADR 0025/0026)

Status: complete

- Store creation asks for the platform (WooCommerce or Shopware 6). A Shopware store's "Order source" block shows only `ShopwarePanel.vue`: steps for a role with Orders → View and an Integration without administrator rights, requirements, what is read and not read, the state-change warning that must be acknowledged before connecting, the refund note (partial refund amount from the provider), connected key SWIA••••, last sync, sync now, key-rejected alert, disconnect with a reminder to delete the integration. Verification hint and the `api_key` method name cover both key types. Error texts for every Shopware connector code (ru/en/de, checked by a test).
- Tests: customer unit tests 90; `make frontend-check` green; `make frontend-smoke` 42/42 twice in a row (new step: a Shopware store gets the Shopware panel, connecting waits for the acknowledgement).

### Step 87: Real PayPal Payments through SwagPayPal (ADR 0025 part 3)

Status: complete

- `plugins/shopware-app/tests/paypal/` (`configure.py`, `order.py`, `approve.mjs`, `refund.py`, `inspect.py`, `pay.sh`, `chain.sh`): SwagPayPal configured with the owner's sandbox shop app, real buyer payments approved in Chromium, refunds through the plugin's admin route, then the service connects the Shopware integration key and the Watchdog PayPal app and reconciles. Screenshots and order ids stay in a git-ignored `.out/`.
- Confirmed on Shopware 6.7.2.2 (SwagPayPal 10.8.5) and 6.6.10.6 (SwagPayPal 9.13.4): capture id and sandbox flag in the transaction custom fields; EUR to an account without EUR balance stays pending/in progress; refunds leave only `refunded_partially`/`refunded`. Chain PASS on both: exact capture links, partial refund unknown with the provider's 5.00 shown, full refund reconciled, no refund without findings.

### Step 93: Shopware App (ADR 0027)

Status: complete

- Backend `ShopwareApp` + `ShopwareAppController`: per-store app archive (risk acknowledgement required, read-only manifest permissions, registration URL and secret per integration), registration and confirmation handshake, key checked once the app is active, signed webhooks (`checkout.order.placed`, `order.written`, `state_enter.*` for payment and order states, app lifecycle) reading the named orders right away through `ShopwareSync::syncChanged()`; Admin API at the shop URL Shopware reports. Credential kind `shopware_app`. OpenAPI: four operations.
- Dashboard: the Shopware panel offers the app (recommended, download after the acknowledgement, waiting state that refreshes itself) or the integration key; the connected view shows the connection type and the last webhook. `@bw/api-client` gained `requestFile` for file responses.
- Tests: 5 backend app tests; SQLite 394 + 4 skipped, PostgreSQL 18 402 (tree with the parallel Telegram work). Customer unit tests 91. Real `plugins/shopware-app/tests/app/app-e2e.sh` on Shopware 6.6.10.6 and 6.7.2.2: PASS. `make frontend-smoke`: the Shopware step passes; the run stops later at the e-mail recipient step because Step 88 (parallel session) renamed "Добавить адрес" to "Добавить получателя" without updating the smoke test.

### Step 94: Newest Shopware Releases (ADR 0025/0027)

Status: complete

- `plugins/shopware-app/tests/build-image.sh` builds exact Shopware releases from the dockware base through composer and `system:update:finish`; `wait-ready.sh` waits until the admin token works (a fresh container may restart its web server after answering 401). `paypal/run.sh` installs the matching SwagPayPal line (9.x for 6.6, 10.x for 6.7) and runs the money chain.
- 6.7.15.1: key chain, SwagPayPal chain and app — PASS. 6.6.10.29: key chain and SwagPayPal chain — PASS; app installation fails inside Shopware 6.6.10.25–29 (empty DI argument for the app-system TrustedUrlResolver), affecting every app with a registration URL; documented, the key remains the path there.
- Stripe for Shopware: no open-source Shopware 6 Stripe plugin exists (packagist/GitHub); the common "Stripe Payment" plugin (Pickware) is downloaded only through a Shopware account — needs the owner.

### Fix: PayPal webhook URL empty on the store page

- The webhook URL was only in the connect and sync responses; the store page loads the integration list, which lacked it, and the PayPal panel had no fallback, so the field next to "Copy" stayed empty after a reload. `IntegrationDto` now returns `webhook_url` for Stripe and PayPal (null for other connectors; OpenAPI `Integration` schema, both copies), and both panels fall back to building it in the browser. Regression tests: the list carries the URL (backend), the PayPal panel shows it when the field is missing (frontend). The old frontend fixture always contained `webhook_url`, which hid the bug.

### Step 136: WooCommerce Plugin ZIP Downloadable From the Account

Status: complete

- Owner's backlog item. The release ZIP is built into `WATCHDOG_PLUGIN_DIR` (default `apps/backend/storage/app/plugins`, ignored by git); `PluginPackage` picks the highest `business-watchdog-X.Y.Z.zip` there (other names ignored). Customer API (any member who may read integrations): `GET /api/v1/downloads/woocommerce-plugin` (available, version, file, size, SHA-256 — never the server path) and `GET /api/v1/downloads/woocommerce-plugin/file` (application/zip, `X-Content-SHA256`, private no-store; 404 `plugin_not_available`). OpenAPI and README (build command now targets the plugin directory) updated.
- Account: store → order source → «Плагин» shows «Скачать плагин» with version, size and checksum plus WordPress install steps, or a note that the archive is not published yet.
- Local stand: the real 0.2.0 ZIP built with `build-release.sh` (PHP 7.4 lint passed) is picked up from the default directory.

Verification: `tests/Feature/Integrations/PluginDownloadTest.php` (2: nothing published, newest of several versions with size and hash, download content and name; guests 401). Customer 117 tests (`tests/pluginDownload.test.ts`, 2). Frontend check green. Backend SQLite 557 passed, 4 skipped; PostgreSQL 18: 561 of 561.

### Step 141: Observing Real Payment Attempts in Shopware Stores (ADR 0056)

Status: complete

Why: WooCommerce stores get «payments are not going through» from the plugin's server-side attempt aggregates (ADR 0007 layer 1); Shopware stores had nothing. Owner's backlog item 2026-10-14, independent of the browser check way.

Added:

- ADR 0056. Attempt = a payment transaction of a Shopware order created after the order source was connected; outcome from its state: paid/authorized/refunded/chargeback → paid; offline core methods (prepayment, invoice, cash on delivery, direct debit) still open → on hold; failed → failed; cancelled → failed (`retried` when the order has a later transaction); otherwise pending → stuck after 30 minutes → late success if paid later.
- `observed_payment_attempts` (one row per transaction, ids only) and `ObservedPaymentAttempts`: outcome counted in the 5-minute UTC window of the time it became known; windows recomputed from the rows (repeated reads and replays change nothing, an emptied window is removed) into the same `payment_attempt_windows`; then the same rule `CHECKOUT_PAYMENTS_FAILING`, incidents, notifications, entitlement and coverage as for WooCommerce. `checkout:payment-attempts-stuck` every minute.
- `ShopwarePaymentAttempts` is fed by every Shopware order read (App webhooks right away, delta every 5 minutes, daily audit); the order criteria also include the payment method's `handlerIdentifier` (offline handlers of plugins with other technical names).
- `FakeDnsClient` moved to its own test file so Shopware provider tests also run alone.
- `plugins/shopware-app/tests/attempts/attempts-e2e.sh` (`make shopware-attempts-e2e`), its own database `bw_attempts_e2e`.

Verification: `tests/Feature/Providers/ShopwarePaymentAttemptsTest.php` (4: outcomes and classes, three failures → incident, re-read unchanged, a payment resolves; pending → stuck by the command → late success moves the window; transactions before the connection ignored, coverage; schedule). Backend SQLite 567 passed, 4 skipped; PostgreSQL 18: 571 of 571. Real Shopware 6.6.10.6 and 6.7.2.2 (integration key, Store API orders, payments failed and paid by state transitions): PASS.

Remaining (ADR 0056 confirmed by the owner on 2026-10-14 as it is): checkout observation script (layer 2) for the test customer and guest ways; the same observation for the WooCommerce REST API connector.

### Step 143: Checkout Observation Script in the Shopware App (ADR 0057)

Status: complete

Why: spec §17.1 layer 2 — notice when real customers can no longer complete a purchase although no order (and so no ADR 0056 attempt) is created. Owner decisions 2026-10-14: only with the test customer or guest check way (ADR 0055); no consent banner, because the script watches nothing but whether payment works and a purchase can be completed; the service decides whether a store is observed.

Added:

- Spike on Shopware 6.7.2.2: an app's storefront JS is added to the theme on install and loaded on every page (`defer`, after `storefront.js`); app Twig templates apply after the shop cache is cleared.
- App sources for the first time: `apps/backend/resources/shopware-app` (linked as `plugins/shopware-app/src`, so they ship with the backend): `business-watchdog.js` (stage counters only — no storage on the device, no form values, no identifiers, every part guarded) and confirm/finish templates with a marker and a render beacon (`noscript` variant). The store's archive (app 1.1.0) adds them with the store's endpoint.
- Backend: public `GET /api/v1/observe/{integration}/config`, `GET …/r.gif`, `POST …/stages` (store origin only, rate limited, outside Sanctum's stateful SPA handling — found by the e2e: a shop on `localhost` got a CSRF 419 for the beacon); `checkout_observation_windows` (counters per store and 5 minutes, no visitor data); decision cached a minute: Shopware app active, check way test customer or guest, feature `payment_attempts_script`. Rules `CHECKOUT_SCRIPT_SILENT` (info) and `CHECKOUT_SUBMIT_FAILING` (warning; only orders since the first failing send count) with incidents family `checkout_observation`, notification texts ru/en/de; `checkout:observation-evaluate` every 5 minutes. Coverage `checkout_script` per store. OpenAPI updated.
- Worker: the Shopware check records `checkout_script_present` when the lease expects the script (`expect_checkout_script`); requests to the service's own origin are dropped quietly (`quiet_origins`), so checks are never counted or reported.
- Dashboard: coverage row «Наблюдение за оформлением», incident titles and advice; app instructions now say to clear the Shopware cache and what the script counts. Owner panel labels.
- `plugins/shopware-app/tests/observation/observation-e2e.sh` (`make shopware-observation-e2e`), own database `bw_observation_e2e`.

Verification: `tests/Feature/Checkout/CheckoutObservationTest.php` (5). Backend SQLite 572 passed, 4 skipped; PostgreSQL 18: 576 of 576. Worker 16. Customer 125, owner panel 55; frontend check green. Real Shopware 6.6.10.6 and 6.7.2.2 with the real app archive and real shoppers' browsers: PASS (10 checks each).

Remaining: an update archive for stores already running the app; the same layer for WooCommerce; scripts that run before the theme's are not observed (Shopware loads app scripts after `storefront.js`). ADR 0057 confirmed by the owner on 2026-10-14 as it is.

### Step 146: Checkout Observation Script in the WooCommerce Plugin (ADR 0059)

Status: complete

Why: owner's task 2026-10-14 — layer 2 (spec §17.1, ADR 0007 «not started») in the WooCommerce plugin, following the Shopware app (ADR 0057), without a consent banner (the owner's decision for Shopware, applied to WooCommerce by the task).

Added:

- ADR 0059. Plugin 0.3.0: `Observation\ObservationSettings` (the heartbeat answer `checkout_observation.observe`, an autoloaded option), `CheckoutObservation` (checkout — Classic or Blocks, not order-pay — and order-received pages: the script in the page head with an inline configuration, the page counted in `wp_footer` so redirects are not, a `noscript` beacon, script tag attributes and exclusion filters for WP Rocket, Autoptimize, LiteSpeed Cache, SiteGround Optimizer), `ObservationToken` (page kind and expiry signed with a key derived from the connection secret), `ObservationLog` (table `wp_bw_checkout_observation`, schema 4, counters per 5-minute window, capped; a change after reporting raises the revision), `Jobs\ObservationReportJob` (closed windows → `checkout.observation` through the outbox, every minute), REST `POST /observe` (sendBeacon, token, same-site origin, never for a verified browser check) and `GET /observe/noscript` (1×1 GIF), diagnostics, CLI `checkout-observation`, uninstall clean-up, `assets/` in the release ZIP.
- `assets/checkout-observer.js`: `script_loaded`, `form_ready` (Classic `#place_order` / Blocks place-order button enabled and a payment method offered), `submit_clicked` (Classic form sent; Blocks button pressed without field validation errors after 1.5 s), `returned_with_error`, `submit_stalled` (60 s), `finish_reached`, JavaScript error count, `script_altered` (combined, deferred or async — shown in diagnostics). No storage, no form values, no identifiers; synthetic pages send nothing.
- Service: event `checkout.observation` (contract `event.schema.json` and its backend copy, validator: aggregate id `observation:<window start>`, 5-minute window, capped counters), `CheckoutObservationProjector` into the same `checkout_observation_windows` (plugin checkout = confirm columns; `source_revision`, only newer revisions replace); heartbeat answers `checkout_observation`; the observation decision covers the plugin (active, store active, plan feature; no browser check required); rules evaluate plugin stores too, «orders» for the submit rule include the plugin's layer 1 windows; coverage `needs_plugin` for REST-key stores; the WooCommerce browser check records `checkout_script_present` when expected. Notification and dashboard texts made neutral (plugin or app, checkout or confirmation page). OpenAPI updated.
- `tests/matrix/observation-e2e.sh` (`make woocommerce-observation-e2e`): real shoppers' browsers on the matrix, own database `bw_wc_observation_e2e`; WordPress background core updates switched off (on wc8 an automatic update replaced core files during a run and the store answered 500).

Verification: backend `tests/Feature/Checkout/WooCommerceCheckoutObservationTest.php` (4: heartbeat answer, ingest through the signed API with revisions, rule with layer 1 windows, coverage and check expectation) plus the Shopware observation tests; plugin integration `ObservationTest.php` (6) — suite 54/54 on all six matrix targets (PHP 7.4 / WooCommerce 6.0 to PHP 8.3 / 11.2); observation e2e PASS on all six targets in one run (60 of 60 checks). The existing REST API chain e2e still passes on latest. Customer 125, worker 17 (with another agent's uncommitted tests).

ADR 0059 confirmed by the owner on 2026-10-14 as it is (60 s stall, observation without a configured browser check).

### Step 147: Payment Attempts of WooCommerce Stores Connected With a REST API Key (ADR 0060)

Status: complete

Why: owner's task 2026-10-14 — stores connected only with a read REST API key (the main path, ADR 0024) had no payment attempt observation; layer 1 lived only in the plugin.

Added:

- ADR 0060. `WooCommerceApiPaymentAttempts`: a checkout order (`created_via` checkout or store-api) created after the key was connected is an attempt; paid date or processing/completed/refunded → paid, on-hold → on hold, failed → failed, cancelled → failed (cancelled), pending → stuck after 30 minutes → late success; admin/REST orders, drafts, free orders and unknown custom statuses are not counted. Fed by every order page the REST sync reads (delta, recent, audit) into the service-side attempts of ADR 0056 (same windows, rule, incidents, coverage). The connector now also reads `created_via` (still no personal data).
- `tests/matrix/rest-api-attempts-e2e.sh` (`make woocommerce-rest-attempts-e2e`): real WooCommerce without the plugin, the real connector, own database `bw_wc_attempts_e2e`.

Verification: `tests/Feature/Providers/WooCommerceApiPaymentAttemptsTest.php` (3). E2E PASS on floor (WooCommerce 6.0.2) and latest (11.2.0): admin order and order from before the connection ignored, three checkout failures (Classic and Blocks) open the incident, bank transfer on hold is a success, a paid order resolves it, re-reads change nothing, no personal data stored.

ADR 0060 confirmed by the owner on 2026-10-14 as it is (only checkout / store-api orders; a cancelled unpaid order is a failure).

### Step 148: Updating the Shopware App of Connected Stores (ADR 0061)

Status: complete

Why: owner's backlog item — a store that already runs the app could not get a newer version: the dashboard built an archive only for a new connection.

Added:

- ADR 0061. `ShopwareApp::updatePackage()` and `POST /api/v1/stores/{store}/integrations/shopware-app/update` (owner/admin): the current app version built for the store's connected app integration — same integration, registration URL and manifest secret — so Shopware installs it as an update; `404 shopware_app_not_connected` otherwise; audited (`update_package_created`).
- The installed version from every signed webhook (`source.appVersion`) in `health.app_version` and `connector_version`; the manifest also subscribes to `app.updated`. App version 1.2.0. Integration DTO `app_update` (installed, latest, available); OpenAPI updated.
- Dashboard, Shopware panel of a connected app: «Версия приложения» (unknown until the first event) and, when older, «Обновление приложения до версии …» with the steps (download, upload in Shopware, confirm Update, clear the cache) and «Скачать обновление приложения». ru/en/de; error text.
- `plugins/shopware-app/tests/app/update-e2e.sh` (`make shopware-app-update-e2e`), own database `bw_app_update_e2e`; app e2e helper `shop.py update` (upload + extension update API).

Verification: `ShopwareAppTest` (+1: no app → 404, version tracked, update archive with the same secret, URLs, `app.updated` and the script, nothing new created, audit, `app.updated` → up to date). Customer 126 (+1). Backend SQLite 582 passed, 4 skipped; PostgreSQL 18: 586 of 586; frontend check green. Real Shopware 6.6.10.6 and 6.7.2.2: app 1.0.0 → update archive → Shopware updates to 1.2.0, same integration active, `app.updated` seen at once, storefront script and both templates present, orders still arrive through webhooks, Shopware does not register again — PASS. The existing app install e2e (app 1.2.0) still passes on 6.6.10.6 and 6.7.2.2.

Remaining: the owner to confirm ADR 0061 (no automatic notice to customers about a new app version).

### Step 155: Local WooCommerce Purchase Fault Lab and Real Sandbox Payments (Spec §9–10, §17.1, §18–21; ACC-12, ACC-15, ACC-17, ACC-29)

The existing matrix provisioned fresh WordPress 7.1.3 / WooCommerce 11.2.0 (HPOS) in Docker. A test-only MU plugin now injects six controlled faults only in the local environment and only with an explicit request header: disabled product button, disabled cart checkout control, a cart script preventing navigation, cart price changed from the catalog price, zero payment methods, and a gateway returning HTTP 504. `purchase-faults-e2e.sh` runs an isolated Playwright robot through a healthy BACS order and all six faults. It sends synthetic `example.test` buyer details. This robot really submits one local order; it is separate from the production scheduled browser worker, whose no-purchase network policy remains intact. The test uses a Classic cart page; WooCommerce Blocks were not included in this purchase robot.

Official Stripe Gateway 11.1.0 in test mode captured a €10 order and refunded €3. Official PayPal Payments 4.1.3 completed a Sandbox buyer order. A further Stripe test-mode chain deliberately created both disagreements: provider capture present but WooCommerce order pending, and Stripe decline but WooCommerce order marked paid. The first exposed a missing reverse reconciliation rule; Step 155 adds it. These are provider sandbox transactions, not live charges. Stripe credentials are revoked in the temporary backend test store when the chain exits; the fixture store and history are retained for audit. No production WooCommerce plugin logic was changed by the fault injector.

Verified: 7/7 local purchase robot modes; plugin integration 54/54 on a fresh WooCommerce matrix target; real Stripe capture/partial refund; real PayPal Sandbox buyer checkout; Stripe divergence chain. The 504 mode is a local gateway simulation, not a timed-out real provider. The pending payment attempt produced by that simulation is stored by the plugin; the plugin integration suite covers conversion to `pending_stuck` after the 30-minute observation window. The local WordPress image has no `sendmail`, so checkout emails were not delivered or tested. A direct re-run of the integration suite on the already used fault lab failed a deduplication assertion because that test assumes a clean database; the fresh matrix run passed.

### Step 156: Shopware Purchase Fault Lab and PayPal Sandbox Divergences (Spec §9–10, §17.1, §18–21; ACC-15, ACC-17, ACC-29)

The local purchase matrix now uses Shopware 6.6.10.29 and 6.7.15.1 images. A separate Playwright buyer robot placed a healthy guest order and reproduced six controlled faults on each version: disabled product buy button, disabled cart checkout control, cart script suppressing navigation, cart item price different from catalog price, unavailable order submit, and a response lost after Shopware created the order. Both versions passed all seven robot modes and ended with exactly two orders (healthy and response loss). The robot is restricted to localhost and an explicit lab flag; it uses synthetic `example.test` data. The production scheduled adapter ran first on each image and created no orders. No Shopware App was installed in this fault lab; its signed webhook and checkout observation suites were verified separately in Steps 143 and 148.

Official SwagPayPal 10.8.5 on Shopware 6.7.2.2 processed three genuine PayPal Sandbox buyer payments of USD 23.41; the backend independently read and linked the exact capture IDs. A USD 5 partial refund remained `unknown` because SwagPayPal reported a refund state without the amount; the full refund reconciled. Two deliberate disagreements then produced `MONEY_CAPTURE_NOT_RECORDED:pending` (PayPal paid, Shopware order open) and `MONEY_CAPTURE_MISSING:pending` (Shopware marked paid, PayPal capture absent). The fixture for the second case explicitly declares Sandbox mode; without it the service correctly returns unknown coverage. The runner now propagates failed inner assertions as a failed exit status, and temporary backend credentials are revoked on exit. Approval URLs and buyer screenshots are not retained in test output.

A separate disposable Shopware 6.7 container with valid Sandbox credentials was disconnected from external networking after an order was created. Calling its payment handler over loopback returned the payment error destination and left the transaction `failed`. That is a simulated provider reachability failure, distinct from the browser response loss in the purchase matrix. No live charges were made. PayPal signed webhook delivery, Shopware App installation in this particular lab, and notification email delivery were not tested here. No production Shopware App code, database migrations, or contracts changed in Step 156.

### Step 160: Shopware Checkout Observation Through the Shop (ADR 0062)

Status: complete

- Owner decision: the Shopware app's checkout script must report to the app in the shop, as the WooCommerce plugin does, not to the service. Spike on Shopware 6.6.10.6 and 6.7.2.2 (`plugins/shopware-app/tests/spike/observation_spike.py`): the app owns its custom entities without manifest permissions; an App Script storefront endpoint on the shop's domain stores one row per report with `services.writer`; caching can be disabled; 40 concurrent reports stored 40 rows; the Admin API reads and deletes the rows.
- App 1.3.0: `Resources/entities.xml` (reports, observe setting), `storefront-bw-observe` endpoint (`/storefront/script/bw-observe`, known pages/stages only, errors capped, no cache), page-loaded scripts passing the setting to the templates, render image and script reports on the shop's own endpoint. The script makes no request to decide whether to observe and never contacts the service.
- Service: `ShopwareCheckoutReports` runs with every scheduled Shopware delta sync of an app connection: writes the setting when the decision changes or hourly, collects reports into the 5-minute windows by `createdAt`, deletes them in the shop. Report ids are remembered for 7 days (`checkout_observation_collected_reports`), so a failed delete is never counted twice. `ShopwareClient::syncOwnEntities` writes only the app's own entities (setting upsert, report delete). The direct endpoints `/api/v1/observe/{integration}/config|r.gif|stages`, their rate limiter and origin check are removed.
- Browser check: lease `network_policy.quiet_paths` (replaces `quiet_origins`) drops `/storefront/script/bw-observe` on the store origin without reporting, so the check is not counted.
- Found while running the WooCommerce e2e and fixed: the WooCommerce cart check (Step 157) did not recognise the product on a store with plain permalinks and a Blocks cart — the Blocks cart first renders an empty placeholder row and the product link is `?product=slug`. The adapter now also compares the canonical product URL and waits up to 5 s for the row to fill.
- Dashboard note about the app script (ru/en/de) says the counts go only to the shop.

Migration `2026_10_16_120000_create_checkout_observation_collected_reports.php`; `contracts/openapi.yaml` drops the three observe paths and renames `quiet_origins` to `quiet_paths`.

Verification: backend full SQLite suite 590 passed, 4 skipped (incl. `CheckoutObservationTest` 10); browser-worker unit 27/27; Playwright scheduled WooCommerce/Shopware and public fixtures 27/27; customer dashboard 126/126. Real Shopware in Docker (`observation-e2e.sh`): 6.6.10.6 and 6.7.2.2 — 14/14 each, including that real shoppers' browsers contacted only the shop and that collected reports were deleted in the shop. Real WooCommerce 11.2.0 HPOS in Docker (`plugins/woocommerce-watchdog/tests/matrix/observation-e2e.sh latest`) — 10/10 after the cart fix (the first run failed on the cart check described above).
