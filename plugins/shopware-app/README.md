# Shopware App sources

`src/` is a link to `apps/backend/resources/shopware-app`: the backend builds the archive, so the files ship with the
backend (only `apps/backend` is in its containers) and need no separate release step.

The service builds a separate app archive for every store (`apps/backend/app/Support/Providers/Shopware/ShopwareApp.php`):
`manifest.xml` is generated there (store-specific URLs and secret); the files under `src/` are added to the archive as
they are.

- `src/Resources/app/storefront/dist/storefront/js/business-watchdog/business-watchdog.js` — checkout observation
  script (ADR 0057/0062): stage reports only to the shop's own endpoint, no storage on the device, no form values, no
  visitor identifiers.
- `src/Resources/views/storefront/page/checkout/{confirm,finish}/index.html.twig` — marker for the script and a
  render image on the shop's endpoint (counts shown pages even when JavaScript is broken or off).
- `src/Resources/entities.xml` — the app's own entities: reports and the observe setting (ADR 0062).
- `src/Resources/scripts/storefront-bw-observe/observe.twig` — the endpoint `/storefront/script/bw-observe` in the
  shop; stores one report per request while observing. The service collects and deletes them through the Admin API.
- `src/Resources/scripts/checkout-{confirm,finish}-page-loaded/observe.twig` — pass the observe setting to the
  templates.

Tests: `tests/` (end to end against real Shopware in Docker).
