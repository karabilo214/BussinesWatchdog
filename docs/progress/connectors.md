# Progress: Connectors

WooCommerce plugin and REST API connector, Stripe, PayPal, Shopware integration and App, compatibility spikes and real-payment chains.

### Step 45: WooCommerce Plugin Foundation And Compatibility Matrix

Status: complete for the plugin foundation; owner chose the floor PHP 7.4 / WP 5.9 / WC 6.0

Added:

- `plugins/woocommerce-watchdog`: capability-detecting compat layer, local tables, secret storage, pairing (admin/REST/WP-CLI), signed heartbeat with rotation and suspension, public challenge endpoint, diagnostics, uninstall.
- Docker matrix (6 pinned targets, legacy + HPOS, classic + blocks) with in-WordPress integration tests and an e2e against the backend.
- `docs/compatibility.md` filled with executed combinations; `docs/adr/0005-woocommerce-plugin-compatibility.md`; `docs/implementation/implementation-step-45-checklist.md`.

Verification:

- PHP 7.4 lint; integration 8/8 and e2e pass on floor, wc7-legacy, wc7-hpos, wc8, wc9, latest. Backend suite green.

Remaining:

- Order/refund snapshots + revisions + outbox delivery (Step 46), rescan/backfill, capabilities/deployment events.

### Step 46: WooCommerce Order And Refund Snapshots

Status: complete for capture into the local outbox

Added:

- Revisions (schema v2), outbox writes, minor-unit conversion, order/refund snapshot builders, shutdown-time capture from hooks, deletion/trash handling, cache busting.
- The matrix found real version differences (WC 7.9 HPOS fires no hook on refund deletion and keeps stale caches after trash/refund deletion; `checkout-draft` absent in WC 6.0; refund deletion hooks differ between 8.9/9.9/11.2); each is handled and documented in `docs/compatibility.md`.
- `docs/implementation/implementation-step-46-checklist.md`.

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
- `docs/implementation/implementation-step-47-checklist.md`.

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
- ADR 0007 records the outcome semantics and thresholds chosen for the owner to confirm; `docs/implementation/implementation-step-49-checklist.md`.

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
### Step 163: Stripe App OAuth read-only path (ADR 0020; Spec §10)

The Stripe App spike found that new Connect OAuth integrations cannot request `read_only`; Stripe Apps OAuth v2 supports `payment_intent_read` and `charge_read` (including refunds). The customer store panel now starts OAuth installation and keeps manual restricted-key entry as fallback. The backend binds one-use state to tenant/store/actor/mode, exchanges the code, verifies `stripe_apps` scope, mode, account and GET access to all three resources, encrypts access and rotating refresh tokens, and refreshes before the existing polling sync. Local revoke deletes stored OAuth tokens. Added the API contract, schema tables and platform setup instructions in `docs/spikes/stripe-app-oauth.md`.

Verification: backend SQLite 599 passed + 4 skipped, PostgreSQL 18 603/603, customer dashboard 129/129 plus typecheck/build. Stripe App OAuth tests use simulated Stripe responses; a real public Stripe App is not configured or published yet, so this is not a live installation claim. The backlog item stays open until the owner creates the platform App and an external test installation passes.

### Step 185: Stripe App OAuth Withdrawn (ADR 0020)

Status: complete (owner decision 2026-10-19)

- Not available for Ukraine: without a business registered in Germany Stripe does not let the platform account finish its setup, so neither the customer authorisation through the button nor the Stripe App External test works. If a company registered in Germany becomes available, this item can be taken up again.
- Reverted the code of Step 163 (`db0bd13`): `StripeAppOAuth`, `StripeAppOAuthController`, the `stripe-app/*` routes, OAuth token handling in `StripeConnector`/`StripeSync`, config and `.env.example` entries, `openapi.yaml` and `ui-api-catalog.md` operations, `spec/database/schema.sql` tables, the customer dashboard button, API client and texts. New migration `2026_10_19_100000_drop_stripe_app_oauth_tables` drops the tables where the old migration ran. The uncommitted test App package `apps/stripe-app` was deleted. Restricted-key connection is unchanged.
- Also fixed: the customer dashboard lacked texts for the team error codes `owner_role_protected` and `member_not_found` added in Step 177 (its translation test was red since then).

Verification: backend SQLite 624 passed + 4 skipped (the 4 Stripe App OAuth tests removed); PostgreSQL 18: 628/628; customer dashboard typecheck, 133/133, build. OpenAPI parses (197 paths).

### Step 202: Connector release safety (Spec §9, §18–20, §37–39; ACC-12, ACC-13, ACC-15–17)

- WooCommerce plugin 0.3.2: own action/filter callbacks are checked for callability and catch `Throwable`; a failed filter returns its input. Bootstrap and uninstall are guarded. Schema upgrades run only in safe admin requests; an incomplete schema leaves monitoring inactive instead of running `dbDelta` during checkout. Checkout render emits only optional same-origin observation beacons; counter writes happen in separate requests. The release builder checks the guard and observer assets. Admin, order, payment, REST, cron and shutdown hooks are guarded.
- Shopware App 1.3.1: removed checkout Twig overrides and page-loaded App Scripts. The separate storefront script observes confirm/finish pages through optional same-origin beacons and does not change checkout forms. The archive builder fails if a source read or ZIP operation fails. This loses no-JavaScript page-render coverage.
- Production release gate: WooCommerce ZIP is served only when its exact SHA-256 is approved; Shopware App requires approval of the current package fingerprint plus the specific store UUID. An unapproved App returns 409 before changing the integration. Shopware 6.6.10.29 remains excluded because its own App registration fails; test a copy of each store before adding its UUID. Neither new release is deployed to production.
- Verification: WooCommerce integration matrix 58/58 across six targets; WooCommerce observation E2E latest PASS; release ZIP install, upgrade, rollback, offline checkout, both stop switches, no WooCommerce and uninstall PASS. Shopware App 1.3.1 initial install/order/payment/uninstall on 6.6.10.6 PASS; update 1.0.0 → 1.3.1 on 6.7.2.2 and 6.7.15.1 PASS; observation 14/14 on 6.7.2.2. Backend targeted tests 14/14, 109 assertions; plugin PHP 7.4 lint and `git diff --check` PASS.

Contracts: `contracts/openapi.yaml` documents the release gate. No migrations. Remaining: per-store production approval requires a copy of the real store and its checkout smoke; unsupported extensions and Shopware versions are not automatically certified by these matrix results.

Live publication check (2026-10-20): the running backend's `/var/www/html/storage/app/plugins` is empty, so the customer dashboard correctly shows no WooCommerce download. The previous UI text promising to send an archive on request was replaced in ru/en/de with a release-dependent message. The tested 0.3.2 ZIP has not been published to production.

### Step 205: Publish the Connector Downloads (Spec §9, §18–20, §37–39; ACC-12, ACC-13, ACC-15–17)

The owner requested release through the production deploy script. To keep pending work in other modules off the server, release commit `bbc61b3` contains only Step 202 connector changes and is based on the previously deployed `3c14205`. `infra/production/deploy.sh bbc61b3` completed; it reported no migrations. The exact WooCommerce 0.3.2 ZIP from that commit matched the tested SHA-256 `d82e7cfa4a5329fc700c806221ece2af91299d7379efa0d094d8501efebeab4e`, was copied to the persistent production storage volume, and was approved through the production gate. The running backend now returns version 0.3.2 and this SHA from `PluginPackage::latest()`; the public dashboard responds 200, while an unauthenticated direct plugin download returns 401.

Shopware App 1.3.1 has approved source fingerprint `b07b8839d92494e8217ff8d479c62c3f45aab836d24bb3a207293794529433d0` on the server. There are currently no Shopware stores on the production server. Per owner decision, its store allowlist remains empty: a store-specific archive is created for download only after that store's copy passes the installation and checkout checks and its UUID is approved. No generic App ZIP has been released to customers.

Verification for the exact release branch: WooCommerce ZIP PHP 7.4 lint; backend `PluginDownloadTest|CheckoutObservationTest` 14/14 (109 assertions); Docker release checks from Step 202; production `PluginPackage::latest()` and SHA check after backend recreation. No contracts or migrations changed in this publication step. Remaining: first real Shopware store needs a copy-based approval before it can download the App.

