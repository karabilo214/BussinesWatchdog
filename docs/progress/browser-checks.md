# Progress: Browser checks

Browser check backend, Playwright worker, synthetic marker, egress proxy, redacted screenshots.

### Step 51: Browser Check Backend (Spec §18–20, ADR 0010)

Status: complete for the backend; the worker follows in Step 52

Added:

- Browser check tables, worker credentials, `browser:schedule`, internal lease/heartbeat/result API with fencing and expiry recovery.
- Outcome policy (two site failures confirm; worker problems never do), checkout and coverage incidents with scheduled-pass recovery, notifications (ru/en/de).
- Strict validation of sanitized worker results; user API for the scenario, manual runs and run history; OpenAPI updated.
- `docs/adr/0010-browser-check-backend.md`, `docs/implementation-step-51-checklist.md`.

Verification:

- SQLite 298 passed + 4 skipped; PostgreSQL 18 302 passed.

Remaining:

- Worker (Step 52); artifacts, synthetic marker, Blocks draft cleanup (Step 53).

### Step 52: Browser Worker (Node + Playwright, ADR 0011)

Status: complete; real browser checks reach the payment form on all six WooCommerce targets without placing an order

Added:

- `apps/browser-worker`: lease loop, WooCommerce adapter (Classic + Blocks), network policy (cart-only mutations, order/pay/capture/refund blocked), sanitized diagnostics, Docker image (sandbox on, read-only, no capabilities), Compose service, unit tests.
- `tests/matrix/browser-e2e.sh` (`make worker-e2e`) with the real backend; the matrix mu-plugin can disable its test gateway for the no-payment-methods case.
- `docs/adr/0011-browser-worker.md`, `docs/implementation-step-52-checklist.md`, compatibility table.

Verification:

- Worker unit tests 10/10; browser e2e PASS on all six targets with unchanged order counts; backend suites green.

Remaining:

- Egress proxy, artifacts, synthetic marker, Blocks draft cleanup (Step 53).

### Step 53: Synthetic Marker, Blocks Draft Cleanup, Egress Proxy (ADR 0012)

Status: complete

Added:

- Backend synthetic marker in the lease; worker sends it to the store origin only; plugin verifies it, marks orders, flags synthetic events, skips payment-attempt monitoring and cleans only its own old checkout drafts.
- Squid egress proxy (`infra/docker/egress`), Compose isolation of the worker, `make egress-test`; the worker refuses production without the proxy.
- `docs/adr/0012-synthetic-marker-and-egress.md`, `docs/implementation-step-53-checklist.md`.

Verification:

- Backend SQLite 299 + 4 skipped, PostgreSQL 303; worker 13/13; plugin integration and browser e2e on all six targets (marker verified per run, no orders, no payment attempts); egress 7/7.

Remaining:

- Screenshots/artifacts (Step 54).

### Step 54: Check Artifacts — Redacted Screenshots (ADR 0013)

Status: complete; browser checks P0 done (Steps 51–54)

Added:

- `artifacts` table and private S3 disk; lease-bound internal upload with size/signature/SHA-256 checks; 60-second user links; daily purge; OpenAPI.
- Worker: masked viewport screenshot on failures, focused on the failing step.
- `docs/adr/0013-check-artifacts.md`, `docs/implementation-step-54-checklist.md`.

Verification:

- Backend SQLite 302 + 4 skipped, PostgreSQL 306; worker 13/13; browser e2e on six targets with S3Mock; screenshot inspected.

### Step 140: Browser Checks for Shopware — Three Ways to Reach Payment Methods (ADR 0055)

Status: complete

Why: browser checks were built when WooCommerce was the only connector; a Shopware store could create and enable a scenario, but every run ended `blocked` with `not_a_woocommerce_product_page`, unnoticed. Owner decision 2026-10-14: the customer chooses per store how the check reaches payment methods — stop before sign-in, test customer or guest; the checkout observation script (next steps) works only with the last two.

Added:

- ADR 0055. Spike on Shopware 6.6.10.6 and 6.7.2.2 storefronts: payment methods appear only on `/checkout/confirm` after sign-in or guest registration; guest registration creates a guest customer each time; a signed-in customer's cart persists between sessions; 6.6/6.7 name the name fields differently.
- Backend: adapter by store platform (`woocommerce-payment-form@1`, `shopware-payment-form@1`, 422 `platform_not_supported` otherwise); migration `check_scenarios.checkout_access` + encrypted test customer account (`sign_in_ciphertext`, keyring) and moving existing Shopware scenarios to the Shopware adapter; scenario API takes `checkout_access` and write-only `sign_in` (422 `sign_in_required` / `sign_in_not_applicable` / `checkout_access_not_applicable`), returns `platform`, `checkout_access`, `sign_in_configured`, steps and untested parts per way; the account is decrypted only into the worker lease (never into the run snapshot or audit; audit records `sign_in_changed`); switching away from the test customer deletes it. Error code `test_account_rejected` → info incident `CHECKOUT_TEST_ACCOUNT_REJECTED` (component `test_account`) resolved by the next pass; notification texts ru/en/de. Shopware defaults: `/checkout/cart`, `/checkout/confirm`. OpenAPI updated (scenario, lease, `sign_in` action).
- Worker: `adapter/shopware.js` (product → add to cart → cart → checkout → sign-in or guest details → payment methods and «Submit order» available, never clicked; after a test customer run the test product is removed from the account's cart, also after a failure); shared helpers in `adapter/common.js`; adapter chosen by `adapter_version`; Shopware network policy: cart operations allowed, sign-in only for the test customer, registration only as a guest without account/password (`customer_account_creation` otherwise), `checkout/order`, `account/order/…`, `/payment/…` (any method) and Store API checkout/payment blocked; login/registration forms and addresses masked on screenshots.
- Customer dashboard: «Как проверка доходит до способов оплаты» in the scenario form for Shopware stores (three ways with explanations; e-mail + password for the test customer, kept when left empty; the guest way warns «около N в сутки» guest customers at the chosen frequency); ru/en/de; owner panel labels for the new incident and error.
- `plugins/shopware-app/tests/browser/browser-e2e.sh` (`make worker-e2e-shopware`): real Shopware in Docker, worker image, its own database `bw_browser_e2e` (migrated fresh).

Found while testing:

- The first e2e version used the local stand database, so its worker leased six queued runs of leftover WooCommerce «Browser E2E» test stores (not real stores) and opened five info incidents «check not running» on them; the script now uses its own database. The stand rows were left as they are.
- Shopware 6.6 posts `/country/country-state-data` (regions of the chosen country) while the guest form is filled; it only reads, so the policy allows it — first blocked as an unknown mutation, which ended the guest run as unsupported.
- vue-i18n treats `@` as a linked message: the synthetic e-mail in texts is written as `{'@'}`.

Verification: `tests/Feature/Browser/ShopwareBrowserChecksTest.php` (4: ways and validation, account encrypted/hidden/kept/deleted and audit without it, platforms without an adapter, account only in the lease, rejected account → info incident resolved by a pass). Worker 15 tests (+2 Shopware policy). Customer 123 tests (+4), owner panel 55. Backend SQLite 563 passed, 4 skipped; PostgreSQL 18: 567 of 567. Shopware browser e2e: PASS on 6.6.10.6 and 6.7.2.2 (dockware).

Remaining: server-side observation of real Shopware payment attempts (App webhooks); checkout observation script (layer 2) for the test customer and guest ways. ADR 0055 details confirmed by the owner on 2026-10-14 as they are.
