# ADR 0070: Browser checks and payment forms that wait for gateway scripts

Status: point 1 accepted by the owner on 2026-10-20 and implemented in Step 207 (extended in Step 292); point 2 accepted by the owner on 2026-10-27 and implemented in Step 293.

## Context

The live woo-test store (WooCommerce 11.2 Blocks checkout, Stripe Gateway, PayPal Payments; Step 206) showed that the Blocks payment block renders its methods only after every registered gateway's script has answered. Browser checks load only the store's own origin (plus origins the customer lists), so on such stores the payment block never leaves its loading skeleton and the check ended `inconclusive / selector_changed` — a false «the page changed». Listing the gateway origins by hand is not realistic: Stripe's express buttons pull Apple Pay, Google Pay, Amazon Pay, hCaptcha and Link; PayPal pulls several API hosts. Amazon Pay's `cPSPcheckout.js` also trips the worker's «blocked store script» heuristic.

## Decision

1. **Honest result now.** When the Blocks payment step shows no methods and the attempt blocked third-party scripts, the worker marks the payment step **skipped** with the assertion `payment_methods_not_verified` (`gateway_scripts_not_loaded`). The path up to the payment form counts as confirmed; the cabinet and the owner panel show the reason next to the skipped step. Without blocked third-party scripts a missing method list stays `selector_changed`.
2. **Next: a full payment-form check as a check setting** («проверять способы оплаты полностью»): the service itself allows the known origins of payment providers (Stripe, PayPal and the wallets their scripts load), still blocks every payment, capture and refund call by path, and narrows the «store script» heuristic to the store origin. Needs its own design: synthetic traffic to providers' fraud signals, PayPal's client-token rate limit for checks from one IP.

## Consequences

- Stores with Stripe/PayPal on the Blocks checkout get passing checks with a visible limitation instead of false incidents.
- Classic checkout is unaffected (methods are rendered by the server).

## Amendment (Step 292, 2026-10-27)

On WooCommerce 11.2 with the official Stripe gateway 11.1 the Blocks payment step does not stay in its skeleton: without Stripe's script WooCommerce shows «There are no payment methods available». Point 1 now also covers this: no selectable method on Blocks **and a blocked payment-provider script** (hosts of Stripe, PayPal, Braintree, Klarna, Mollie, Adyen, Google Pay) → skipped `payment_methods_not_verified / gateway_scripts_not_loaded`. Other blocked third-party scripts (analytics) do not turn a missing method list into «not verified».

## Point 2 implemented (Step 293, 2026-10-27)

- **Setting.** `load_payment_providers` in the scenario definition, cabinet «Check payment forms fully» in the scenario's advanced settings, **off by default**: the provider sees the check's page loads like visits of its form, so the merchant decides. The lease carries it as `network_policy.payment_providers`.
- **What it allows.** Hosts of known payment providers by suffix — Stripe (`stripe.com`, `stripe.network`, `stripecdn.com`), PayPal (`paypal.com`, `paypalobjects.com`), Braintree, Klarna, Mollie, Adyen, Google Pay — over HTTPS without a port, **read methods only** (GET, HEAD, OPTIONS). Every other request to them is blocked as `provider_write`: no payment method, intent, confirmation, capture or refund can be created, and the providers' telemetry and fraud-signal POSTs (`r.stripe.com`, `m.stripe.com`) are not sent either. Wallet hosts outside the list (Apple Pay, Amazon Pay, hCaptcha, `gstatic.com`) stay blocked; the methods themselves still render.
- **What it detects.** With the scripts loaded the Blocks payment step sees the real methods (`payment_method_selectable`). A 401/403 from a provider host on such a read (Stripe answers `GET /v1/elements/sessions` with 401 for an invalid or rolled publishable key, and the customer sees «Invalid API Key provided» instead of the card form) fails the payment step `site_failure / payment_provider_rejected`; the cabinet names the cause («The payment provider rejected your store's key…»).
- **Not covered yet.** PayPal's client-token rate limit for repeated checks from one IP (seen in Step 206) is not handled specially; a provider form that loads but stays empty without a 401/403 is not detected.