### Step 206: Live Test Stores on the Production Server — WooCommerce and Shopware Runs

Status: both runs done; the stores, the App and the data stay in place for the owner's own observation (App removal and WooCommerce PayPal with a sandbox buyer not done yet). Since 2026-10-20 17:20 UTC the server runs `main` (`9caa446`, then `cd73eea`)

- Infrastructure (`docs/ops/test-shops.md`): `woo-test` and `shopware-test` subdomains routed by the production Caddy (noindex) to the `bw-test-shops` compose project; release branch `release/test-shops-2026-10-20` = deployed `bbc61b3` plus only test-store infrastructure and the worker/backend fixes below (the server still runs connector release code, not `main`). WooCommerce: WordPress 7.1.3 / PHP 8.3, WooCommerce 11.2.0 HPOS, Storefront, Stripe Gateway 11.0.1 (test), PayPal Payments 4.1.3 (sandbox), bank transfer, a WP-Cron sidecar. Shopware 6.7.15.1 (`prod`), SwagPayPal 10.8.5 (sandbox). The dockware Adminer, mailcatcher and log viewer were reachable for ~10 minutes; removed in the container and blocked in Caddy. The owner's organization «BW» has an override; the Shopware store was created with platform `woocommerce` and corrected in the database (audited) because the platform cannot be changed later.
- Real shopper (`infra/test-shops/shopper.mjs`, run in the worker image): Stripe test card, declined card, bank transfer on the Blocks checkout.
- Results on the live service:
  - order 20 (Stripe, 17.80 €): plugin delivered the order in ~3 minutes (WP-Cron cadence), the Stripe charge linked by exact reference, capture ok and recorded by the store;
  - three declined cards → failed orders 21–23 → `CHECKOUT_PAYMENTS_FAILING` opened; the next paid order resolved it;
  - bank transfer (order 24) → unsupported, not "unpaid";
  - partial refund 3 € from WooCommerce through Stripe (order 20) → refund ok;
  - refund 4 € only in Stripe (order 26) → not detected: the server runs reconciliation rules v2; the fix (FIN-08, rules v3, ADR 0068) is in `main`, not deployed;
  - 41 old Stripe test payments of the test account were imported (they will become payment-without-order findings after the 24-hour orphan grace).
- Found and fixed (worker and backend, deployed as `8a38610`):
  - the worker blocked PayPal Payments 4.x button lookups `ppc-sdk-v6-client-token` / `ppc-sdk-v6-simulate-cart` as unknown mutations (every store with official PayPal failed the check); now allowed, PayPal order creation/approval and cart changes stay blocked;
  - the worker blocked the plugin's own observation beacon `/business-watchdog/v1/observe` (every store with the plugin failed); now passed as quiet paths, also matched in `?rest_route=`;
  - the Blocks payment step counted radios before they render; now waits for them.
- Open (owner decision): with gateway scripts blocked, the Blocks payment block never leaves its loading skeleton, so the check ends `selector_changed` on stores with Stripe/PayPal. Allowing the gateway origins in the check settings exposes a long tail (Apple Pay, Google Pay, Amazon Pay, hCaptcha, Stripe Link, PayPal API hosts), and the Amazon Pay script `cPSPcheckout.js` is mistaken for a store script by the worker's `blockedScripts` heuristic. Also: PayPal Payments rate-limits client tokens (429) for repeated checks from one IP; low-traffic stores without a system cron make the plugin go silent.


