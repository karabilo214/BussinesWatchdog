# ADR 0059: Checkout observation script in the WooCommerce plugin (layer 2)

Status: accepted for now (owner's task 2026-10-14: layer 2 in the WooCommerce plugin following the Shopware app, ADR 0057). Details below were chosen by the implementer and are to be confirmed by the owner.

## Context

Spec §17.1 layer 2, ADR 0007 («layer 2 not started»). The Shopware app does it without code in the shop: the browser reports straight to the service (ADR 0057). The WooCommerce plugin runs inside WordPress, so the spec's design is possible: the script reports to the plugin's own endpoint, the plugin counts the checkout pages it renders itself and sends aggregates through its outbox, like layer 1.

## Decisions

1. **Same limits as ADR 0057 and no consent banner** (owner decision for Shopware 2026-10-14, applied to WooCommerce by the owner's task): the script counts only checkout stages, stores nothing on the device (no cookies, local or session storage), reads no form values, sets no visitor identifiers and never links pages of one visitor; the plugin keeps only counters per 5-minute window, no IP addresses or user agents.
2. **Pages**: the checkout page (Classic or Blocks, not order-pay) and the order-received page. The plugin counts each page it actually renders (`wp_footer`, so redirects such as an empty cart are not counted) and prints a `noscript` beacon (`GET /wp-json/business-watchdog/v1/observe/noscript`) — «shown but the script is silent» is told apart without a browser request to the service.
3. **Script** (`assets/checkout-observer.js`, no dependencies, in the page head so it registers its error handlers before most theme scripts): `script_loaded`, `form_ready` (a payment method offered and the place-order button enabled, Classic or Blocks), `submit_clicked` (Classic: the checkout form is sent; Blocks: the place-order button is pressed and no field validation error is shown after 1.5 s), `returned_with_error` (a checkout error notice appears after sending), `submit_stalled` (no navigation and no error notice 60 s after sending — longer than for Shopware because WooCommerce gateways often open payment windows on the page), `finish_reached`, and the number of JavaScript errors. Reports go with `sendBeacon` to the plugin's `POST /wp-json/business-watchdog/v1/observe` with a short-lived token signed with a key derived from the connection secret (page and expiry only), so outsiders cannot inflate the counters.
4. **Optimisers**: the script tag carries `data-no-optimize`, `data-no-minify`, `data-no-defer`, `data-cfasync="false"` and the plugin adds it to the exclusion lists of WP Rocket, Autoptimize, LiteSpeed Cache and SiteGround Optimizer. If the script finds itself combined, deferred or async, it says so (`script_altered`), and the plugin diagnostics show the time.
5. **The service decides**: the heartbeat answer carries `checkout_observation.observe`; the plugin enqueues the script and counts pages only while it is true. True while the plugin is the store's active order source, the store is active and the plan has `payment_attempts_script`. Unlike Shopware, a browser check is not required: the WooCommerce check always reaches the payment form, so whenever there is a check it can confirm the script; without one the script still works.
6. **Synthetic traffic**: on a page rendered for a verified browser check (signed marker, ADR 0012) the plugin does not count the page and tells the script it is synthetic: the script sets its marker for the check and sends nothing.
7. **Reporting**: closed windows (one minute after their end) are sent as `checkout.observation` (aggregate `checkout`, id `observation:<window start>`, revision); a late report for a sent window raises its revision and sends it again; the service replaces the window's counters only with a newer revision. The same `checkout_observation_windows`, rules (`CHECKOUT_SCRIPT_SILENT`, `CHECKOUT_SUBMIT_FAILING` — orders are the plugin's layer 1 payment attempts) and coverage as ADR 0057. Stores connected only with the REST API key get coverage «needs the plugin».

## Not decided / for the owner

- 60 s for a stalled submit (Shopware 20 s).
- Observation without a configured browser check (WooCommerce only).
