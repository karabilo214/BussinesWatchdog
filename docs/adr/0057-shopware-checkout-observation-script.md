# ADR 0057: Checkout observation script for Shopware stores (layer 2)

Status: accepted (owner decisions 2026-10-14: the script works without a consent banner; the service decides whether a store is observed). Details below confirmed by the owner on 2026-10-14 as they are.

## Context

Spec §17.1 layer 2: a small script on the checkout page observes «page shown → payment form ready → Submit order → result», so the service learns when real customers can no longer complete a purchase even though no order (and so no layer 1 attempt, ADR 0056) is created. Owner decision (ADR 0055): for Shopware it works only when the browser check reaches the payment page (test customer or guest), because only then can the check confirm the script is present.

Spike on Shopware 6.7.2.2 with a minimal app: a JS file shipped in the app (`Resources/app/storefront/dist/storefront/js/<app>/<app>.js`) is added to the theme by Shopware on install and loaded on every storefront page (`defer`, after `storefront.js`); Twig templates in the app override storefront blocks (after the shop's cache is cleared). An app has no code of its own in the shop, so — unlike the WooCommerce plugin's WordPress endpoint — the browser sends its data straight to the service.

## Decisions

1. **What the script does** (`apps/backend/resources/shopware-app`, linked as `plugins/shopware-app/src`; shipped inside the store's app archive, app version 1.1.0): only on the confirm and finish pages (where the app's templates place a marker). It asks the service whether the store is observed; if yes it counts stages: `script_loaded`, `form_ready` (payment methods offered, «Submit order» enabled), `returned_with_error` (the confirm page came back with an error after a submit), `submit_clicked` (the order form was sent), `submit_stalled` (the page did not leave 20 s after sending), `finish_reached`, plus the number of JavaScript errors on the page. Sent with `sendBeacon` to `/api/v1/observe/{integration}/stages`.
2. **Without a consent banner — owner decision 2026-10-14**: the script watches nothing but whether payment methods work and a customer can complete a purchase. This holds only with these limits, which must not be loosened: it stores nothing on the device (no cookies, local or session storage), reads no form values, sets no visitor identifiers and never links pages of one visitor; the service keeps only counters per store and 5-minute window, no IP addresses, no user agents.
3. **Render beacon**: the templates also show a 1×1 image from the service (`/r.gif?page=…`, a `noscript` variant on the confirm page). It counts shown pages even when JavaScript is broken, blocked or optimised away, so «pages shown but the script is silent» can be told apart (spec §17.1 «silence is never healthy»).
4. **The service decides**: a store is observed only while its order source is the Shopware **app** (the integration key cannot ship a script), the app integration is active, the browser check uses `test_customer` or `guest` (ADR 0055) and the plan includes `payment_attempts_script`. Otherwise `/config` answers `observe: false`, beacons are ignored and the script stops. Requests are accepted only from the store's own origin (`Origin`/`Referer` host = the shop URL Shopware registered or the store's address) and are rate limited per IP; counters per window are capped.
5. **Rules** (`checkout:observation-evaluate`, every 5 minutes, versioned constants; requests of the observation endpoints never use the SPA session handling, so a shop on a host the SPA also uses gets no CSRF check):
   - `CHECKOUT_SCRIPT_SILENT` (info, component `checkout_script`): in the last 6 hours at least 5 confirm pages were shown to browsers with JavaScript, but the script reported nothing. Resolved when the script reports again.
   - `CHECKOUT_SUBMIT_FAILING` (warning, component `checkout_submit`): in the last 2 hours at least 3 «Submit order» sends and, since the 5-minute window of the first of them, no payment attempt (no order transaction, ADR 0056) and no finish page — so an order placed before the failures does not hide them, while one in the same window is taken as working (no false alarm). Resolved by the next attempt or finish page.
6. **Browser check**: with test customer or guest, the payment step also records whether the script is on the confirm page (`checkout_script_present`); requests of the check to the service are dropped quietly (they would count synthetic traffic) and not reported as errors. Coverage shows the script state per store.
7. **Installing**: the dashboard instructions say to clear the Shopware cache after installing (the app's templates apply only then — seen in the spike and the e2e) and explain what the script counts. **Not done yet**: a store that already runs app 1.0.0 cannot download 1.1.0 — the dashboard builds an archive only for a new connection. No store runs the app yet (not released); an update archive for connected stores (same integration and secret) is a separate task.

## Confirmed by the owner (2026-10-14)

- Thresholds: 5 pages / 6 hours for a silent script, 3 sends / 2 hours for failing submits, 20 s for a stalled submit.
- Known limit: errors of scripts that run before the app's script (Shopware loads app scripts after `storefront.js`) are not counted.
- The same layer for WooCommerce (plugin endpoint) is a separate task.

## Verification

`tests/Feature/Checkout/CheckoutObservationTest.php`; `plugins/shopware-app/tests/observation/observation-e2e.sh` on Shopware 6.6.10.6 and 6.7.2.2 with the real app archive and real shoppers' browsers: not observed while the check stops before sign-in; with the guest way all stages counted; three submits cancelled by a broken page script open `CHECKOUT_SUBMIT_FAILING` (an earlier order does not hide them); a real order reaches the finish page and resolves it; the browser check finds the script and is neither counted nor reported — PASS.

## Legal review package (2026-10-16)

A draft for the lawyer (questions on § 25 TDDDG, legal basis, processor role and DPA, web server log retention, plus privacy policy paragraphs in German and English for stores) was prepared as the doc «Скрипт наблюдения за оформлением заказа — вопросы юристу» and handed to the owner. Open technical point found while writing it: retention of IP/User-Agent in the production web server access log for `/api/v1/observe/*` is not set yet.
