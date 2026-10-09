# ADR 0008: Features Are Packaged By Subscription Plan

Date: 2026-10-09

Status: accepted (owner decision); recorded in the spec, subscriptions not implemented yet.

## Decision

- The order-outcome contour of addendum 1.2 goes into P1, available only on specific plans.
- Plans differ by features, not only by limits (example lineup Free / €19 / €59 — not approved prices). Each sellable feature has a stable feature code in plan entitlements (`spec/Business-Watchdog-TZ.md` §29.1).
- Entitlements are enforced on the backend everywhere (scheduling, detectors, obligations, ingestion of add-on data, API, notifications, exports); the frontend only displays them.
- One WooCommerce plugin build for all plans; it receives enabled features from the SaaS and does not collect data for disabled ones.
- Downgrade: history kept, observation stops, open incidents become `not_entitled` (never "fixed"); upgrade: observation starts from activation, earlier periods are not declared violations.

## Consequences for current work

New backend features are built behind a feature code and an entitlement check seam from the start; until billing exists, P0 pilot entitlements are granted manually. The mapping of features to plans and the prices remain open owner decisions.
