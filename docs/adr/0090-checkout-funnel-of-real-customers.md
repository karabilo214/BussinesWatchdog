# ADR 0090: Checkout statistics of real customers — aggregates only, plans, loss estimate, warm-up

Date: 2026-10-29, revised 2026-10-30. Status: accepted (owner, 2026-10-29; data decision revised to aggregates only, 2026-10-30). Backlog: task 12, section A. Builds on ADR 0007 (payment attempts, layers 1 and 2).

## Context

The synthetic check walks one path as one robot and cannot see what depends on the visitor (browser, device, language, time zone — the first real Shopware store failed only for browsers reporting the time zone `Europe/Kyiv`). The owner wants the product to show where and how much a merchant loses on the checkout. ADR 0007 already counts payment attempts per method (layer 1) and observes checkout stages with a script (layer 2), but there are no segmented aggregate statistics and no comparison with the store's own norm. Exact per-visitor funnels are intentionally excluded by the revised decision.

## Decision

1. **Aggregates only — no per-visitor data (owner, 2026-10-30; replaces the session identifier decided on 2026-10-29).** The WooCommerce plugin / Shopware App count on the store server and send per 5-minute window only counters per checkout step (e.g. added to cart, reached checkout, payment attempts, orders, store 5xx on cart/checkout requests). No session id or hash, no IP, no individual events, no names, e-mail, addresses, order numbers or form values. This extends ADR 0007 layer 1 (per-window payment attempt counters). Our promise stays: «we receive no data about your customers — only anonymous statistics per 5-minute window»; no data processing agreement (AVV) and no change to our legal documents are needed. Losing the exact path of one visitor (steps 1–2–3 then left) is accepted.
2. **Segments as numbers only, small groups suppressed.** Counters may be split by coarse attributes — browser family, OS family, device type, browser language, browser time zone, country if the store already knows it — but only as counts per window; a segment with fewer than 5 visitors in a window is merged into «other». Nothing identifies a person alone or in combination.
3. **Plans.** Not part of Start (needs noticeable resources); Growth and higher. Volumes and retention per plan are set during implementation; plan and site texts change only when the feature is released.
4. **Loss estimate**, shown only as an estimate with a range and never mixed with reconciliation amounts:

   `loss ≈ (p₀ − p₁) × N × average order value`

   - N — count of the affected segment that reached the failing step during the incident (sum of window counters);
   - p₀ — the store's usual ratio of orders to that step's count in the segment (norm, same weekday and hour band);
   - p₁ — the same ratio during the incident;
   - average order value — median of the store's paid orders over 28 days (minor units, separately by currency/mode; no per-visitor cart values).

   These are ratios of aggregate counters, not individual session conversion probabilities. Without step counters, a sales-loss estimate uses the shortfall of orders against their expected count, multiplied by the median paid order value. Unknown history/coverage is not zero.

   Shown only when N is large enough and p₀ > p₁ is statistically significant; only the excess over the normal abandonment counts.
5. **Staged warm-up instead of one threshold (owner, 2026-10-30; replaces «7 days»).** A single week holds one sample per weekday, so a sale, a holiday or an outage on that day would distort the norm.

   | History | Norm | Alerts |
   |---|---|---|
   | < 7 days | — | none, the cabinet shows «collecting the norm, N days left» |
   | 7–13 days | — | only «sales stopped»: hours of zero orders where the store clearly sells more than zero |
   | 14–27 days | 2–3 samples of the same weekday plus the store's overall daily profile | also «sales fell by half or more», only when it lasts several hours |
   | ≥ 28 days | 4 samples of the same weekday and hour band | full sensitivity: smaller drops, segments, checkout steps |

   Rules: small numbers are not compared per hour — the window widens (2 h, half a day, a day) until enough orders are expected, and the test is statistical (counts against the expected rate), not «−50%»; days with an incident, a sale or a public holiday stay out of the norm (German public holidays count as Sundays; the merchant can mark «we had a promotion» in the cabinet); the norm is the rolling last 28 days. Exact thresholds are tuned on real data of the test stores and the first client before release. Hard signals that need no norm (5xx share in the store's cart, ADR 0007 layer 1 streaks) are not delayed.

## Consequences

- New plugin and App versions (WordPress.org SVN only on the owner's command; App version approval for the cabinet).
- No device storage, no per-visitor data: merchants' cookie consent and our legal documents stay as they are.
- Section B starts with sales counts against the store norm using existing orders/payment projections, followed by the audit of layers 1 and 2. No individual checkout event schema is added.
