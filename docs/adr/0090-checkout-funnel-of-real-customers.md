# ADR 0090: Checkout funnel of real customers — data, plans, loss estimate, warm-up

Date: 2026-10-29. Status: accepted (owner, 2026-10-29). Backlog: task 12, section A. Builds on ADR 0007 (payment attempts, layers 1 and 2).

## Context

The synthetic check walks one path as one robot and cannot see what depends on the visitor (browser, device, language, time zone — the first real Shopware store failed only for browsers reporting the time zone `Europe/Kyiv`). The owner wants the product to show where and how much a merchant loses on the checkout. ADR 0007 already counts payment attempts per method (layer 1) and observes checkout stages with a script (layer 2), but there is no funnel per visitor session, no segments and no comparison with the store's own norm.

## Decision

1. **Only a session identifier, no new storage on the visitor's device.** The WooCommerce plugin / Shopware App derive it on the store server from the store's existing session: `HMAC-SHA256(per-store secret, store session id)`, truncated. No new cookie, no localStorage/sessionStorage, no fingerprinting. We never receive the store session id itself, names, e-mail, addresses, IP addresses, order numbers or form values.
2. **Coarse segment attributes only** (owner confirmed): browser family, OS family (no versions), device type (phone / tablet / desktop), browser language, browser time zone (IANA name as reported, to catch names the store does not know), country if the store already knows it from the shipping step. Nothing that identifies a person alone or in combination at this granularity.
3. **Plans.** Not part of Start (needs noticeable resources); Growth and higher. Volumes and retention per plan are set during implementation; plan and site texts change only when the feature is released.
4. **Loss estimate**, shown only as an estimate with a range and never mixed with reconciliation amounts:

   `loss ≈ (p₀ − p₁) × N × average order value`

   - N — sessions of the affected segment that reached the failing step during the incident;
   - p₀ — the store's usual share of such sessions that completed an order (norm, same weekday and hour band);
   - p₁ — the actual share during the incident;
   - average order value — median of the store's paid orders over 28 days (minor units, store currency), or the carts of the abandoning sessions when the plugin reports them.

   Shown only when N is large enough and p₀ > p₁ is statistically significant; only the excess over the normal abandonment counts.
5. **Warm-up: no norm-based incidents before 7 days of observation** of the store (owner: otherwise false alarms). Until then the cabinet shows «collecting the norm, N days left» and the funnel without alerts. Between 7 and 28 days the norm uses the history available, with wider confidence bounds; from 28 days the full window. Hard signals that need no norm (e.g. a share of sessions getting 5xx from the store's cart, ADR 0007 layer 1 streaks) are not delayed.

## Consequences

- New plugin and App versions (WordPress.org SVN only on the owner's command; App version approval for the cabinet).
- Whether merchants need an extra line in their cookie consent is a question for the owner's lawyer; the design avoids device storage on purpose.
- Section B of task 12 starts with an audit of what layers 1 and 2 already send and store.
