# ADR 0007: Monitoring Real Payment Attempts

Date: 2026-10-09

Status: accepted (owner decision); layer 1 implemented in Step 49, layer 2 not started.

## Context

Synthetic checks stop before "Place order" (no real purchases in P0/P1) and reconciliation only covers created orders. The gap between a customer pressing "Pay" and a confirmed payment was only covered indirectly by P2 sales anomalies.

## Decision

Recorded in `spec/Business-Watchdog-TZ.md` §17.1 and the release table in §2:

- Layer 1 (P0): server-side attempt/outcome aggregates in the plugin (paid / failed / pending_stuck / rejected_before_order) per payment method, signal "payments are not going through".
- Layer 2 (P1): a small dependency-free checkout script observing click → request → response stages.
- The script may be prevented from running by other broken JS, optimisers, CSP, blockers or consent; silence is never "healthy". The server counts rendered checkout pages and compares them with script signals; a gap is itself a signal, strengthened by layer 1. The script loads early as its own file, captures other scripts' errors, is excluded from optimiser bundling, and has a `noscript` beacon. Synthetic checks verify the script is present.
- Client promise: "we watch real payment attempts and alert when customers stop paying successfully", not a guarantee for every purchase.
- No PII, no form values, no card data.

## Open items for implementation

Event type name and schema, rule thresholds (defaults: N=3 consecutive failures per method, 30 min pending window, 5 min aggregation), incident family `checkout_payment`, optimiser exclusion list.

## Layer 1 implementation decisions (Step 49, to be confirmed by the owner)

The open items above were settled as follows. Thresholds are constants in `PaymentAttemptMonitor` and versioned (`rule_version` v1, `config_version` 1).

- **Event.** `checkout.payment_attempts`, aggregate type `checkout`, aggregate id = window start. One event per closed 5-minute window (UTC), revision 1, with per-method counters only. No order ids, customer data or error texts leave the store; the plugin keeps order ids locally for 7 days to resolve outcomes.
- **Attempt.** A checkout submission that reaches `woocommerce_checkout_order_processed` (Classic) or `woocommerce_store_api_checkout_order_processed` / `woocommerce_blocks_checkout_order_processed` (Blocks / Store API). A repeated submission for the same order closes the previous attempt as `failed/retried`.
- **Outcomes** (windowed by the time the outcome is known):
  - `paid` — payment complete or a paid status (`processing`, `completed`; this includes cash on delivery);
  - `on_hold` — `on-hold` (bank transfer, cheque): the checkout worked, money is expected later; counts as success;
  - `failed` with a class: `gateway_error` (Classic error notice after the order was created), `payment_error` / `checkout_error` (Store API error response), `status_failed`, `cancelled`, `retried`, `order_missing`;
  - `pending_stuck` — no result 30 minutes after the attempt (class `no_result`);
  - `late_success` — a stuck attempt that was paid later; counts as success;
  - `rejected_before_order` — the checkout was rejected before an order existed (Classic error notice without an order, Store API error before the order-processed hook). Counted and stored but **not** part of the failure streak: these are mostly customer input mistakes.
- **Rule `CHECKOUT_PAYMENTS_FAILING`.** Per payment method: 3 consecutive non-successful outcomes (`failed` + `pending_stuck`) after the last success, counted across windows (each window reports `trailing_failures` after its last success, so order inside a window is exact). Opens a `checkout_payment` incident (severity `warning`), any later success resolves it with a recovery notification, a new streak within 24 h reopens it. Methods are independent.
- **Declines.** Without a provider the plugin cannot tell a bank decline from a broken integration, so declines count as failures. The notification says so explicitly. The statistical rule (success-rate drop vs. the store's own history) is not implemented yet.
- **Not covered yet.** The "pay for order" page (paying an existing pending order), gateways that never report a result in WooCommerce (only `pending_stuck` after 30 min), the dashboard coverage view, and the entitlement check for feature code `payment_attempts_server` (there is no entitlement system yet; ADR 0008).

