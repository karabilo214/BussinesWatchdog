# ADR 0061: Updating the Shopware app of connected stores

Status: accepted for now (owner's backlog item 2026-10-14: an update archive for stores that already run the app). Details below were chosen by the implementer and are to be confirmed by the owner.

## Context

The dashboard builds an app archive per store with the store's own registration URL and manifest secret (ADR 0027) and only for a new connection: `package()` refuses while the app is the order source. A store running an older app (1.0.0 without the checkout observation script of ADR 0057) had no way to get a newer one, and the service did not know which version a shop runs.

## Decisions

1. **Update archive for the same integration**: `POST /api/v1/stores/{store}/integrations/shopware-app/update` (owner/admin, like the first download) returns the current app version built for the store's connected app integration — the same integration, registration URL and manifest secret — so Shopware treats it as an update of the installed app («Upload extension» → «Update»), and nothing new is created on the service side. Only for an app integration that is active, degraded or still pending; otherwise `404 shopware_app_not_connected`. Audited (`stage: update_package_created`, version).
2. **Installed version**: every signed app webhook carries `source.appVersion`; the service keeps it (`health.app_version`, `connector_version`). The manifest now also subscribes to `app.updated`, so an update is seen at once. Shopware does not register the app again on an update (seen on 6.6 and 6.7: the shop secret and the API key stay); should it ever do so, an active integration may re-register and the new secret replaces the old one.
3. **Dashboard**: the connected app shows the installed version («unknown» until the first webhook) and, when it is older than the current one or unknown, «Download app update» with the steps: upload in Shopware (Extensions → My extensions → Upload extension), confirm the update, clear the cache.
4. App version 1.2.0 (manifest: `app.updated` webhook).

## Not decided / for the owner

- No automatic notice to customers about a new app version (only the dashboard hint).

## Verification

`tests/Feature/Providers/ShopwareAppTest.php`; `plugins/shopware-app/tests/app/update-e2e.sh` on Shopware 6.6.10.6 and 6.7.2.2: app 1.0.0 installed and connected, the update archive accepted by Shopware as an update to 1.2.0, the same integration still active, the new version known at once (`app.updated`), the storefront script and both templates present, orders still arriving through webhooks, no new registration — PASS.
