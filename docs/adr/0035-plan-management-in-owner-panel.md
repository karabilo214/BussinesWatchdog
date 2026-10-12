# ADR 0035: Plans, prices and billing periods managed in the owner panel

Status: implemented (Step 104); discounts follow in the next step

## Context

Owner 2026-10-12: everything sold is fully configurable in the owner panel — new packages, limits, prices and billing periods of any length ("one day or any period"), discounts. spec/panels/OWNER-PANEL.md §5: versioned plans with an immutable code, localized texts, draft/published/retired, entitlements, prices per currency and interval, trial days; a published version is immutable, a change is a new version, current customers are not moved, publishing needs step-up.

## Decisions

1. **Periods**: a price is billed every `interval_count` (1–365) `interval` (day, week, month, year) — Paddle's billing cycle `{interval, frequency}` supports exactly this. A period without a repeating step ("until 17 March") is not possible with a recurring subscription. Migration `2026_10_12_130000_add_billing_interval_count`; the unique price key includes the period, so one version can sell several periods (e.g. every month and every year).
2. **Trial**: 0–365 days per version (was 0–90); still our own trial without a card (ADR 0030 p. 7).
3. **Editing**: `PlanAdmin` — create a plan (immutable code, version 1 draft), edit the draft (names and descriptions ru/en/de, trial, features from the catalog, limits with minimums: stores ≥ 1, checks at most every 5 minutes, history ≥ 1 day; empty = unlimited), add/remove draft prices (supported Paddle currencies, exponent from ISO 4217, amount entered as text and converted to minor units with string operations only), new version copying the latest one. New kinds of features need code; their inclusion and every limit value are configuration.
4. **Publishing**: needs names in all three languages and a base price for the current payment mode, a reason and a current authenticator code (step-up). The Paddle product (one per plan, shared by its versions — previously one per version) and the prices are created first, outside the database transaction; if Paddle fails the version stays a draft (`provider_unavailable`). Then the version and its prices are published and the previously published version is retired for new buyers. Subscribers keep their version, prices and entitlement snapshot; the list shows subscribers per version.
5. **Retiring** (reason + code): the version stops selling, subscribers stay; the plan is retired when nothing of it is on sale. The Paddle price stays active at Paddle (checkouts are created only by our server from published prices, so it cannot be bought); archiving it at Paddle is a later improvement.
6. **What customers see**: only published versions and published prices of the current mode, in every environment (the Paddle sandbox no longer sells drafts). `billing:seed-placeholder-plans --test-prices` publishes the placeholder plans with test prices in the sandbox only.
7. **Rights**: `plans.read` (owner, admin, billing) to view, `plans.manage` (owner) to change; every change is in `platform_audit_log` (denied step-ups and Paddle failures too).
