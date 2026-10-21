# ADR 0077: Connections without installing anything come first; WooCommerce webhooks without a plugin

Date: 2026-10-22

Status: accepted (owner's decision 2026-10-22: the API connection is the default on both platforms, and WooCommerce's own webhooks make it instant without a plugin)

## Context

A store can be connected with a read-only key only — WooCommerce REST API (ADR 0024) or a Shopware integration (ADR 0025/0026) — without putting any of our code into the shop. This covers reconciliation with Stripe and PayPal, payment attempts from orders and the external browser checks. The plugin and the app add instant updates and the observation of real shoppers' checkout (ADR 0057/0059/0062). For shops in the DACH region, getting third-party code approved (agency, IT security, data protection officer) is often the main hurdle; a read-only key is much easier to approve.

## Decisions

1. **The key is the default on both platforms.** The cabinet already recommended the REST API key for WooCommerce; for Shopware the integration key is now preselected and marked «recommended», the app is described as optional: instant updates and observation of real shoppers' checkout.
2. **WooCommerce webhooks without a plugin.** WooCommerce can send order webhooks by itself (Settings → Advanced → Webhooks). For a REST API connection the cabinet creates a secret and shows the delivery URL (`/api/v1/webhooks/woocommerce/{integration}`), the secret and the topics `order.created`, `order.updated`, `order.deleted`, `order.restored`; the merchant creates one webhook per topic. Our key stays read-only, so the service cannot create them itself.
3. **A delivery is only a signal.** It is accepted when signed (`X-WC-Webhook-Signature`, base64 HMAC-SHA256 of the raw body) and sent from the store's host (`X-WC-Webhook-Source`); it then queues the connection's ordinary delta sync, which is unique per connection, so a burst of deliveries collapses into one read. The body is never trusted; polling every 5 minutes stays the guarantee, so a disabled webhook loses nothing. WooCommerce's unsigned ping is answered 200 and proves nothing.
4. **A new secret replaces the old one** (shown once); the cabinet shows when the last delivery arrived.
5. **Checkout observation of real shoppers stays with the plugin and the app.** A script added without them (e.g. through a tag manager) would have to report to our domain, which is outside the legal design of ADR 0062.

## Consequences

- Without the plugin, WooCommerce changes arrive within seconds once the merchant adds the webhooks; Shopware without the app still polls every 5 minutes.
- WooCommerce disables a webhook after repeated failed deliveries; the service answers deliveries quickly (it only queues) and polling covers any gap.
