# ADR 0007: Monitoring Real Payment Attempts

Date: 2026-10-09

Status: accepted (owner decision); recorded in the spec, implementation not started.

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
