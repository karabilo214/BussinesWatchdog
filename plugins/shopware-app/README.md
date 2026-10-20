# Shopware App sources

`src/` is a link to `apps/backend/resources/shopware-app`: the backend builds the archive, so the files ship with the
backend (only `apps/backend` is in its containers) and need no separate release step.

The service builds a separate app archive for every store (`apps/backend/app/Support/Providers/Shopware/ShopwareApp.php`):
`manifest.xml` is generated there (store-specific URLs and secret); the files under `src/` are added to the archive as
they are.

- `src/Resources/app/storefront/dist/storefront/js/business-watchdog/business-watchdog.js` — checkout observation
  script (ADR 0057/0062): stage reports only to the shop's own endpoint, no storage on the device, no form values, no
  visitor identifiers.
- `src/Resources/entities.xml` — the app's own entities: reports and the observe setting (ADR 0062).
- `src/Resources/scripts/storefront-bw-observe/observe.twig` — the endpoint `/storefront/script/bw-observe` in the
  shop; stores one report per request while observing. The service collects and deletes them through the Admin API.

The storefront script detects the checkout page by URL and sends optional same-origin beacons. The archive has no
checkout Twig override or page-loaded app script; a broken observation endpoint cannot stop page rendering. A page
with JavaScript disabled or blocked is no longer counted as rendered by this app.

Tests: `tests/` (end to end against real Shopware in Docker).
