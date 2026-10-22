# Shopware App sources

`src/` is a link to `apps/backend/resources/shopware-app`: the backend builds the archive, so the files ship with the
backend (only `apps/backend` is in its containers) and need no separate release step.

The service builds one universal app archive for all stores (version 1.5.0, ADR 0076) in
`apps/backend/app/Support/Providers/Shopware/ShopwareApp.php`. The generated `manifest.xml` uses shared service URLs;
the files under `src/` are added to the archive. After installation, the merchant opens the app in Shopware and enters
a pairing code from the customer dashboard. The service checks the code, storefront domain and App API access before
binding the installation to a store. Older store-specific archives continue to work until replaced.

- `src/Resources/app/storefront/dist/storefront/js/business-watchdog/business-watchdog.js` — checkout observation
  script (ADR 0057/0062): stage reports only to the shop's own endpoint, no storage on the device, no form values, no
  visitor identifiers.
- `src/Resources/entities.xml` — the app's own entities: reports and the observe setting (ADR 0062).
- `src/Resources/scripts/storefront-bw-observe/observe.twig` — the endpoint `/storefront/script/bw-observe` in the
  shop; stores one report per request while observing. The service collects and deletes them through the Admin API.

The storefront script uses configured checkout paths and CSS selectors (ADR 0075), falling back to standard Shopware
paths. It fetches the current configuration from the shop endpoint and sends optional same-origin reports. The archive has no
checkout Twig override or page-loaded app script; a broken observation endpoint cannot stop page rendering. A page
with JavaScript disabled or blocked is no longer counted as rendered by this app.

Tests: `tests/` (end to end against real Shopware in Docker).
