# Progress: Browser checks

Browser check backend, Playwright worker, synthetic marker, egress proxy, redacted screenshots.

### Step 51: Browser Check Backend (Spec §18–20, ADR 0010)

Status: complete for the backend; the worker follows in Step 52

Added:

- Browser check tables, worker credentials, `browser:schedule`, internal lease/heartbeat/result API with fencing and expiry recovery.
- Outcome policy (two site failures confirm; worker problems never do), checkout and coverage incidents with scheduled-pass recovery, notifications (ru/en/de).
- Strict validation of sanitized worker results; user API for the scenario, manual runs and run history; OpenAPI updated.
- `docs/adr/0010-browser-check-backend.md`, `docs/implementation/implementation-step-51-checklist.md`.

Verification:

- SQLite 298 passed + 4 skipped; PostgreSQL 18 302 passed.

Remaining:

- Worker (Step 52); artifacts, synthetic marker, Blocks draft cleanup (Step 53).

### Step 52: Browser Worker (Node + Playwright, ADR 0011)

Status: complete; real browser checks reach the payment form on all six WooCommerce targets without placing an order

Added:

- `apps/browser-worker`: lease loop, WooCommerce adapter (Classic + Blocks), network policy (cart-only mutations, order/pay/capture/refund blocked), sanitized diagnostics, Docker image (sandbox on, read-only, no capabilities), Compose service, unit tests.
- `tests/matrix/browser-e2e.sh` (`make worker-e2e`) with the real backend; the matrix mu-plugin can disable its test gateway for the no-payment-methods case.
- `docs/adr/0011-browser-worker.md`, `docs/implementation/implementation-step-52-checklist.md`, compatibility table.

Verification:

- Worker unit tests 10/10; browser e2e PASS on all six targets with unchanged order counts; backend suites green.

Remaining:

- Egress proxy, artifacts, synthetic marker, Blocks draft cleanup (Step 53).

### Step 53: Synthetic Marker, Blocks Draft Cleanup, Egress Proxy (ADR 0012)

Status: complete

Added:

- Backend synthetic marker in the lease; worker sends it to the store origin only; plugin verifies it, marks orders, flags synthetic events, skips payment-attempt monitoring and cleans only its own old checkout drafts.
- Squid egress proxy (`infra/docker/egress`), Compose isolation of the worker, `make egress-test`; the worker refuses production without the proxy.
- `docs/adr/0012-synthetic-marker-and-egress.md`, `docs/implementation/implementation-step-53-checklist.md`.

Verification:

- Backend SQLite 299 + 4 skipped, PostgreSQL 303; worker 13/13; plugin integration and browser e2e on all six targets (marker verified per run, no orders, no payment attempts); egress 7/7.

Remaining:

- Screenshots/artifacts (Step 54).

### Step 54: Check Artifacts — Redacted Screenshots (ADR 0013)

Status: complete; browser checks P0 done (Steps 51–54)

Added:

- `artifacts` table and private S3 disk; lease-bound internal upload with size/signature/SHA-256 checks; 60-second user links; daily purge; OpenAPI.
- Worker: masked viewport screenshot on failures, focused on the failing step.
- `docs/adr/0013-check-artifacts.md`, `docs/implementation/implementation-step-54-checklist.md`.

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

### Step 154: Audit of Scheduled Browser Checks on Real Storefronts (Spec §18–21, ACC-15, ACC-17, ACC-19–24)

The local scheduler and worker were inspected end to end. The scheduler runs every minute, snapshots an eligible verified store's scenario, and the worker leases and reports an attempt. Backend tests confirm fencing, retries, incident confirmation after two site failures, and scheduled recovery. The local stand had no connected real stores: all 31 enabled scenarios were leftover fixtures (`example.test`, `wordpress`, `check-shop.example`). They generated repeated `blocked_external_origin`/timeout results. These 31 local scenarios were disabled; `browser:schedule` then returned `due=0 created=0 recovered=0`. Historical runs and incidents remain for audit.

Real storefront runs used the production sandbox and egress proxy, with no order submission:

- Thompson + Hanson, WooCommerce product `/product/46-1659/`: initially the scheduled adapter clicked without choosing the required variation and falsely reported `site_failure`. Its functional WooCommerce scripts load from `c0.wp.com`; without that explicitly allowed origin, the browser could not run variation logic and again reported a false site failure. With the functional CDN origins allowed and variation selected, the cart had a product but the first of two cart rows was hidden and the adapter incorrectly returned `selector_changed`. After fixes the check passed product, add-to-cart, cart, checkout and classic payment-method presence. Shipping was skipped because no synthetic location was configured. With the CDN intentionally excluded the check now returns `blocked_external_origin`, not checkout failure.
- Christopeit Sport, Shopware product `/pulsmessgurt-bluetooth/30-2209`: a delayed consent dialog and a noninteractive duplicate buy button initially caused `infra_timeout`. The adapter now declines optional cookies, waits for the dialog to close, selects the interactive buy button, and passes product, cart and checkout. `stop_before_sign_in` stops at the sign-in/registration page as designed; payment methods were not observed on this real store.

Worker now rejects an adapter assertion with `passed: false` instead of reporting the step and run as passed. An isolated Playwright test covers a Woo variation, a hidden first cart row and the missing expected checkout observation script. Verified: worker unit tests 25/25; scheduled Woo Playwright 2/2; backend browser tests 25/25 with `APP_ENV=testing`; live Woo full flow and Shopware stop-before-sign-in flow. The worker image was rebuilt and restarted. No migrations or contracts changed.

