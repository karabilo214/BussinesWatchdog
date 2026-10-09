# ADR 0006: Payment Provider Is Optional; Key Entered In The Dashboard

Date: 2026-10-09

Status: accepted (owner decision); implementation planned, not started.

## Decision

- Stores can use Business Watchdog without Stripe. The WooCommerce plugin is identical with or without Stripe.
- The Stripe restricted read-only key is entered in the Business Watchdog dashboard, never in the WordPress plugin (secret hygiene, and independence of financial evidence from the store).
- Without an active `independent_provider` integration, provider-dependent reconciliation rules return `unknown` / `provider_not_connected` and create no incidents or notifications.
- Provider-authority events are accepted only from provider credentials, not from plugin (`store_reported`) credentials.
- Connecting a provider queues the store's orders in the reconciliation window for re-evaluation.

Recorded in `spec/Business-Watchdog-TZ.md` §10.1.

## Planned work

1. Reconciliation gating + authority check on ingest (small step).
2. Stripe read-only connector (P1): dashboard key form, restricted-key check, keyring storage, charges/refunds/PaymentIntent sync, webhooks, store ↔ account link.
