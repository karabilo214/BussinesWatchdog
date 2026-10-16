# ADR 0062: Shopware checkout observation through the shop

Date: 2026-10-16

Status: accepted (owner's decision 2026-10-16: the Shopware script must talk to the app in the shop, as the WooCommerce plugin does, and not to the service directly). Replaces the transport of ADR 0057 points 1, 3 and 4; the stages, rules and limits of ADR 0057 stay.

## Context

ADR 0057 sent the stages of the Shopware app's checkout script and a 1×1 render image straight from the shopper's browser to the service (`/api/v1/observe/{integration}/…`). A Shopware app has no PHP code in the shop, so that was the simple way. The WooCommerce plugin (ADR 0059) reports to its own WordPress endpoint and sends aggregates through its outbox, so the shopper's browser never contacts the service. The direct path transmitted the shopper's IP address and browser details to the service, was subject to strict `connect-src` CSP and to blockers, and needed a separate legal answer for Shopware.

Spike on Shopware 6.6.10.6 and 6.7.2.2 (`plugins/shopware-app/tests/spike/observation_spike.py`, app `observation-app/BwSpike`): an app can declare its own entities (`Resources/entities.xml`) and receives create/read/update/delete on them automatically, without permissions in the manifest; an App Script in `Resources/scripts/storefront-<hook>/` is served on the shop's own domain at `/storefront/script/<hook>` (GET and POST, form fields in `hook.request`, query in `hook.query`) and may write with `services.writer`; `response.cache.disable()` keeps GET out of the HTTP cache; 40 concurrent POSTs stored 40 rows (one row per report, so there is no lost update of a counter); the Admin API with the app's integration reads `/api/search/custom-entity-bw-checkout-report` and deletes through `/api/_action/sync`. Page-loaded hooks (`checkout-confirm-page-loaded`, `checkout-finish-page-loaded`) can read but not write.

## Decisions

1. **The browser talks only to the shop.** The app (version 1.3.0) declares two entities: `custom_entity_bw_checkout_report` (page, stages, script error count — nothing about the visitor) and `custom_entity_bw_checkout_setting` (observe yes/no). Its storefront endpoint `/storefront/script/bw-observe` stores one report per request only while the setting says observe; it accepts only the known pages and stages, at most 10 stages, script errors capped at 20, and disables caching.
2. **Shown pages** are counted by a 1×1 image on the shop's own endpoint (`stages=rendered`, `rendered_noscript` in `noscript` on the confirm page, a per-render cache buster), so «pages shown but the script is silent» still works when JavaScript is broken. The image and the script's reports are rendered only while observing; the page-loaded scripts pass the setting to the templates.
3. **The script** reads the setting from its marker (no request to decide), sends stages with `sendBeacon` as form fields to the shop endpoint, and keeps the ADR 0057 limits: nothing stored on the device, no form values, no identifiers, no linking of pages.
4. **The service collects.** With every scheduled Shopware delta sync (every 5 minutes) of an app connection the service writes the setting when the decision changed or once an hour, reads up to 10 pages of 100 reports oldest first, adds them to the store's 5-minute windows by their `createdAt`, and deletes them in the shop. A report's id is remembered (`checkout_observation_collected_reports`, 7 days) before the delete, so a failed delete is only repeated and never counted twice. Reports of a store that is not observed are deleted without counting. A failure is kept in `health.observation` and never fails the order sync.
5. **Write boundary.** The Admin API client writes only the app's own entities: upsert of the setting and delete of reports, checked before any request (`ShopwareClient::syncOwnEntities`). It never writes the shop's orders, customers or other data; the read-only boundary for merchant data stays as in ADR 0025–0027.
6. **The direct path is removed**: `/api/v1/observe/{integration}/config`, `/r.gif` and `/stages`, their rate limiter and the origin check. No store ran the earlier app versions in production.
7. **Browser check**: the lease names `quiet_paths: ["/storefront/script/bw-observe"]`; the worker drops requests to that path on the store's origin without reporting them, so the check is never counted as a shopper. The script marker is present whether or not the store is observed, so the check can confirm the script before the setting reaches the shop.

## Consequences

- Reports reach the service up to 5 minutes later than before; the rules look at 2 and 6 hours, so this does not change their results.
- The shop keeps the reports until the next collection; if the service is unreachable they wait in the shop.
- Anyone can post to the shop's endpoint while it observes; this only adds reports for that shop, and the service caps every window. There is no signed token as in WooCommerce because App Scripts have no secret to sign with.
- The privacy text for Shopware stores no longer needs the paragraph about the browser connecting to the service.

## Verification

`tests/Feature/Checkout/CheckoutObservationTest.php` (collection, repeated delete, not observed, write boundary, archive contents); browser-worker unit tests; `plugins/shopware-app/tests/observation/observation-e2e.sh` on Shopware 6.6.10.6 and 6.7.2.2 with real shoppers' browsers, which also checks that the browsers contacted only the shop and that collected reports are deleted in the shop.
