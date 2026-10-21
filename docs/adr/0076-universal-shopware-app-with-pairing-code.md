# ADR 0076: One Shopware App archive for everyone, bound to a store by a code and its domain

Date: 2026-10-21

Status: accepted (owner's decision 2026-10-21: the app is universal; the store is confirmed by a code from the cabinet plus the domain the app connects from; the Shopware Store comes later)

## Context

Since ADR 0027 the cabinet builds an app archive for one pending integration: its manifest carries a registration URL with that integration's id and its own secret, so the installation binds itself to the store without any code. The owner wants one archive for every customer — the same file, later also in the Shopware Store — and a binding the merchant does by hand: download the app in the cabinet, install it, take a code from the cabinet, paste it into the app; the app's shop and the code together confirm the store.

Shopware's private-app handshake needs a secret in the manifest. In a universal archive that secret is the same for everyone and effectively public: it proves only that a request comes from an installation of our app, not from a particular customer.

## Decisions

1. **Universal archive.** One `BusinessWatchdog.zip` per version: registration `…/api/v1/shopware/app/register`, webhooks `…/api/v1/shopware/app/webhook`, the app secret derived from the service's `APP_KEY` (HMAC, so it never reveals the key and needs no extra setting). The cabinet downloads the current approved version (`GET /api/v1/downloads/shopware-app`); the release gate of ADR 0074 is unchanged.
2. **Installations before binding.** A registration creates or renews a row in `shopware_app_installations` keyed by Shopware's shop id: shop URL, app version, the shop secret and, after confirmation, the app's API key — encrypted. It belongs to no customer yet, so it reads nothing and receives nothing but lifecycle events. Unbound installations are removed after 7 days.
3. **Where the code goes.** The app has a main module («Open app» in Shopware's Extensions): our page in an iframe, which Shopware opens with a query signed with the shop secret. The merchant pastes the cabinet's code there. Reading the code from the app's own configuration would need `system_config:read`, i.e. every setting of the shop including mail passwords — not acceptable for a read-only app.
4. **Binding = code + domain.** The code is the store's pairing code from the cabinet (`POST /stores/{id}/pairing-codes`, the same 15-minute code as the WooCommerce plugin, attempts limited). The form is accepted only with a valid shop signature (≤ 1 hour old), an unconsumed and unexpired code of a Shopware store, the host of the registered shop URL equal to the store's address host, and a successful Admin API login and order read at that URL with the app's key — so the shop that registered really runs at that domain. Then the integration is created exactly as before (read-only app connection, order source, store verified by API key), the code is consumed and the binding audited.
5. **The page** shows nothing about other customers: before binding only «enter the code», after binding the store name and «connected». It may be framed only by the shop's own origin (`frame-ancestors`), posts without cookies (the signature authenticates, no CSRF session) and tells the Shopware Administration it has loaded (`sw-app-loaded`).
6. **Existing per-store archives keep working** through the per-integration routes of ADR 0027/0061 until those stores reinstall the app; the cabinet no longer hands out per-store archives.
7. **Shopware Store later.** The same registration/binding works for a Store app; only the secret then comes from the Shopware account.

## Consequences

- Anyone can install the archive and register a shop; without a valid code for a store on the same domain nothing is read or bound. Registration and the form are rate limited.
- A shop on a different domain than the cabinet's store (e.g. a staging host) cannot be bound to it; the customer adds that host as its own store.
- Moving from a per-store archive to the universal one means uninstalling the old app and installing the new one (the registration URL changes).
