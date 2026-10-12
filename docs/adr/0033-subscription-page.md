# ADR 0033: Subscription page in the customer dashboard

Status: implemented (Step 102)

## Context

spec/panels/CLIENT-PANELS.md §5 (`/app/billing`) and PUBLIC-SUBSCRIPTION-FRONTEND.md §3, §5–§6: plan, payment state and access state shown separately, limits and usage, buy through the provider's hosted checkout, "confirming payment" until the backend has verified state, never access from the browser's return. Backend from ADR 0029–0032.

## Decisions

1. **Route** `/app/settings/billing`, a "Subscription" tab next to Profile and Team. Every member sees the plan, access state, limits and usage; only the owner sees the subscription details (the endpoint is owner-only) and the trial/purchase buttons; others read "the organisation owner manages the subscription".
2. **Catalog** `GET /api/v1/billing/plans` (any member): the latest sellable version of each plan with its sellable prices for the current Paddle mode — the same rules as the checkout (`BillingCatalog`, shared with `PaddleCheckout` and the trial). Drafts appear only in the sandbox and are marked "Draft". Plans with a price first, cheapest first.
3. **Money in the browser**: minor-unit strings; the total for base + extra stores is computed with BigInt only for display. The server ignores it: the checkout takes only the price id and the number of extra stores. Tax note: gross for EU consumers, without VAT for businesses with a VAT id, final total shown by Paddle (ADR 0030 p. 6).
4. **Purchase**: the page asks the server for a checkout (one Idempotency-Key per selection, reused on a retry of the same selection), opens Paddle.js (`cdn.paddle.com/paddle/v2/paddle.js`, loaded only when buying) with the server's client token and transaction id. The buyer enters email, country and card in Paddle's window; we pass no personal data to it.
5. **Confirmation**: after Paddle reports `checkout.completed` the page shows "confirming the payment with Paddle" and polls the checkout; it shows "confirmed" only when the checkout is confirmed **and** the subscription read from our backend is a paid provider subscription. Found in the live run: the transaction event confirms the checkout a moment before the subscription event is projected, so checkout status alone is not enough.
6. **Not in this step**: plan change, cancellation and resume, payment method portal, invoices, `capacity_choice_required` on downgrade (backend adapter actions do not exist yet); the page says plan change and cancellation come later.
