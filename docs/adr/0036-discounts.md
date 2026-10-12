# ADR 0036: Discounts managed in the owner panel

Status: implemented (Step 105); the precedence rule (point 4) is shown to the owner

## Context

Owner 2026-10-12: discounts are part of the fully configurable commercial setup in the owner panel. Paddle discounts (checked in the sandbox): percentage (decimal string) or flat amount in minor units with currency, `recur` + `maximum_recurring_intervals` (null = forever), `usage_limit`, `expires_at`, archiving by status; a server-created transaction accepts `discount_id` and the discount moves to the subscription for its duration.

## Decisions

1. **Kinds and terms**: percentage (stored in basis points, 0.01–100 %) or flat amount with currency; duration one payment, 1–1000 billing periods or forever; optional usage limit, start and end; plans (none = all plans).
2. **Audience**: promo code (entered by the customer, 3–32 characters, case-insensitive), chosen organisations (applied without a code; found by name in the panel) or an offer for everyone buying the chosen plans.
3. **Applied only by our server**: discounts are created in Paddle with `enabled_for_checkout: false`, so Paddle's checkout window does not accept codes; our checkout checks the discount (active, started, not expired, plan, flat-amount currency equals the price currency, confirmed uses below the limit) and passes `discount_id` with the transaction it creates. Paddle's own `usage_limit` and `expires_at` are set as a second guard. The browser's code or amounts are never trusted.
4. **Precedence** (one discount per purchase, Paddle accepts one): an entered valid code wins; otherwise a discount assigned to the organisation; otherwise the public offer that saves the most on the base price (percentages rounded half up to a minor unit). A code that does not apply is an error, not a silent fallback.
5. **Customer view**: `/api/v1/billing/discounts/check` (owner, 10 per minute) tells whether a code applies to a price; the catalog shows the organisation's own or the best public offer per plan; the subscription page shows the discount, the crossed-out total and the discounted first payment (BigInt, half up) and says Paddle shows the final total with taxes. Checkout sessions store `discount_id`; uses are counted from confirmed sessions.
6. **Changes**: creating, changing (name, customer text, plans, organisations, start, end, usage limit) and archiving need a reason and an authenticator code and are audited; the amount, audience and duration of an existing discount never change — create a new one. Archiving stops new purchases; subscriptions that have the discount keep it until it ends.
7. Verified in the Paddle sandbox: promo code created in the panel (20 %, 2 periods, Start only, 5 uses), bought with Paddle's test card: transaction total 22.40 € instead of 28.00 €, `times_used` 1, the subscription carries the discount for two monthly periods.