Shopware run (2026-10-20, App 1.3.1 uploaded by the owner, store `01a1592f…`):
  - App registered over HTTPS, store verified; orders arrive by webhook within seconds; prepayment → unsupported; the browser check passed fully (guest, delivery, 4 payment methods, observation script); observation windows recorded.
  - `bin/console app:install` refused the App without a de-DE `label` → App 1.3.2 (`08971cb`); its fingerprint is not approved yet, so App downloads for new stores are closed after the `main` deploy.
  - PayPal (`infra/test-shops/sw-shopper.mjs`, sandbox buyer): the USD sandbox merchant held EUR captures `PENDING` (`RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION`); SwagPayPal kept the orders `in_progress`. After the owner accepted them, MONEY_CAPTURE_NOT_RECORDED opened for 10001–10004 and resolved itself when Shopware marked them paid. 10005 was denied by the owner → PayPal DECLINED, Shopware later set the payment `failed` → nothing to report.
  - Partial refund 120 € from the Shopware admin (10003): PayPal refunded, the service did not read it (Transaction Search lag, pointers only for unknown captures) → false «refund missing» ahead; fixed in Step 209; now `unknown` (Shopware reports no refund amount).
  - Refund 5 € only at PayPal (10002, API with the shop's sandbox key): MONEY_REFUND_EXTRA pending, after the 60-minute grace an incident (17:00 UTC).
  - Findings from the run became Steps 208 (amounts and PayPal ids, ADR 0071), 209 (PayPal refunds from the store, order number in titles) and 210 (store payment state, «payment failed, order open», payments without order since connection, ADR 0072).
  - WooCommerce on rules v3: the Stripe-only refund of order 26 is MONEY_REFUND_EXTRA; a user's manual match of an old 10 € Stripe charge to order 26 raised capture amount and multiple captures (correct for that link). Scheduled checks pass with the payment step skipped (`gateway_scripts_not_loaded`, ADR 0070).

Verification: worker unit tests 28/28 (new: PayPal lookups, quiet beacon incl. `rest_route`); backend `tests/Feature/Checkout` + `tests/Feature/Browser` 44/44; production deploys `6ce9cdf`, `d9466f2`, `8a38610`.

### Step 214: Privacy Paragraph and LIA Template for Stores in the Cabinet (ADR 0062)

Status: complete; deployed 2026-10-21 (`7cad5ef`), provider set in the production `.env`

- After the lawyer's answer (ADR 0062): the store, not the shopper, is informed; a paragraph in the store's privacy policy satisfies Art. 13, and a ready legitimate interest assessment helps the store's data protection officer.
- `resources/merchant-texts/{de,en}-privacy.txt` — the paragraphs of the legal package (Step 160) with the lawyer's addition «auf Servern in Deutschland / on servers in Germany»; `{de,en}-lia.txt` — a LIA template (processing, interest, necessity, balancing, result incl. § 25 TDDDG, review) with placeholders for the shop; both say they are samples, not legal advice.
- `GET /api/v1/downloads/observation-texts` (members with integration read): `provider_set` and the texts with the provider from `WATCHDOG_PROVIDER_NAME` / `WATCHDOG_PROVIDER_ADDRESS` (`docs/ops/integrations.md`), a bracketed placeholder without it. `contracts/openapi.yaml` (`ObservationTextSet`).
- Cabinet `ObservationTexts.vue`: a collapsed block «Texts for your store's privacy policy» (ru/en/de UI, texts in German/English) with copy for the paragraph and a `.txt` download of the LIA; loaded only when opened. Shown with the WooCommerce plugin download, in the Shopware app connection steps and for stores connected through the app.

Verification: `ObservationTextsTest` (provider filled, «servers in Germany», both languages, placeholder and warning without a provider, guests refused), OpenAPI route guard; customer `observationTexts.test.ts` (loaded on open, language switch, provider warning, failed request). Backend SQLite 687 passed + 4 skipped; customer 145/145, vue-tsc, build.

### Step 215: One Approved Shopware App Version for Every Store (ADR 0074)

Status: complete; deployed 2026-10-21 (`c10dd1f`), the store list removed from the production `.env`; App 1.3.2 is not approved yet (fingerprint `5f49a82d…41e6a8f7` on production)

- Owner's decision: no check on a copy of the store and no per-store approval; the owner approves an App version, and every customer downloads it in the cabinet.
- `ShopwareApp::releaseApproved()` checks only the version fingerprint; `WATCHDOG_SHOPWARE_APP_APPROVED_STORE_IDS` and `watchdog.shopware.app_approved_store_ids` are removed (`infra/production/env.example`); the integration DTO's update availability no longer depends on the store.
- `docs/ops/integrations.md` (how to approve a version), backlog item on third-party themes rewritten without the copy of the store.

Verification: `CheckoutObservationTest` (archive refused without approval, issued with the exact fingerprint, refused with another); backend SQLite 687 passed + 4 skipped.

### Step 216: Download the Current Shopware App Locally at Any Time

Status: complete in main

- Owner's request: on the local server the newest app must always be downloadable to try it on a Shopware shop. The release gate is already off outside production; for a store connected through the app the cabinet offered a download only when a newer version existed.
- `app_update.test_download` (integration DTO, `contracts/openapi.yaml`) is true only while `watchdog.connector_release_gate` is off. The Shopware panel then shows «Local environment: download app X.Y.Z» for a connected app with no pending update; it uses the existing update archive (same integration and secret). Never shown in production.

Verification: `ShopwareAppTest` (flag true without the gate, false with it); customer `orderSource.test.ts` (no button without the flag, button with the version and download with it). Backend SQLite 689 passed + 4 skipped; customer 146/146, vue-tsc.

### Step 217: Checkout Pages and Elements per Shopware Store (ADR 0075, part A)

Status: complete in main; parts B–D (app 1.4.0, worker, coverage) follow

- ADR 0075: one setting per store for third-party themes, used by the browser check and the app's script; data only (paths, CSS selectors); the script will read it from its own shop; checking is the browser check on the live store (no copy, ADR 0074).
- `CheckoutLayout` (`stores.settings.checkout_layout`, no migration): confirm and finish paths, selectors of the order form, submit button, payment methods and error notice; empty = Shopware default. Paths start with `/`, no `..`, `//`, query or a bare `/`; selectors from a restricted character set; 200 characters at most; trailing slashes trimmed.
- `PUT /api/v1/stores/{store}/checkout-layout` (store managers, Shopware stores only, `checkout_layout_unsupported` otherwise) replaces the set and writes `store.checkout_layout_saved` to the audit log when it changes; `Store.checkout_layout` = `{custom, defaults}` or null (`contracts/openapi.yaml`).
- The check's default `checkout_url` for Shopware is the store origin plus the confirm path of the setting; a scenario's own `checkout_url` still wins.
- Cabinet: «Checkout pages and elements» on the store page for Shopware stores (defaults as placeholders, field errors in ru/en/de).

Verification: `StoreCheckoutLayoutTest` (save, trim, defaults, audit, check URL, reset; rejected paths and selectors, bare `/`, other platforms, viewer role), OpenAPI route guard; customer `checkoutLayout.test.ts`. Backend SQLite 691 passed + 4 skipped; customer 148/148, vue-tsc.

### Step 218: Shopware App 1.4.0 Reads the Store's Checkout Layout; Worker and Coverage Use It (ADR 0075, parts B–D)

Status: complete; deployed 2026-10-21 (`d53806e`), app 1.4.0 approved on production at the owner's request (fingerprint `02315e2c…79ee718e4`), so every store can download it; an update archive was built for the Shopware-Test store (1.3.1 → 1.4.0) for the owner to install

- App 1.4.0: `custom_entity_bw_checkout_setting.layout` (text); the storefront endpoint answers `GET /storefront/script/bw-observe?config=1` with `{observe, layout}` (`cache.maxAge(300)` for Shopware's HTTP cache; browsers get `no-cache, private`) and keeps accepting reports by POST. The script reads the setting without cookies on every storefront page, recognises the confirm and finish pages by the layout's paths, finds the form, submit button and payment methods with the layout's selectors (inside `try/catch`), sends no reports when the store is not observed, and exposes `layout`, `config` and `found` in `window.BusinessWatchdogCheckout` (version 4).
- Service: `ShopwareCheckoutReports` writes the store's layout with the observe flag (`layout_hash` in `health.observation`; rewritten when it changes or hourly).
- Worker: the Shopware adapter takes `checkout_layout` from the lease (confirm page by path, payment method and submit selectors); an element of a custom layout that is not found makes the step `inconclusive / selector_changed` (`layout_payment_method_not_found`, `layout_submit_button_not_found`), not a site failure. With the script expected it waits for the marker (up to 5 s) and adds `checkout_script_elements`. The network policy lets the script's `GET …?config` through and keeps reports quiet.
- Coverage `checkout_script.elements_not_found` (ADR 0075 «observation not confirmed»): the script loaded but the elements were not found, or a store with a layout got `selector_changed` on the payment step. The cabinet's layout panel warns about it.
- `contracts/openapi.yaml`: lease `checkout_layout`, the new coverage state.

Verification: `CheckoutObservationTest` (layout reaches the app setting and is rewritten only on change; `elements_not_found`; archive: one `fetch(` to the shop's own setting without cookies, `layout` field, `maxAge(300)`), `StoreCheckoutLayoutTest` (lease layout); worker unit 28/28 (setting read allowed, reports quiet); Playwright Shopware fixtures 12/12 in the worker image (third-party theme passes with the layout, stale selector inconclusive); customer 148/148, vue-tsc; backend SQLite 692 passed + 4 skipped. Real Shopware in Docker (`plugins/shopware-app/tests/observation/observation-e2e.sh`) on 6.6.10.6 and 6.7.2.2 — 18/18 each: custom layout reaches the script and its elements are found, a renamed submit button is reported as not found without `form_ready`, not observed = setting read and no reports, the browser check finds the script and its elements, all earlier observation checks. `update-e2e.sh` on 6.7.2.2 (1.0.0 → 1.4.0) 8/8. Not run: an update from 1.3.x (entity without `layout`) to 1.4.0.

### Step 219: One Shopware App for Every Store, Bound by a Pairing Code and the Shop's Domain (ADR 0076)

Status: complete; deployed 2026-10-21 (`9f0c0b0`, migration applied), app 1.5.0 approved on production (fingerprint `7970c842…57426aac8`); the universal archive was handed to the owner to replace the per-store app on Shopware-Test

- Owner's decision: the app is universal; the store is confirmed by a code from the cabinet plus the domain the app connects from; the Shopware Store comes later.
- `ShopwareApp::universalPackage()` — one archive: registration `/api/v1/shopware/app/register`, webhooks `/api/v1/shopware/app/webhook`, the app secret derived from `APP_KEY` (HMAC), and a main module `/api/v1/shopware/app/connect`. Download `GET /api/v1/downloads/shopware-app`. App version 1.5.0.
- `shopware_app_installations` (migration, `spec/database/schema.sql`): shop id, URL, version, encrypted secrets, status registered → confirmed → bound, integration id. `ShopwareAppInstallations` handles registration (409 `shopware_app_shop_moved` when a bound shop registers from another host), confirmation (a bound shop renews its integration's keys), webhooks (bound → the integration's handling; unbound → version only, deleted on `app.deleted`), the module's signature (shop secret over Shopware's query, ≤ 1 hour) and binding.
- Binding (`POST /api/v1/shopware/app/connect`, no session/CSRF, `throttle:pairing-exchange`): the store's pairing code (`POST /stores/{id}/pairing-codes`, 15 minutes, attempts limited), a Shopware store, the shop URL host equal to the store host (without `www.`), a successful Admin API login and order read with the app's key; then the same read-only app integration as before (`capabilities.app_distribution = universal`), the code consumed, audit. The module page (`resources/views/shopware-app/connect.blade.php`, de/en) may be framed only by the shop's origin and tells the Administration it loaded.
- `shopware:app-installations-purge` daily: unbound installations older than 7 days. Per-store archives of earlier versions keep working through the per-integration routes; the cabinet no longer hands them out.
- Cabinet: the Shopware app steps download the one archive (after the state-change acknowledgement), then «Create connection code» shows the code and waits (every 5 s) until the store is bound.
- Why not a code in the app's configuration: reading it would need `system_config:read` (all shop settings, mail passwords included).

Verification: `ShopwareAppUniversalTest` (same archive for everyone; registration signed with the app secret; nothing bound before the code; unsigned module refused; framing only by the shop; wrong code, other domain refused; binding, code consumed; webhooks of the bound shop; `app.deleted` revokes and forgets; a shop whose key does not work is not bound; purge after 7 days), provider tests 55/55, customer 149/149 and vue-tsc, backend SQLite 699 passed + 4 skipped, PostgreSQL 18 703/703. Real Shopware in Docker (`plugins/shopware-app/tests/app/universal-e2e.sh`) on 6.6.10.6 and 6.7.2.2 — 9/9 each: the universal archive installs, registration and confirmation leave the shop unbound, the Administration opens the main module with a source Shopware signed, a code of a store on another domain and a forged shop id are refused, the store's code binds, an order arrives by webhook, uninstalling revokes and forgets.

### Step 220: Connector installation guides in the customer cabinet

- The WooCommerce ZIP download opens a guide and creates the store's 15-minute pairing code. The guide lists the WordPress upload, activation, WooCommerce → Business Watchdog and exact Service URL / Pairing code fields. SHA-256 is explicitly labelled as a file checksum, never a pairing code.
- Downloading the universal Shopware App opens a guide and creates a pairing code. It lists Shopware upload, install, activate, Open app and code confirmation. The service URL is displayed for reference; the universal App already contains it, so only the pairing code is entered in Shopware. Both guides allow a new code if the old one expires.
- No migrations or API contracts changed. Customer `orderSource.test.ts` covers both dialogs and the URL/code fields; `npm run check -w @bw/customer` passed (150 tests, typecheck, production build). Traceability: main spec §8–9, `spec/panels/CLIENT-PANELS.md` §2, ACC-04 and EXT-29.
- Deployed on 2026-10-21 as `f69c660`; all production containers started and `/app/` returned HTTP 200.

### Step 221: Connections Without Installing Anything Come First; WooCommerce Webhooks Without a Plugin (ADR 0077)

Status: complete in main

- Owner's decision: the read-only API connection is the default on both platforms; WooCommerce's own webhooks make it instant without the plugin.
- Shopware panel: the integration key is preselected and «recommended»; the app is described as optional (instant updates and checkout observation of real shoppers). WooCommerce already recommended the REST API key.
- `WooCommerceApiWebhooks`: `POST /api/v1/integrations/{id}/woocommerce-webhook` (integration managers, REST API connections) creates a secret (`integration_credentials.kind = woocommerce_webhook`, migration for the PostgreSQL check, `spec/database/schema.sql`), revokes the previous one and returns the delivery URL, the secret (shown once) and the topics `order.created`, `order.updated`, `order.deleted`, `order.restored`. `POST /api/v1/webhooks/woocommerce/{integration}`: the unsigned ping `webhook_id=N` → 200; signature `X-WC-Webhook-Signature` (base64 HMAC-SHA256 of the raw body) and `X-WC-Webhook-Source` host = store host (without `www.`) required; an order topic queues `SyncIntegration` delta (unique per connection), the body is not used; `health.webhook` records the last delivery. `IntegrationDto.webhook_url` names the delivery URL for REST API connections.
- Cabinet: «Instant updates — without a plugin» on the REST API connection: steps for WooCommerce → Settings → Advanced → Webhooks, delivery URL and secret to copy, last delivery time; the polling note says changes arrive at once when webhooks work.
- Checkout observation of real shoppers stays with the plugin and the app (ADR 0062 legal design).

Verification: `WooCommerceApiWebhooksTest` (setup by managers only, unsigned ping proves nothing, wrong signature, other source, unused topic, signed order delivery queues a delta, new secret replaces the old one, not configured → 409); customer `orderSource.test.ts` (Shopware key preselected and recommended, app steps on choice; webhook setup and last delivery); backend SQLite 701 passed + 4 skipped, PostgreSQL 18 705/705, customer 151/151, vue-tsc, PHP 7.4 lint. Real WooCommerce 9.9.7 (HPOS) in Docker without the Watchdog plugin (`plugins/woocommerce-watchdog/tests/matrix/rest-api-webhook-e2e.sh wc9`) — 6/6: four webhooks created as the merchant does, the ping and every delivery answered 200 (failure count 0), WooCommerce's signature accepted, a new order read through the API at once without polling.

### Step 222: Preserve Order Identity Across Connector Replacements

- Production Shopware-Test reproduced the issue: installing universal App 1.5.0 after revoking 1.4.0 created a new integration for the same `shop_id`; orders 10001–10004 were reimported under new internal IDs, while their exact PayPal captures remained allocated to the old IDs. Four false `MONEY_CAPTURE_MISSING` incidents followed.
- On a future Shopware App activation with the same shop ID, carry the previous integration's orders, refunds and provider object revisions to the replacement before its first sync. WooCommerce REST API reconnection carries the previous revoked source for the same store; plugin re-pairing carries it when the install ID matches. Existing plugin ZIP upgrades already preserve the integration ID and queued events.
- For duplicates already imported before this change, reconciliation may borrow an active capture allocation only from exactly one revoked sibling with the same tenant, store, provider/account, external order ID, mode, currency, gateway and transaction reference. Refund evaluation uses the same allocation. Changed payment references and ambiguous siblings still cannot borrow it.
- Production hotfix `b92b8f2` deployed without Step 221; `reconciliation:recheck-open-incidents` queued 6 orders. The four capture incidents for 10001–10004 auto-resolved. The real PayPal refund / Shopware `none` discrepancy for order 10002 remains open, currently twice because the historical duplicate order rows have separate incident fingerprints. Do not suppress that discrepancy as a paid-order false positive.
- No migrations or API contracts. Verification: focused backend PostgreSQL tests 44/44 (243 assertions), including Shopware App reinstall, WooCommerce API reconnect with preserved order/refund IDs, plugin re-pair and reference mismatch; WooCommerce ZIP release check on WordPress 7.1.3 / WooCommerce 11.2.0 passed, including ID preservation across upgrade, rollback and upgrade again. Traceability: spec §8–10 and §11–14; ACC-12, ACC-13 and ACC-29.

### Step 223: WooCommerce Plugin 0.3.3 Ready for the WordPress.org Directory

Status at Step 223: code prepared in main; submission and directory assets were still pending. Later submission status is recorded below under 2026-10-26.

- `readme.txt` (WordPress.org format): description of the service and what the plugin adds over a read-only REST API key, the mandatory «External services» section checked against the code (what is sent and when, nothing before pairing; no customer names, addresses, e-mails, phone numbers or items; versions of WordPress, WooCommerce, theme and active plugins; counters only; links to terms and privacy), installation, FAQ, changelog. `Contributors: businesswatchdog` is a placeholder for the owner's WordPress.org user name.
- Release package ships `readme.txt` instead of the internal `README.md` (`bin/build-release.sh`); header `Requires Plugins: woocommerce`.
- Plugin Check (wordpress.org/plugins/plugin-check) on the release ZIP: 108 errors → 0. SQL names the plugin's tables as `{$wpdb->prefix}bw_*` (values were already placeholders; no query logic changed); the one query with column names from the `COUNTERS` whitelist carries a justified phpcs exception; translator comments; the store-verification text answer escaped; the fixed 1×1 GIF marked; the synthetic header and the checkout payment method unslashed and sanitized; nonce checks that happen elsewhere documented; prefixed globals in `uninstall.php`. Remaining 91 warnings are direct queries on the plugin's own tables, `error_log` in the safety guard and placeholder-count heuristics for `IN (...)` lists.
- Version 0.3.3 (no behaviour change).

Verification: plugin integration suite 58/58 on the floor target (PHP 7.4, WordPress 5.9, WooCommerce 6.0) and on WooCommerce 9.9.7 HPOS; PHP 7.4 lint of the package; Plugin Check 0 errors on the built ZIP.

### Step 226: Stripe Payment for Shopware 6 — Plugin Read

Status: analysis and live test payments complete; connector not written yet

- The owner installed `StripeShopwarePayment` 1.17.4 on Shopware-Test. Its source was read (copied to a temporary folder, not into the public repository; proprietary licence). Findings and the connector plan with open owner decisions: ADR 0025, "Spike part 4".
- Key facts: every method goes through a PaymentIntent with `metadata.shopware_order_transaction_id` = Shopware order transaction id; the transaction keeps `stripe_payment_context.payment.payment_intent_id`/`charge_id`; no test/live flag; no refunds and no `charge.refunded` handling; PaymentIntent description carries customer email; keys per sales channel.
- Found in our code: `stripe_shopware_payment_*` methods are classified as supported Stripe orders while the cabinet says they are not supported; without the links above this would produce `payment_not_matched` findings for a store that connects Stripe (by reading, not run). No customer has this combination.
- Plugin state on the test shop: 8 Stripe payment methods active, no Stripe keys, no orders paid with Stripe yet.
- Live run (ADR 0025 part 5): owner entered the Stripe test keys in the plugin page, the plugin registered its HTTPS webhook; Card and SEPA assigned to Storefront by the owner. `sw-shopper.mjs` gained `stripe`, `stripe_3ds`, `stripe_3ds_fail`, `stripe_decline`, `stripe_sepa` (Stripe test cards / test IBAN only). Orders 10006–10010: paid, 3-D Secure paid, 3-D Secure failed, declined, SEPA paid by webhook; the metadata link is present on every PaymentIntent and charge; a Stripe refund leaves Shopware `paid`. Production shows them as `MONEY_UNSUPPORTED`/`provider_not_connected` (no Stripe integration on Shopware-Test).
- Run: `ssh bw-prod "docker run --rm -i --security-opt seccomp=/opt/business-watchdog/repo/infra/production/seccomp/bw-browser-worker.json -e BW_SHOP=https://shopware-test.business-watchdog.com -e BW_PAY=stripe -w /app --entrypoint node business-watchdog/browser-worker:<release> --input-type=module -" < infra/test-shops/sw-shopper.mjs`.

### Step 227: Stripe in Shopware — Connector

Status: complete; deployed 2026-10-22 (`906c4d3`) and checked on Shopware-Test

- Implements ADR 0025 parts 4–5 with the owner's decisions (refund only at Stripe = information, mode from the linked Stripe payment, one Stripe account per store). Details: ADR 0025 "Implementation (Step 227)".
- `ShopwareOrderMapper`: PaymentIntent id as `transaction_ref`, `provider_refunds_synced = false`, other Stripe apps unsupported. `StripeEventMapper`: `provider_store_transaction_ref` from `metadata.shopware_order_transaction_id`. `ExactReferenceMatcher`: exact links accept orders without a reported mode. `OrderReconciliationService`: the mode gate is skipped for exactly linked orders; `refund_not_synced_by_payment_app` (`unsupported`). `OrderDto`: mode of the linked payment when the store reported none. Event contract: optional `provider_refunds_synced` (contract and backend copy).
- Cabinet: the Shopware panel says Stripe (the "Stripe Payment" plugin) is checked and refunds made in Stripe are information; the findings table shows a note for `refund_not_synced_by_payment_app` (ru/en/de).
- After deploy once: `shopware:sync --audit`, `stripe:sync --audit`.

Verification: new `ShopwareIntegrationTest::test_stripe_payment_plugin_orders_link_both_ways_take_the_payment_mode_and_refunds_only_at_stripe_are_information` (card order linked by PaymentIntent id in a test-mode account, SEPA order linked by metadata only, declined card without a reference not a discrepancy, refund only at Stripe → information, Shopware refunded without Stripe refund → mismatch, another Stripe app unsupported, no email read, order card shows the payment's mode); it fails with the mode relaxation or the refund rule reverted. Backend SQLite 710 passed + 4 skipped; Providers/Reconciliation/Payments/Ingest on PostgreSQL 18 199/199; Pint ok; customer typecheck and 152/152. 
Production check (2026-10-22): after the deploy `shopware:sync --audit` re-emitted exactly the five Stripe orders (PaymentIntent ids as references, `mode_reported = false`, `provider_refunds_synced = false`); the owner connected the Stripe test account to Shopware-Test with the restricted key in the cabinet; `stripe:sync --audit` read 46 objects. Current results: 10006 capture ok + refund 50 € only at Stripe → `refund_not_synced_by_payment_app` (information); 10007 and 10010 (SEPA) ok, linked by `exact_reference`, payment mode test; 10008 (3-D Secure failed) and 10009 (declined, no reference in Shopware) — no findings. 14 WooCommerce-Test payments in the same account → `other_site`; five old payments without any store marker (one captured on 2026-10-10, before the connection) → no findings. No incidents. Note: `reconciliation_findings` keeps every run; the current result is the run in `reconciliation_subject_states.run_id`.

### Step 229: Stripe Sync Continues Unfinished Windows by Object Id

Status: complete; deployed 2026-10-22

- Reported: when a window exceeded the page budget, `StripeSync` remembered only the oldest `created` second read and the next run asked `created[lt]` that second, so an object created in the same second as the last one read but not yet read was never fetched (webhooks, overlaps or the daily audit could hide it, but nothing guaranteed it). Reproduced in a test before the fix.
- Found while fixing: while an older continuation was still open, a new delta window that also exceeded the budget was not remembered at all (`! isset(backlog_before)`), and its watermark moved on — the middle was lost until an audit.
- Now every unfinished window is kept in `health.sync.resources.<resource>.backlogs[]` with `from`, `until` (`created[lte]`, fixed when the window started), `after` (id of the last object read) and `oldest`; the next run continues with Stripe's `starting_after`. If Stripe no longer knows that object (`400`), the window restarts from its oldest second inclusive (duplicates are idempotent). A continuation saved by the previous release (`backlog_from`/`backlog_before`) is finished inclusively of that second and converted.
- `StripeClient::listCreatedSince()` takes `starting_after` and returns `last_id`.

Verification: `StripeIntegrationTest` new tests — same-second object across the page boundary, a second unfinished window while the first is continued, a vanished cursor, a continuation from the previous release; the first, second and fourth failed before the fix. Backend SQLite 715 passed + 4 skipped, Pint ok. Not checked against the real Stripe API (fixtures only).

### No step number (2026-10-23): WordPress.org Contributor Set

Status: complete; ZIP 0.3.3 built for the WordPress.org submission and handed to the owner

- `readme.txt`: `Contributors: karabin214` (the owner's WordPress.org user name) instead of the placeholder `businesswatchdog`. No code change, version stays 0.3.3.
- Built with `bin/build-release.sh`: 53 files, PHP 7.4 lint ok, SHA-256 `de08405f1dd63088da5e8774e3a0ada4a54223bb7bad291ee7d915682fb5a941`. Plugin Check not rerun (only `readme.txt` changed since Step 223, 0 errors there). Submission was left to the owner at that point; the owner later confirmed it had been submitted (see 2026-10-26 below).

### No step number (2026-10-26): WordPress.org Review and Local Candidate Kept Separate

- The owner confirmed that ZIP 0.3.3 had been submitted to WordPress.org and was still awaiting manual approval. The actual submission date and review reference were not recorded. Its SHA-256 remains `de08405f1dd63088da5e8774e3a0ada4a54223bb7bad291ee7d915682fb5a941`; the exact local file was found at `/private/tmp/claude-501/-Users-karabin-Sites-Bussines-Watchdog/d768be68-c797-40de-8ba8-0447d1156b4a/scratchpad/wporg/business-watchdog-0.3.3.zip` and its hash rechecked. This temporary path is not permanent release storage.
- Step 271 prepared a separate local ZIP 0.3.4 (`/private/tmp/bw-step271-release/business-watchdog-0.3.4.zip`, SHA-256 `859c9bcd317bf9b6f2fa1cff36f7fde16b4a4fa6c0b66987fa4a4525452b48ff`). It sends the store's payment method name; the bundled external-service disclosure was updated. This ZIP was neither submitted to WordPress.org nor approved for customer download. The submitted 0.3.3 ZIP was not overwritten.
- WordPress.org review, source version in git and the SaaS download SHA-256 gate are separate states. The last recorded customer-download approval remains 0.3.2. `docs/ops/integrations.md` now records the artifacts, the existing-review/SVN update procedure and the checks still needed before publishing 0.3.4. No submission, approval or production publication was performed during this documentation update.

### Step 274: WordPress.org Review — Plugin Prefixes and Admin Notices (2026-10-26)

Status: complete; backend part deployed 2026-10-26 (`7be8672`); plugin 0.4.0 uploaded to the WordPress.org review by the owner, not approved for customer download

- Owner's WordPress.org review mail (2026-10-26, automated pre-review of 0.3.3) asked for: prefixes of at least 4 characters for declarations, globals and stored data; admin notices limited in scope (Guideline 11); proof that the submitter owns the name "Business Watchdog".
- Plugin 0.4.0: every table (`{prefix}business_watchdog_outbox|revisions|state|payment_attempts|checkout_observation`), option, scheduled hook, cron schedule (`business_watchdog_every_N`), nonce/`admin_post` action, form field, order meta (`_business_watchdog_payment_mode`, `_business_watchdog_synthetic_run`), script handle and constant (`BUSINESS_WATCHDOG_VERSION|FILE|DIR|MIN_PHP|MIN_WC|ALLOW_INSECURE_ENDPOINT`, JS `BUSINESS_WATCHDOG_CHECKOUT_OBSERVER`) uses the `business_watchdog_` prefix. The HMAC purposes `bw-synthetic-v1`/`bw-observe-v1` and the `X-BW-Synthetic` header are shared with the backend and unchanged.
- `Storage\LegacyNames::migrate()`: renames the old tables when the new ones do not exist, moves the options, clears old schedules; runs on activation, uninstall, and in admin, cron and WP-CLI requests (not in shopper requests) while an old option exists. Background auto-updates therefore do not leave monitoring idle until an admin visit. Old order meta stays readable (`PaymentMode`, `SyntheticOrders::isSynthetic`); the backend REST-API mapper reads `_business_watchdog_payment_mode` then `_bw_payment_mode`.
- Admin notices (stopped, unsupported WooCommerce, schema failure, PHP too old, hard switch) are shown only on the Plugins screen and on the plugin's own page.
- `release-checks.sh`: new check "upgrade moves the data to the current names"; rollback checks are skipped (SKIP) when the previous package still uses the old names, since a downgrade would need a new pairing. `release-probe.php` reads both name sets.
- Name/ownership is the owner's action: TXT record `wordpressorg-karabin214-verification` on `business-watchdog.com` (or a WordPress.org e-mail on that domain); see `docs/ops/integrations.md`.

Verification: plugin integration tests floor and latest 59/59 (new test: data under the old names is moved, connection and integration ID kept, old meta read); `release-checks.sh latest` PASS (upgrade from 0.3.4 on origin/main); PHP 7.4 lint; Plugin Check on the ZIP 0 errors. Backend SQLite 799 passed + 4 skipped. ZIP handed to the owner on the Desktop (`~/Desktop/business-watchdog-0.4.0.zip`); headers `Plugin URI` and `Author URI` point to business-watchdog.com, 54 files, SHA-256 `f05829f91d0ab9d8d3262a8f0510e510664a014fada624cef4b44f9f04c70c1a`.

### Step 276: Stripe Disputes as an Informational Signal (2026-10-26)

Status: complete; deployed 2026-10-26 (`189aec7`, migration `create_provider_disputes_table`)

- Backlog «Споры (chargebacks) как информационный сигнал». ADR 0081, owner's decisions: one incident per dispute (warning), notify at once, remind 3 days before the answer is due, outcome in the digest; a soft hint when the key lacks `Disputes: Read`; PayPal later (new backlog item).
- Contract: event `dispute.snapshot` (aggregate `dispute`, `aggregate_revision` required, `$defs/dispute`) in `contracts/event.schema.json` and the runtime copy; OpenAPI: schema `Dispute`, `dispute` on `Incident`, `disputes` on the order and payment cards, incident families `checkout_observation` and `dispute` added to the enum.
- Data: table `provider_disputes` (migration and `spec/database/schema.sql`): payment link, status, reason, amount, deadline, opened/closed, `reminded_at`, `incident_fingerprint`, `source_revision`; PostgreSQL CHECKs for status, mode and amount. `EventPayloadValidator` and `DisputeSnapshotProjector` (newer revision only; links the payment, or `PaymentSnapshotProjector` links it when the payment arrives later). No `financial_transactions`, the reconciliation is unchanged. Purged by `StoreDeletion` and `OrganizationPurger`.
- Stripe: `StripeEventMapper` maps `dispute` objects (only the listed fields; metadata and evidence are not read); `StripeSync` lists `/v1/disputes` of the last `WATCHDOG_STRIPE_DISPUTE_DAYS` (120) on every run and records `health.sync.disputes.state` (`ok` / `permission_missing`, a 403 never fails the sync); the webhook accepts dispute objects (`charge.dispute.*`).
- `Support\Disputes\DisputeIncidents`: open/attach while open (`DISPUTE_OPEN`, `DISPUTE_INQUIRY` for `warning_*`), self-resolve on the outcome (`auto_resolved_dispute_won|lost|closed`), nothing for a dispute already closed when first seen; signal type `dispute` without amounts. `disputes:remind` (hourly at :35) asks once for an incident reminder when the answer is due within 3 days; `NotificationDelivery::INCIDENT_KINDS` accepts the reminder kind through the outbox.
- Notifications (ru/en/de): fact with order, reason and deadline, «Disputed amount», «Opened», source and advice, subjects incl. reminder, recovery with the outcome; Stripe reason codes translated. `NotificationRenderer` takes family-specific `amount` and `possible_start.not_later_than`.
- Cabinet: `DisputeCard` (status, reason, amount or «hidden», deadline, closed), dispute panel on the incident with a link to the order, «Payment dispute» section on the order card, dispute fact in the evidence, hint `provider.disputes_permission_missing` on the Stripe panel, `Disputes` in the key instruction; incident family labels for `dispute` and the previously missing `checkout_observation`, auto-resolution labels for disputes and checkout observation; titles with the order number for dispute incidents.

Verification: `StripeDisputeTest` (6): sync → dispute linked to payment and order, warning incident and notification request, no money operation and no mismatch, incident/order APIs, German letter text; outcome «lost» resolves the incident and an older revision changes nothing; a dispute already closed opens no incident; 403 on disputes keeps the sync ok with `permission_missing`; reminder requested exactly once and amounts hidden without `money.view`; webhook `charge.dispute.created` feeds the same path; no customer e-mail from metadata stored. Cabinet `tests/disputes.test.ts` (2). Backend SQLite 805 passed + 4 skipped, PostgreSQL 18 809/809, Pint; cabinet typecheck, 196 tests, build. Tested with JSON fixtures of the Stripe API only, not against a real Stripe account.

Production 2026-10-26 (`189aec7`): migration applied (187 ms); deploy without downtime (`probe.sh` 480 s on `/health/ready`, `/app/`, `/owner/`, `/en` — 0 answers other than 200); `disputes:remind` scheduled hourly at :35. `stripe:sync --inline` on the three active Stripe test-mode connections: all `ok`, `health.sync.disputes.state = ok` (the existing restricted keys can read disputes), 0 disputes in these accounts. The incident list of 24 real incidents serializes with the new DTO; no errors in app or worker logs. A real dispute was not observed yet (none in the test accounts).

### Step 277: PayPal Disputes (2026-10-26)

Status: complete; deployed 2026-10-26 (`33fa2cd`, migration `allow_closed_provider_disputes`)

- Backlog «Споры PayPal так же, как Stripe». ADR 0081 decision 8. Same `dispute.snapshot` event, `provider_disputes`, incidents, reminders and cabinet as Step 276.
- `PayPalClient` allowlist: `GET /v1/customer/disputes` and `/v1/customer/disputes/{id}` (read only). `PayPalSync::disputes()` after the transaction windows: list by `update_time_after` (watermark − overlap; first run `WATCHDOG_PAYPAL_DISPUTE_DAYS` = 180), each listed dispute read by id (≤ 50 per run, ≤ 5 pages, `next` links), `health.sync.disputes` = `ok` (with `watermark`, `complete`) / `permission_missing` (403) / `unavailable` (any other error); only rejected credentials fail the sync. `syncDispute()` for webhooks.
- `PayPalDisputeMapper`: capture from `disputed_transactions[].seller_transaction_id`, linked to the PayPal order via the cached capture → order lookup (`payment_external_id` = PayPal order id), status mapping incl. the inquiry stage and outcomes, deadline only while the seller must answer, reason in lower case; buyer data and messages are not read.
- Webhook: `CUSTOMER.DISPUTE.*` → the dispute id from the body (validated) is read through the API.
- New status `closed` (resolved without a known outcome): event contract and runtime copy, OpenAPI, `ProviderDispute::STATUSES`, schema.sql, PostgreSQL CHECK migration; texts «closed» and PayPal reason codes (ru/en/de) in letters and the cabinet.
- Cabinet: hint `paypal.disputes_permission_missing` on the PayPal panel; setup steps mention the optional Disputes feature and the dispute webhook events.

Verification: `PayPalDisputeTest` (4): a chargeback waiting for the seller → dispute linked to the PayPal order payment and the store order, warning incident, order API, watermark, no buyer e-mail stored; mapping of under review, inquiry, won, closed and inquiry closed with the matching auto resolutions; 403 on disputes keeps the payment sync and the integration active; webhook reads the dispute from the API and ignores an invalid id. Existing PayPal tests 12/12 unchanged. Backend SQLite 809 passed + 4 skipped, Pint; cabinet typecheck, 196 tests, build. JSON fixtures of the PayPal API only, not tested against a real PayPal account; the list filter `update_time_after` follows PayPal's documented parameters and is not confirmed on the sandbox yet.

Production 2026-10-26 (`33fa2cd`): migration applied (48 ms); deploy without downtime (`probe.sh` 480 s, ~4800 requests, 0 answers other than 200). `paypal:sync --inline` on the three active sandbox apps: payments sync `ok`, integrations stay active, no `last_error`; disputes `permission_missing` on all three (the apps have no Disputes feature; their write scopes are capture, payments_v1, refund), so the hint shows on the PayPal panels. The `update_time_after` list filter is still unconfirmed against PayPal: it needs an app with Disputes enabled. No errors in app or worker logs.

Production check with Customer disputes enabled (2026-10-26): the owner enabled Features → Add-on services → Customer disputes on the sandbox app (all three PayPal connections use the same app). PayPal kept returning the token issued before the change (no dispute scopes, ~8 h left); with the owner's consent that token was terminated once (`POST /v1/oauth2/token/terminate`, 200), and the new token carries `disputes/read-seller` among others. The dispute list then answered 400 `INVALID_DATE_RANGE`: `update_time_after` may be at most 179 days back, the default 180 was just outside (179/150/121 days → 200). Fix: the window is capped at 179 days (`PayPalSync::DISPUTE_MAX_DAYS`, also for an older watermark), default `WATCHDOG_PAYPAL_DISPUTE_DAYS` = 179; `PayPalDisputeTest` fakes PayPal's limit and runs with a configured 365 days. Backend SQLite 809 passed + 4 skipped, Pint.

Production 2026-10-27 (`a58ce7e`, no migration, deploy without downtime: ~4500 requests, 0 answers other than 200): `paypal:sync --inline` on the three PayPal connections — disputes `ok`, complete, watermark set, no `last_error`; the sandbox app has no disputes in the window, so no dispute was projected yet. The `write_scopes` shown for the connections are from connect time and do not yet list `disputes` (the add-on now also grants `disputes/update-seller`); they refresh on a reconnect.

### Step 278: Stripe Multicapture (2026-10-27)

Status: complete; deployed 2026-10-27 (`95a9e14`, no migration)

- Backlog «Частичные многократные списания (multicapture)». ADR 0082 (financial assumption shown to the owner): one capture operation per charge is the cumulative `amount_captured`.
- `FinancialTransactionProjector::captureGrowth()`: an independent-provider `capture`, succeeded before and after, same currency, exponent and payment, may grow; history in `metadata.amount_history` / `amount_observed_at`. A smaller amount from an older observation is ignored; from a newer one it stays `transaction_operation_conflict`. Other kinds and store-reported operations stay immutable (ADR 0020).
- `PaymentAllocationService::followCaptureGrowth()`: a single active automatic allocation that covered the previous amount grows with it (`evidence.capture_growth`); manual or split allocations keep their amounts; affected orders and unmatched payments are marked for reconciliation.

Verification: `StripeMulticaptureTest` (2): partial capture 40 → order under-captured; capture grows to 100 with the intent succeeded → capture and automatic link 100, history kept, all events processed, no mismatch after reconciliation; a late 40 delivery is ignored; a manual 40 link stays 40 when the capture grows to 70, and a newer 50 stays a conflict. Backend SQLite 811 passed + 4 skipped, PostgreSQL 18 815/815, Pint. JSON fixtures only, not tested against a real Stripe multicapture account (multicapture needs an eligible Stripe account and IC+ pricing).

Production 2026-10-27 (`95a9e14`): deploy without downtime (`probe.sh` 420 s, ~4300 requests, 0 answers other than 200). `stripe:sync --inline` on the three Stripe connections `ok`; all `transaction.observed` events processed, 0 `transaction_operation_conflict`, 0 of 139 captures grew (no multicapture in these accounts); no errors in app or worker logs. A real multicapture was not observed.

### Step 279: Payment Provider Fees as Information (2026-10-27)

Status: complete; deployed 2026-10-27 (`017622a`, no migration)

- Backlog «Комиссии как информационный сигнал, вне сверки денег». ADR 0083, owner's decisions: order card plus a 30-day overview and CSV; Stripe `Balance: Read` optional.
- Fees are `financial_transactions` of kind `fee` with id `fee:<capture id>`; `ExactReferenceMatcher` now matches only captures and refunds (by kind), so a fee is never mistaken for a refund or linked to an order.
- PayPal: `PayPalOrderMapper::fee()` from `seller_receivable_breakdown.paypal_fee` of a succeeded capture.
- Stripe: `StripeSync::fees()` — succeeded captures of the audit window without a fee, `GET /v1/balance_transactions?source=<charge>` (≤ 100 per run, retry after 6 h via cache), `health.sync.fees` `ok` / `permission_missing` (403 never fails the sync); fee in the balance currency.
- API: `fees` on the order card; overview `fees` (last 30 days per payment mode and currency, fee and captured sums, operations; null without `reconciliation.read`, amounts null without `money.view`). OpenAPI updated.
- Cabinet: «Payment provider fee» and «Net after fee» lines on the order card (BigInt arithmetic), «Fees, last 30 days» overview tile with the share of captures, Stripe hint `provider.fees_permission_missing`, `Balance` in the key instruction (ru/en/de).

Verification: `StripeFeeTest` (2): the fee comes from the balance transaction, is linked to the payment, never allocated, reconciliation without mismatch, order API and overview with fee 1.58 € of 89.00 €, a known fee is not requested again; 403 keeps the sync and the integration active with `permission_missing`, and the fee arrives after the permission is added. `PayPalDisputeTest` new fee test (0.84 USD from the capture breakdown, allocation stays 10.00). Cabinet provider test excludes the API field `paypal_fee` from error codes. Backend SQLite 814 passed + 4 skipped, PostgreSQL 18 818/818, Pint; cabinet typecheck, 196 tests, build. JSON fixtures only.

Production 2026-10-27 (`017622a`): deploy without downtime (`probe.sh` 420 s, 0 answers other than 200). `stripe:sync --inline`: fees `ok` on the three Stripe connections (the restricted keys already read balance transactions), 42 fee operations (14 charges × 3 connections to the same Stripe test account), all linked to their payments, none allocated, all in EUR; overview fees of the owner's organisation: 90.75 € of 7,936.02 € captured in test mode (≈ 1.1 %). PayPal delta sync emitted no fees: delta reads only new transactions, so fees of existing PayPal captures arrive with the next daily `paypal:sync --audit` (03:xx) or a new capture. No errors in app or worker logs.

### Step 282: The merchant sees whether WooCommerce webhooks work (2026-10-27)

Status: complete; deployed 2026-10-27 (`4dd089b`, cabinet text fix in the follow-up commit)

- Backlog 11.G for WooCommerce; ADR 0077 decision 6. Owner's report: after creating webhooks the cabinet did not say whether they work.
- `WooCommerceApiWebhooks`: the unsigned ping `webhook_id=N` is kept as `health.webhook_ping` (unverified) and queues `CheckWooCommerceWebhooks` (5 s, unique per connection); an order delivery from the store's host with a wrong signature is kept as `health.webhook_rejected`; a new secret clears all of them. `check()` reads `GET /wc/v3/webhooks?per_page=100&status=all` with the read-only key (added to the GET allowlist) and keeps only this connection's webhooks: per topic `active` / `paused` / `disabled` / `missing`, issues `old_address` (an earlier connection) and `secret_newer_than_webhooks`; a ping counts when its id is an active webhook of this store pointing to us. Other webhooks and their addresses are dropped in memory. `POST /api/v1/integrations/{id}/woocommerce-webhook/check` (integrations managers, `throttle:provider-sync`), OpenAPI.
- Cabinet `WooCommerceWebhookStatus`: stages «not set up», «waiting for a check», «signature not checked» (reached), «check failed» (rejected), «working» (only after a signed delivery); per-topic list with the fix, «Check set-up», polling every 10 s for up to 15 min while waiting, the safe signature test «open any order and press Update», the effect line (polling every 5 minutes; silence is not an error), WooCommerce menu names in the client's language with the English names, a screenshot of the webhook form from WooCommerce-Test (Delivery URL and Secret hidden).

Live on production with WooCommerce-Test 11.2.0 (store «BW», REST API connection): the check read the store's 4 webhooks (all active) and found `secret_newer_than_webhooks` — the secret had been re-created at 15:56:24 on 2026-10-23, after the webhooks were saved at 15:54–15:56, so instant updates had never worked and the cabinet showed only «waiting». Pressing Update on order 29 in wp-admin produced a real signed `order.updated`: rejected with `signature_invalid`, WooCommerce `failure_count` 1. A new secret was put into webhooks 1–3 through wp-admin, webhook 4 was deleted and re-created as 5: WooCommerce's ping arrived, the queued check verified it (`ping.verified`, check `ok`); Update on order 29 again → signed delivery accepted (`webhook.last_type = order.updated`), `failure_count` back to 0 everywhere. The cabinet block was checked in Chromium on the local stand with the same five states.

Verification: `WooCommerceApiWebhooksTest` (4): ping kept and check queued; rejected signature kept; new secret clears the state; the check against a faked store list (trailing slash, disabled, missing, an earlier connection's address, another service's webhook not stored, stale secret, then ok, then 401 → `not_readable`); viewers cannot check; the queued job reads once. Customer `orderSource.test.ts`: every stage, never «working» before a signed event, the check button. Backend SQLite 817 passed + 4 skipped, Pint, `spec/validate_spec.py` (283 operations); customer typecheck, 207 tests, build. Stripe and PayPal webhook confirmation — next step.

### Step 283: The merchant sees whether Stripe and PayPal webhooks work (2026-10-27)

Status: complete; deployed 2026-10-27 (`14e0754`, no migration)

- Backlog 11.G for Stripe and PayPal; ADR 0020 and 0022 amended. Before: after saving a secret or Webhook ID the panel said «set up» without any event, and a wrong secret / Webhook ID failed silently.
- Any event that passes verification is now `health.webhook` (Stripe: also event types we do not take in; PayPal: any verified event). A Stripe-format signature that fails with the saved secret, or PayPal headers that PayPal does not verify with the saved Webhook ID, is kept as `health.webhook_rejected`. Saving a new secret / ID clears `webhook`, `webhook_rejected`, `webhook_check`.
- `ProviderWebhookCheck` + `POST /api/v1/integrations/{id}/webhook-check` (integrations managers, `throttle:provider-sync`): Stripe `GET /v1/webhook_endpoints` with the restricted key (optional permission Webhook Endpoints: Read, 403 → `permission_missing`), the endpoint with this connection's address must be enabled and cover `payment_intent.*`, `charge.*`, `refund.*`; PayPal `GET /v1/notifications/webhooks` (added to the GET allowlist), the webhook with this address must have the saved ID (`webhook_id_mismatch`) and the six capture/order events. Other endpoints are not stored. The cabinet runs the check right after a secret / ID is saved.
- Cabinet `ProviderWebhookStatus` in the Stripe and PayPal panels: «not set up», «waiting for the first event» (with how to get one: Stripe Resend of a recent event; PayPal — simulator events cannot be verified), «check failed» with the fix, «working»; check results; «Check set-up». Stripe instructions: Workbench → Webhooks (formerly Developers → Webhooks), optional Webhook Endpoints: Read in the key permissions.

Live on production (owner's test stores only): the check on the six Stripe/PayPal connections of BW, Woo-Test and Shopware-Test — Stripe `permission_missing` (the restricted keys have no Webhook Endpoints: Read), PayPal `endpoint_missing` (the sandbox app had no webhook for any of our addresses). With the owner's permission: a PayPal sandbox webhook `19E93928EC726853K` was created for BW's address (6 capture/order events + 3 dispute events) and saved in BW → check `ok`; a 1.00 EUR sandbox order approved by the sandbox buyer and captured (`8C766418BE355374N`) → **the first real PayPal webhook delivery**: `PAYMENT.CAPTURE.COMPLETED` verified by PayPal with the saved ID, payment taken into BW (test mode, no allocation, no incident). A Stripe test endpoint `we_1UV8XUQHjXkT04LtyIUKfgEP` was created for BW's address and its secret saved; a 1.00 EUR test PaymentIntent with `pm_card_visa` → signed `charge.succeeded` at BW within seconds. The test payments are not linked to an order; the shared test Stripe account is also read by Woo-Test and Shopware-Test.

Verification: `StripeIntegrationTest` (rejected signature kept, garbage header not kept; any verified event counts; new secret clears; endpoint check — missing events, disabled, ok, missing, 403), `PayPalDisputeTest` (webhook id mismatch + missing events, ok, missing, rejected verification kept, any verified event counts); customer `provider.test.ts` (every stage, never «working» after only saving a secret, check after saving). Backend SQLite 820 passed + 4 skipped, Pint, `spec/validate_spec.py` (284 operations); customer typecheck, 209 tests, build.

### Step 284: WooCommerce plugin published in the WordPress.org directory (2026-10-27)

Status: complete; no code change

- WordPress.org approved the plugin on 2026-10-27 (Review ID `APPROVED business-watchdog/karabin214/26Oct26/T2 27Oct26/4.3`) after the 0.4.0 answer of Step 274 and the TXT domain verification.
- Committed to `https://plugins.svn.wordpress.org/business-watchdog` as revision 3768832: `trunk/` and `tags/0.4.0/` are the contents of the exact ZIP the owner uploaded to the review (SHA-256 `f05829f91d0ab9d8d3262a8f0510e510664a014fada624cef4b44f9f04c70c1a`, not rebuilt); `assets/` — the two banners, two icons and four screenshots from `plugins/woocommerce-watchdog/.wordpress-org/` (PNG mime type set). Screenshots checked before publishing: demo data only, no real personal data or secrets.
- SVN ran in an `alpine:3.20` Docker container: Homebrew builds `subversion` from source on macOS 14. The SVN password is the owner's, used for the one commit with `--no-auth-cache`, not stored.
- Public page https://wordpress.org/plugins/business-watchdog/ answers 200 with version 0.4.0.
- `docs/ops/integrations.md`: directory details and the release procedure for new versions.

Screenshot 3 retaken and cabinet download approved — Step 285.

### Step 285: The cabinet hands out plugin 0.4.0 and points to the WordPress.org directory (2026-10-27)

Status: complete; deployed 2026-10-27 (`f398422`, no migration)

- Owner approved the ZIP 0.4.0 for cabinet download — the same file that is in the WordPress.org directory (SHA-256 `f05829f91d0ab9d8d3262a8f0510e510664a014fada624cef4b44f9f04c70c1a`). On the server: copy the file into `storage/app/plugins`, set the hash in `.env`, deploy (`docs/ops/integrations.md`). Archives with another hash (0.3.2) are no longer served.
- `PluginDownload`: under the download button «You can download the plugin here or install it from the WordPress.org directory…» with a link to https://wordpress.org/plugins/business-watchdog/ (new tab); the link opens the connection guide with the code like the download does. Shown also when no ZIP is available. Guide step «upload» names both ways. Texts ru/en/de.
- Directory screenshot 3 retaken (SVN r3768857): local stand, «Demo Store» paired with plugin 0.4.0 from the matrix wc9 WordPress through a connection code; the plugin challenge was served by the plugin and handed to the verification check by hand because the local backend cannot resolve `shop-wc9.example.test`. Demo data only.
- CI «Backend (PostgreSQL 18)» had failed since Step 282: four test assertions compared `health` arrays from `jsonb` with `assertSame`, which depends on key order. Now `assertEquals`; the cabinet already lists webhook topics in its own fixed order.

Verification: backend SQLite 820 + 4 skipped, PostgreSQL 18 824/824, Pint; cabinet types, 210 tests, build; the download block checked on the local stand in ru and en.

Production (2026-10-27): ZIP 0.4.0 copied into `storage/app/plugins` (hash checked on the server), `WATCHDOG_WOO_APPROVED_SHA256` set to it, deployed; `PluginPackage::latest()` returns 0.4.0, 64918 bytes. At the owner's request the 0.3.2 ZIP was deleted from the server. The deployed `StoreView` chunk contains the directory link.
