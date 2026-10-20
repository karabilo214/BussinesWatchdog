# ADR 0070: Browser checks and payment forms that wait for gateway scripts

Status: point 1 accepted by the owner on 2026-10-20 and implemented in Step 207; point 2 is the next step.

## Context

The live woo-test store (WooCommerce 11.2 Blocks checkout, Stripe Gateway, PayPal Payments; Step 206) showed that the Blocks payment block renders its methods only after every registered gateway's script has answered. Browser checks load only the store's own origin (plus origins the customer lists), so on such stores the payment block never leaves its loading skeleton and the check ended `inconclusive / selector_changed` — a false «the page changed». Listing the gateway origins by hand is not realistic: Stripe's express buttons pull Apple Pay, Google Pay, Amazon Pay, hCaptcha and Link; PayPal pulls several API hosts. Amazon Pay's `cPSPcheckout.js` also trips the worker's «blocked store script» heuristic.

## Decision

1. **Honest result now.** When the Blocks payment step shows no methods and the attempt blocked third-party scripts, the worker marks the payment step **skipped** with the assertion `payment_methods_not_verified` (`gateway_scripts_not_loaded`). The path up to the payment form counts as confirmed; the cabinet and the owner panel show the reason next to the skipped step. Without blocked third-party scripts a missing method list stays `selector_changed`.
2. **Next: a full payment-form check as a check setting** («проверять способы оплаты полностью»): the service itself allows the known origins of payment providers (Stripe, PayPal and the wallets their scripts load), still blocks every payment, capture and refund call by path, and narrows the «store script» heuristic to the store origin. Needs its own design: synthetic traffic to providers' fraud signals, PayPal's client-token rate limit for checks from one IP.

## Consequences

- Stores with Stripe/PayPal on the Blocks checkout get passing checks with a visible limitation instead of false incidents.
- Classic checkout is unaffected (methods are rendered by the server).
