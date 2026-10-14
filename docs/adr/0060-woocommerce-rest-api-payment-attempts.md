# ADR 0060: Real payment attempts of WooCommerce stores connected with a REST API key

Status: accepted for now (owner's task 2026-10-14: layer 1 for the WooCommerce REST API connection). Details below were chosen by the implementer and are to be confirmed by the owner.

## Context

Payment attempt observation (ADR 0007 layer 1) lives in the WooCommerce plugin: it hooks checkout submissions and outcomes inside WordPress. Stores connected only with a read REST API key (ADR 0024, the main path) had no observation at all. The service already reads every changed order through `/wc/v3/orders` (delta, recent and audit runs); Shopware attempts are derived the same way (ADR 0056).

## Decisions

1. **Attempt = a checkout order** created after the key was connected: `created_via` is `checkout` (Classic) or `store-api` (Blocks); orders made in the admin, through the REST API or by other plugins, checkout drafts, trashed orders and orders without a payment method (free orders) are not attempts. `created_via` is added to the order fields read (the store's personal data is still not requested). The method is the order's `payment_method` (the same gateway id the plugin reports).
2. **Outcome** from the order status:
   - a paid date, or `processing` / `completed` / `refunded` → **paid** (at the paid date, otherwise the change time);
   - `on-hold` → **on hold** (bank transfer, cheque — success, as in the plugin);
   - `failed` → **failed** (`status_failed`); `cancelled` → **failed** (`cancelled`, e.g. an unpaid order cancelled when the stock hold ends);
   - `pending` → pending → **stuck** after 30 minutes (`no_result`) → **late success** if paid later;
   - any other (custom) status without a paid date is not counted.
3. **One attempt per order**: a REST key cannot see a customer retrying the payment of the same order (the plugin can); the latest status counts.
4. **Windows and rules**: the same service-side attempts as Shopware (`observed_payment_attempts`, ADR 0056) — 5-minute windows by the time the outcome became known, recomputed from the attempts, rule `CHECKOUT_PAYMENTS_FAILING` (3 in a row per method), coverage and incidents as for the plugin. `rejected_before_order` stays 0 (only the plugin or the checkout script sees refusals before an order).
5. **Delay**: within the 5-minute delta of the REST sync (no webhooks).

## Not decided / for the owner

- Counting only `checkout` / `store-api` orders (a checkout of a plugin that uses another `created_via` is not counted).
- A cancelled unpaid order counts as a failure, like in the plugin.
