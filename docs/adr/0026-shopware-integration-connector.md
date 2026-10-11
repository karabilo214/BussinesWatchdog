# ADR 0026: Shopware integration connector and state-based refunds

Status: accepted (owner, 2026-10-11) for the refund model; implementation Step 85

## Context

ADR 0025: Shopware 6.6/6.7 stores connect first through an Integration with a read-only role; the key cannot be limited to reading (state transitions) and read-only cannot be verified, so the owner acknowledges a warning. Payment plugins keep the provider ids on the order transaction (SwagPayPal: `swag_paypal_resource_id` = capture id, `swag_paypal_is_sandbox`), but a refund leaves only the transaction state in Shopware — no amount, no id.

## Refund model for stores that report refunds only as a state (owner decision 2026-10-11)

The store reports `refund_state` on the order: `none`, `partial` (`refunded_partially`) or `full` (`refunded`). It is compared with the succeeded refunds the payment provider made on the payments linked to the order (sum P, captured total C):

- `full`: the whole payment was returned — P = C is ok; P < C is a missing refund, P > C an extra refund (both after the refund grace).
- `partial`: the store does not report the amount. P > 0 and P < C → `unknown` (`refund_amount_not_reported`) **with the provider's refunded amount shown**; P = 0 → missing refund after the grace; P ≥ C → mismatch (the store says partial, the provider returned everything).
- `none` with P > 0 → extra refund (money returned, the store does not know) after the grace.

Stores that report refunds with amounts (WooCommerce plugin and REST API) keep the existing amount-based rules.

## Connector (Step 85)

- Provider `shopware` (store-reported). Connecting: access key + secret of the Integration, the store's own HTTPS origin (Admin API under `/api`), and `state_change_risk_acknowledged = true`. Checks: OAuth `client_credentials` works and the role can search orders. A working key confirms the store (method `api_key`, as for WooCommerce). One order source per store (plugin, WooCommerce key or Shopware key).
- Client allowlist: `POST /api/oauth/token`, `POST /api/search/order`, `POST /api/search-ids/order`; always with `includes` (no customer data); SSRF-safe like the WooCommerce client; token cached for its lifetime.
- Mapping to `order.snapshot`: order state, ISO currency and exponent (amounts are JSON floats; a value not exactly representable in the currency's minor units is skipped), payment method `technicalName` as gateway (`swag_paypal_*` → PayPal provider coverage), capture id from `swag_paypal_resource_id`, mode from `swag_paypal_is_sandbox`, `paid_marked_at` from the transaction's last change when it is paid/refunded (Shopware keeps no paid timestamp on the order — an approximation), `refund_state` from the transaction state. The effective transaction is the latest one that is not cancelled/failed.
- Sync: delta every 5 minutes over `updatedAt ≥ T OR createdAt ≥ T OR transactions.updatedAt ≥ T`; audit daily over 90 days with deletion check by `search-ids`. Freshness by last successful sync; a rejected key degrades the integration and stops polling.
- Stores get a `platform` (`woocommerce` or `shopware`) when created, so the dashboard offers the right order source.

## Verified (Step 85)

`plugins/shopware-app/tests/chain/chain-e2e.sh` on real Shopware 6.6.10.6 and 6.7.2.2 (dockware): a read-only Integration created as an owner would; connecting without the acknowledgement is refused; the key confirms the store; orders mapped (state, totals, gateway, refund_state) without personal data; an unchanged second delta emits nothing; the admin's payment → paid, partial refund and order → in progress are seen by the next delta (through `transactions.updatedAt`); the deleted order is found by the audit — PASS on both versions. The state-based refund rules are covered by backend tests with PayPal provider data; a real SwagPayPal payment is still open (ADR 0025).
