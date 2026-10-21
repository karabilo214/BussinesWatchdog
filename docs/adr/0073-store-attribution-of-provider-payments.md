# ADR 0073: Which store a provider payment belongs to

Status: accepted by the owner on 2026-10-21, implemented in Step 211.

## Context

Woo-Test and Shopware-Test share one PayPal sandbox account (and a store owner may connect one PayPal or Stripe account to several shops). The service reads the whole account, so each store saw the other store's payments, and after 24 hours they would become «payments without order». The live PayPal orders show that the payment apps leave the store's marker at the provider:

- SwagPayPal (Shopware): `custom_id` = `{"orderTransactionId": "<Shopware order transaction id>", "pluginVersion", "shopwareVersion"}`;
- PayPal Payments for WooCommerce: `custom_id` = the order id, `invoice_id` = the store's invoice prefix + the order number (`ecdbaf-29`);
- WooCommerce Stripe Gateway: metadata `site_url` and `order_id` (already read, ADR 0020).

## Decision

1. The PayPal adapter keeps `provider_store_transaction_ref` (SwagPayPal) and `provider_invoice_ref` on the payment; the Shopware order snapshot carries `store_transaction_ref` (the effective transaction id).
2. **Matching:** a SwagPayPal payment is linked to the order with the same Shopware transaction id (strategy `verified_metadata`), also before the store has written the capture id.
3. **Attribution** (`StorePaymentAttribution`, kept in `payments.foreign_store_reason`, refreshed on every unmatched scan and list): an unlinked payment belongs to another store when it carries a Shopware transaction id and the store is not Shopware or (once the store reports transaction ids) has no order with it (`other_store_transaction`); a Stripe site origin other than the store's (`other_site`); a PayPal Payments invoice prefix in a Shopware store, or one that differs from the prefixes of this store's linked payments (`other_invoice_prefix`).
4. Such payments are not «payments without order», raise no findings or incidents, and are listed in a collapsed section «Payments of other stores on this account» (`?scope=other_stores`). Payments without any marker stay this store's to review.

## Consequences

- A store sharing an account sees only its own and unmarked payments as discrepancies. Unmarked payments (manual invoices, payment links) still appear in every store of the account.
- A WooCommerce store learns its invoice prefix from its first linked PayPal payment; until then other WooCommerce stores' PayPal payments stay visible.
- Existing payments get the new markers when PayPal is read again (the daily audit pass); Shopware orders get the transaction id on the next change or `shopware:sync --audit`.
