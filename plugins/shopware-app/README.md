# Shopware App sources

`src/` is a link to `apps/backend/resources/shopware-app`: the backend builds the archive, so the files ship with the
backend (only `apps/backend` is in its containers) and need no separate release step.

The service builds a separate app archive for every store (`apps/backend/app/Support/Providers/Shopware/ShopwareApp.php`):
`manifest.xml` is generated there (store-specific URLs and secret); the files under `src/` are added to the archive as
they are, with `__BW_OBSERVE_ENDPOINT__` replaced by the store's observation endpoint.

- `src/Resources/app/storefront/dist/storefront/js/business-watchdog/business-watchdog.js` — checkout observation
  script (ADR 0057): stage counters only, no storage on the device, no form values, no visitor identifiers.
- `src/Resources/views/storefront/page/checkout/{confirm,finish}/index.html.twig` — marker for the script and a
  render beacon (counts shown pages even when JavaScript is broken or off).

Tests: `tests/` (end to end against real Shopware in Docker).
