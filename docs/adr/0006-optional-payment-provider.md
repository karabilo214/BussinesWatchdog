# ADR 0006: Payment Provider Is Optional; Key Entered In The Dashboard

Date: 2026-10-09

Status: accepted (owner decision); item 1 implemented in Step 48, item 2 planned.

## Decision

- Stores can use Business Watchdog without Stripe. The WooCommerce plugin is identical with or without Stripe.
- The Stripe restricted read-only key is entered in the Business Watchdog dashboard, never in the WordPress plugin (secret hygiene, and independence of financial evidence from the store).
- Without an active `independent_provider` integration, provider-dependent reconciliation rules return `unknown` / `provider_not_connected` and create no incidents or notifications.
- Provider-authority events are accepted only from provider credentials, not from plugin (`store_reported`) credentials.
- Connecting a provider queues the store's orders in the reconciliation window for re-evaluation.

Recorded in `spec/Business-Watchdog-TZ.md` §10.1.

## Planned work

1. Reconciliation gating + authority check on ingest — done in Step 48 (`docs/implementation-step-48-checklist.md`). "Connected" means an `independent_provider` integration with status `active`; degraded/revoked do not count. Gated: order rules (`MONEY_UNSUPPORTED` / `unknown` / `provider_not_connected`), the unmatched-payment scan, allocations (only `independent_provider` transactions). Revoking a provider requeues the store's 90-day window; incidents already open stay open (never auto-resolved by `unknown`).
2. Stripe read-only connector (P1): backend implemented in Step 65 (ADR 0020) — restricted-key check, keyring storage, PaymentIntent/charge/refund sync, webhooks, exact-reference matching; dashboard form in Step 66; compatibility spike with a real Stripe sandbox still required.
