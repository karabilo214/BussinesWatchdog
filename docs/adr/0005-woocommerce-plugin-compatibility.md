# ADR 0005: WooCommerce Plugin Compatibility Strategy

Date: 2026-10-09

Status: accepted.

## Context

Stores run very different WooCommerce generations: legacy post-based order storage vs HPOS (7.1+ opt-in, default for new installs since 8.2), classic shortcode checkout vs Checkout block (default for new installs since 8.3), changing hook names, optional Action Scheduler APIs. The spec asks for PHP 8.2+ (SHOULD) and to fix the real lower bound only after a compatibility spike.

## Decision

1. **Floor**: PHP 7.4, WordPress 5.9, WooCommerce 6.0 (owner decision). This deviates from the spec's "PHP 8.2+ SHOULD"; the plugin code is written in PHP 7.4 syntax (no enums, readonly, match, named arguments, constructor promotion, nullsafe operator, `str_contains`) and is linted with `php:7.4-cli`.
2. **Capabilities, not version numbers**: behaviour is selected by detecting classes/functions (`OrderUtil::custom_orders_table_usage_is_enabled`, `FeaturesUtil`, `as_*` functions, `has_block`), never by comparing WooCommerce versions, except for the single floor check.
3. **Woo CRUD only**: orders/refunds are read through `wc_get_order`/`wc_get_orders` and object getters for both storages; no SQL against `wp_posts`/`wc_orders`.
4. **Adapters** for differences: scheduler (Action Scheduler, WP-Cron fallback), order storage, checkout mode; hook adapters for deletion/refunds/blocks are added with the snapshot step.
5. **HPOS and Blocks compatibility are declared** (`FeaturesUtil::declare_compatibility`) because the matrix covers both storages and both checkout modes for the foundation; each later feature must keep the matrix green before release.
6. **Verification matrix** in Docker with pinned images (`plugins/woocommerce-watchdog/tests/matrix`): six targets from the floor to the latest release, integration tests inside real WordPress via WP-CLI, plus an e2e against the Laravel backend. `docs/compatibility.md` lists only executed combinations.

## Consequences

- New WooCommerce majors are added to `targets.env` and must pass before "WC tested up to" is raised.
- PHP 7.4 syntax limits apply to the plugin only, not to the backend.
- The floor can be lowered or raised later only through a new matrix run and an ADR update.
