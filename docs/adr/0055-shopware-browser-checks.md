# ADR 0055: Browser checks for Shopware stores and how they reach the payment step

Status: accepted (owner decision 2026-10-14: all three ways, chosen by the customer per store; the checkout observation script works only with a test customer or a guest). Details below confirmed by the owner on 2026-10-14 as they are.

## Context

Browser checks (spec §18–21, ADR 0010–0013) were built when WooCommerce was the only connector; the worker had one adapter (`woocommerce-payment-form@1`). Shopware stores (ADR 0025–0027) could create and enable a check scenario, but every run ended `blocked` with `not_a_woocommerce_product_page`: the customer saw a check that could never work.

Shopware 6 storefront (verified on 6.6.10.6 and 6.7.2.2):

- Add to cart is a multipart `POST /checkout/line-item/add`; the cart is `/checkout/cart`.
- `/checkout/confirm` redirects a visitor without a session customer to `/checkout/register`: a collapsed login form and a registration form. Payment methods and the «Submit order» form (`POST /checkout/order`, fields `tos`, `hash`) are on `/checkout/confirm` only, so **payment methods cannot be seen without signing in or registering as a guest**.
- Guest registration (`POST /account/register` with `createCustomerAccount` unchecked) **creates a guest customer record in the shop** each time. 6.6 names the name fields `firstName`/`lastName`, 6.7 `billingAddress[firstName]`/`[lastName]`.
- A signed-in customer's cart is kept between sessions and merged at sign-in: without cleanup the test customer's cart grows by one item per run.

## Decisions

1. **Adapter by platform.** The scenario's adapter follows the store platform: `woocommerce-payment-form@1` or `shopware-payment-form@1`; saving the scenario updates it, and a run snapshot carries `platform`. A platform without an adapter is refused when the scenario is saved (`422 platform_not_supported`) instead of running checks that cannot work. Existing scenarios of Shopware stores are moved to the Shopware adapter with the first way (below).
2. **Three ways to reach the payment step** — `checkout_access`, chosen by the customer in the store's check settings (Shopware only; for WooCommerce it is not used — guest checkout shows payment methods without an account):
   - **`stop_before_sign_in`** (default): product → add to cart → cart → checkout page offers sign-in/registration. Nothing is created in the shop. **Payment methods are not checked** — shown as untested.
   - **`test_customer`**: the worker signs in with a customer account the merchant created for checks (e-mail + password), reaches `/checkout/confirm` and checks that payment methods are offered and «Submit order» is available. After the check (also after a failure) the worker removes the test product from the account's cart. Wrong credentials give a separate status `test_account_rejected` (incident «test customer account rejected», severity info), not a broken shop.
   - **`guest`**: the worker registers as a guest with synthetic data (name «Watchdog Check», e-mail `watchdog-check@example.com`, street «Teststraße 1», the scenario's synthetic postcode or `10115`, the shop's default country, private customer) and reaches `/checkout/confirm`. **Every run creates one guest customer in the shop** (≈ 96 a day at the 15-minute interval) — the settings say this and show the expected number per day; the merchant can find them by the e-mail. Double opt-in for guests or a captcha on the form ends the run as not checkable (`adapter_unsupported` / `waf_challenge`), never as a broken shop.
3. **Test customer credentials** are stored encrypted with the keyring (like integration secrets), never returned by the API (only `sign_in_configured` and nothing else), not written into the run snapshot or audit (audit records only that they changed). They are added to the lease the worker receives and live only for that attempt; inputs are masked on screenshots and request bodies are never stored. Switching away from `test_customer` deletes them.
4. **Network policy for Shopware** (store origin, every non-read request): allowed — `checkout/line-item/add`, `checkout/line-item/delete/{id}`, `checkout/configure`, `country/country-state-data` (6.6 region lookup, read only); `account/login` only with `test_customer`; `account/register` only with `guest` and only as a guest (no `createCustomerAccount`, no password). Blocked — `checkout/order` (`place_order`), `account/order/…` (`order_pay_submit`), anything under `/payment/` (`payment_capture`), Store API `checkout/order` and `handle-payment`, and any other mutation (`unknown_mutation`). Gateway capture/confirm paths stay blocked as before.
5. **Checkout observation script** (spec §17.1 layer 2) for Shopware works only with `test_customer` or `guest`: only then does the check reach the payment page where the script must be confirmed. Server-side observation of real payment attempts (layer 1 analogue from App webhooks) does not depend on the way. Neither is part of this ADR's step; they are next steps.

## Confirmed by the owner (2026-10-14)

- The guest way has no longer minimum interval: any allowed interval; the settings warn with the per-day number of guest customers.
- Synthetic guest data and e-mail stay fixed, the same for every shop.
- As for WooCommerce, only the offer of a payment method is checked, never the payment.

## Verification

`plugins/shopware-app/tests/browser/browser-e2e.sh` on Shopware 6.6.10.6 and 6.7.2.2: all three ways, a repeated test customer run with the cart cleaned, a wrong password (`test_account_rejected`), a sold-out product (`product_unavailable`), exactly one guest customer per guest run, no order created, the password never stored with results — PASS.
