# ADR 0066: Limits of the launch plans for one production server

Status: accepted by the owner on 2026-10-19 (limits, extra-store prices and the volume grace period)

## Context

ADR 0041 set the launch price grid by store order volume (Start 19 €, Business 39 €, Growth 79 €, Enterprise individual). The plans are created by the owner in `/owner/plans` (ADR 0035) and, with Paddle in live mode, are created in Paddle on publishing. The owner asked for limits that the single production server (Contabo, 8 vCPU, 24 GB; Step 187) can carry with care.

The server's bottleneck is the browser checks: one scheduled check takes about 30–60 s (a public storefront check on the server took 28 s, Step 192; scheduled checks go further, to the payment step), and a worker runs checks one after another — about 60–80 checks an hour. The server can hold three workers (2 vCPU, 2 GB each) next to the other services; one runs today (`BW_BROWSER_WORKERS=1`). Reconciliation, ingestion and mail are light in comparison.

## Decision

| | Start | Business | Growth | Enterprise |
|---|---|---|---|---|
| Price per month | 19 € | 39 € | 79 € | individual («Цена индивидуально») |
| `max_orders_per_month` (per store) | 1 000 | 5 000 | 20 000 | by agreement |
| `max_active_stores` | 1 | 2 | 3 | |
| `max_extra_stores` | 2 | 3 | 5 | |
| `min_check_interval_minutes` | 60 | 30 | 15 | |
| `manual_runs_per_day` (per store) | 5 | 10 | 20 | |
| `monthly_event_budget` (per store) | 5 000 | 25 000 | 100 000 | |
| `retention_days` | 90 | 180 | 365 | |
| Trial, days | 14 | 14 | 14 | |
| `checkout_monitoring`, `payment_attempts_server`, `money_reconciliation`, `notifications_telegram` | ✓ | ✓ | ✓ | |
| `payment_attempts_script` | — | ✓ | ✓ | |

- **Not sold yet**: `order_outcomes` and `agency_workspace` are in the feature catalog but not implemented (TZ §43); the «частые проверки» add-on stays off until a second worker or server — it creates the most browser load.
- **Extra store** (accepted): 9 € (Start), 8 € (Business), 7 € (Growth) a month — an extra store costs the server as much as an included one.
- **Volume grace period** (ADR 0041 p. 3, accepted): 14 days.
- **Billing period**: monthly prices only at launch; yearly prices are the owner's later choice.

## Capacity

Worst case, every store checked at its plan's shortest interval with one scenario: a Start store makes 1 check an hour, Business 2, Growth 4. Three workers at half load (≈100 checks an hour) carry, for example, 40 Start + 15 Business + 5 Growth customers with all extra stores, or about 100 single Start stores; one worker about a third of that. Manual runs add little. When the check queue grows (owner panel → «Сервис»), raise `BW_BROWSER_WORKERS` in `.env` and deploy; beyond three workers a second server is needed.
