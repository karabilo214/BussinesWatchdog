# ADR 0011: Browser Worker (Node + Playwright)

Date: 2026-10-09

Status: implemented in Step 52. The network-level egress proxy is still required before production.

## Decisions

- **Runtime.** Node ESM without a build step, `playwright` 1.55.0 pinned, image `mcr.microsoft.com/playwright:v1.55.0-noble` (Chromium 140), runs as `pwuser` with the Chromium sandbox **on**, read-only root filesystem, `/tmp` tmpfs, all capabilities dropped, `no-new-privileges`, memory/CPU/PID limits in Compose. Verified that the sandbox starts under these restrictions.
- **Protocol.** Only the internal lease API; heartbeat every 15 s; the attempt stops 5 s before the absolute deadline; the result is retried until the lease ends; 4xx answers (fenced out, conflict) are not retried. The lease token is never logged.
- **Adapter** `woocommerce-payment-form@1`: one adapter recognises Classic and Blocks at runtime (product form, cart rows, `form.checkout` vs. the Checkout block, payment method list). Step outcomes:
  - `site_failure` — 5xx on a store page, error notice, empty cart after adding, checkout form missing on a WooCommerce page, no payment methods;
  - `product_unavailable` — 404/410 product, out of stock, disabled button;
  - `waf_challenge` — 403/429/503 with challenge markers (no CAPTCHA bypass);
  - `blocked_external_origin` — the main page navigated outside the allowed origins;
  - `adapter_unsupported` — not a recognisable WooCommerce page, or a failure right after the worker itself blocked an unknown mutation (so our own blocking can never be reported as a store failure);
  - `selector_changed` — a WooCommerce page where the expected element was not found (inconclusive, retried);
  - `infra_timeout` — navigation timeout or worker exception.
- **Network policy** (in the browser context, every request incl. subresources, redirects and WebSockets; service workers blocked): HTTPS on the allowed origins only, no non-standard ports. Reads allowed; mutations allowed only for `wc-ajax` cart actions, Store API `cart/*` and cart-only `batch`, `PUT` on Store API checkout (draft update), and the add-to-cart form (urlencoded or multipart). Blocked as payment operations: Classic checkout submit (by endpoint or checkout nonce in the body), Store API checkout `POST`, order-pay, gateway `capture`/`confirm`/`charges`/`refunds`. Any other mutation is blocked as unknown. A blocked payment operation ends the attempt `blocked/forbidden_mutation`.
- **Data.** Results carry origin + pseudonymised path (no query or fragment), method, status, duration, party (first/gateway/third) and error classes; no bodies, headers, cookies, form values or page text. The backend rejects anything else (ADR 0010).
- **Local test mode** `BW_WORKER_INSECURE_LOCAL=1` allows http and private hosts for the WooCommerce matrix; refused with `NODE_ENV=production`.

## Open items

- **Egress proxy (MUST before production, spec §19).** The in-browser policy does not stop DNS rebinding or traffic outside the browser; the worker needs a network-level egress proxy that resolves and checks destination IPs at connect time and has no route to internal networks, DB or Redis.
- Screenshots/artifacts, the signed synthetic marker and Blocks draft cleanup (Step 53).
- Variable products, mandatory shipping before the payment step, and gateways that render only after address entry are reported as unsupported/inconclusive rather than guessed.