Remaining: the local stand cannot exercise a full scheduled lease against an actual connected merchant until one is verified and enrolled. The worker still does not verify the expected product/variant identity, positive cart total, checkout control, delivery option or that a payment choice is enabled, all required by Spec §18. A missing expected observation script is now inconclusive, but has no dedicated coverage incident. The Shopware guest/test-customer methods were covered by prior dockware e2e, not on this third-party store. Functional CDN origins must be explicitly configured per merchant.


### Step 155: Checkout Control Fault Detection (Spec §18–21; ACC-15, ACC-19)

The scheduled WooCommerce adapter now verifies that the cart's checkout control is visible and enabled, clicks it, and requires navigation to checkout. A disabled control or a cart script that swallows its click is a site failure. Two Playwright tests reproduce these faults. This still stops before placing an order; the separate local purchase robot in `progress/connectors.md` submits a sandbox order. The scheduled worker still needs positive cart total, exact item/variant and shipping/payment-choice assertions before full §18 coverage can be claimed.

### Step 156: Shopware Scheduled Checkout Control (Spec §18–21; ACC-15, ACC-17, ACC-19)

The Shopware adapter now treats a disabled buy control without an out-of-stock notice as a site failure. In the cart, it requires a visible, enabled checkout control, clicks it, and confirms navigation; previously it jumped directly to the checkout URL, missing a disabled button or a cart script that intercepted the click. Four Playwright e2e cases cover healthy navigation, disabled product control, disabled cart control, and intercepted cart click. The production adapter passed against both local Shopware 6.6 and 6.7 images without creating orders. It still stops before order submission, and the remaining §18 item/variant, positive total, delivery and payment-choice checks are open.

### Step 157: Cart Identity, Amount, Delivery and Payment Readiness (Spec §18–21; ACC-15, ACC-17, ACC-19)

Both scheduled adapters now match the visible cart line to the selected product; WooCommerce also checks the selected variation labels. They require a positive line amount and cart total, parsed as integer minor units, before accepting the cart. This checks the actual cart amount, not strict equality with the catalog price: an automatic promotion or tax change can legitimately alter the latter. The separate purchase fault labs test a wrong cart price against the product page. Missing readable amount is inconclusive; a zero or negative amount or wrong product is a site failure.

Shopware's `test_customer` and `guest` scenarios now include a shipping step after reaching the confirm page. It verifies an enabled selected or selectable delivery method; the payment step likewise requires an enabled selected or selectable method and an enabled order submit control. `stop_before_sign_in` still ends at the registration/login page and advertises delivery and payment as untested. WooCommerce checks delivery only when a synthetic location is configured, and keeps the step skipped otherwise; its payment method must now be selectable. The scheduled worker never submits an order or payment.

Verification: browser-worker unit 27/27, scheduled Shopware Playwright 11/11, scheduled WooCommerce Playwright 11/11; backend browser-filtered suite 32/32 (329 assertions), including `ShopwareBrowserChecksTest` 4/4. The real local Shopware 6.6.10.29 and 6.7.15.1 storefronts passed all three scheduled modes and the purchase-fault matrix; each scheduled phase created zero orders. Local WooCommerce 11.2.0 passed the scheduled adapter with Classic and Blocks checkout, with zero orders from the scheduled phase; the lab's virtual test product correctly skipped shipping. No migrations or API contract schema changed.

Remaining: a full scheduler/lease run against an enrolled external merchant was not available. Third-party custom themes and checkout plugins may need reviewed selectors; an unrecognised layout must remain inconclusive or unsupported. The scheduled amount assertion is positive amount, not catalog-price parity, and WooCommerce's physical shipping path has Playwright fixture coverage but was not exercised on the virtual-product lab.

### Step 192: Browser Worker on the Production Server

Status: complete

- Worker `worker-1` created on the server; the token went straight into `.env` (`BW_WORKER_TOKEN`) without being printed; `deploy.sh` starts the `browser` profile (worker + egress proxy).
- The Chromium sandbox failed on Ubuntu 24.04 (Docker 29.9.1): Docker's default seccomp profile lets `unshare`/`clone`/`setns` of new namespaces only with `CAP_SYS_ADMIN`, and the worker drops all capabilities. `unshare -Ur` succeeded with seccomp off, and also under Docker's default AppArmor profile, so AppArmor needed no change (a custom AppArmor profile tried first was removed; `deploy.sh` unloads it). New `infra/production/seccomp/bw-browser-worker.json`: moby/profiles `seccomp/default.json` @ 6fe7deb1 plus one rule allowing `unshare`, `clone`, `setns`, `chroot`; `clone3` stays `ENOSYS` (glibc falls back to `clone`). The sandbox stays on (ADR 0011, TZ §Worker); capabilities still dropped, `no-new-privileges`, read-only root.
- README: creating/rotating the token on the server, the seccomp profile, how to check `worker_started … "sandbox":true`.

Verification: on the server — default seccomp: `unshare failed: Operation not permitted`; the new profile: `userns-ok` and `chromium.launch({chromiumSandbox: true})` → Chromium 140.0.7339.16. The worker logged `worker_started` with `sandbox: true`, `egress_proxy: true`, and `last_seen_at` was set. A public storefront check of `https://thompsonhanson.com` submitted through `PublicStorefrontCheckService` on the server finished `passed` in 28 s (`delivery: accepted`), traffic through the egress proxy.
