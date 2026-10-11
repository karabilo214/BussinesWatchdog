# ADR 0027: Shopware App connection

Status: implemented (Step 93); follows the owner's decision "Integration first, App next" (ADR 0025)

## Context

A Shopware Integration key must be created and copied by hand, its permissions cannot be verified and changes are only polled. A Shopware App declares its permissions in a manifest that the merchant approves at install, receives its own API key through a signed handshake and can subscribe to webhooks.

## Decisions

- **One archive per store.** The dashboard hands out `BusinessWatchdog.zip` made for one store (`POST /stores/{id}/integrations/shopware-app`, only after the state-change risk is acknowledged — the app's role has the same Shopware limitation as an Integration, ADR 0025). Its manifest holds a registration URL for this pending integration and its own secret; a new archive replaces a still pending one. This fits self-hosted Shopware (upload under Extensions → My extensions). Distribution through the Shopware Store / Shopware Cloud needs the generic store handshake and is not done.
- **Permissions**: read only — order, order_transaction, order_transaction_capture, order_transaction_capture_refund, currency, payment_method, state_machine, state_machine_state. No customer entities.
- **Handshake** (Shopware's private-app protocol): registration `GET …/register` signed with the manifest secret (HMAC of the query) → proof, a new shop secret, confirmation URL; confirmation `POST …/confirm` with the app's API key signed with the shop secret. Shopware confirms before the app is activated and an inactive app's key cannot log in, so the key is stored and checked when the app is active (immediately or on `app.activated`). Only then does the app become the store's order source; the store is confirmed (method `api_key`) when the shop URL's host is the store's host. The Admin API is then called at the shop URL Shopware reported.
- **Webhooks** (signed with the shop secret, shop id checked): `checkout.order.placed`, `order.written` and `state_enter.order_transaction.state.*` / `state_enter.order.state.*` read the named order right away. Shopware sends entity-written webhooks for orders but **not for order transactions**, so payment state changes come only as `state_enter` business events. `app.deactivated` → degraded, `app.activated` → active again, `app.deleted` → revoked and credentials revoked. The body is only a hint; the 5-minute delta and the daily audit stay the guarantee.

## Verified

`plugins/shopware-app/tests/app/app-e2e.sh` on real Shopware 6.6.10.6 and 6.7.2.2: the archive from the service uploaded, installed and activated through Shopware's extension API (as the owner does); the app has exactly the eight `:read` privileges; registration and confirmation make it the order source and confirm the store; an order and its payment arrive through webhooks with no polling run; uninstalling revokes the integration — PASS on both. Backend tests cover the handshake signatures, waiting for activation, webhook signature and shop checks, lifecycle events and the one-order-source rule.

## Latest releases (Step 94)

PASS on self-built 6.7.15.1. On 6.6.10.25–6.6.10.29 Shopware itself cannot install apps that register with a server (empty `<argument/>` for `shopware.app_system.trusted_url_resolver`, see ADR 0025); the dashboard keeps the integration key as the alternative, and the app steps say to use it when the installation fails on those versions.
