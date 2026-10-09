# Compatibility

Only combinations that were actually executed are listed. "Latest" is never a compatibility statement; every row is a pinned version.

## WooCommerce plugin (`plugins/woocommerce-watchdog`)

Declared floor (owner decision 2026-10-09, ADR 0005): PHP 7.4+, WordPress 5.9+, WooCommerce 6.0+.

Verified on 2026-10-09 with `plugins/woocommerce-watchdog/tests/matrix/run.sh` (integration tests inside real WordPress) and `e2e.sh` (pairing → signed heartbeat → store-verification challenge served publicly → credential rotation against the Laravel backend):

| Target | WordPress (image) | PHP | WooCommerce | Order storage | Checkout page | Scheduler | Integration | E2E |
|---|---|---|---|---|---|---|---|---|
| floor | 5.9.3 (`wordpress:5.9.3-php7.4-apache`) | 7.4 | 6.0.2 | legacy | classic | Action Scheduler | pass | pass |
| wc7-legacy | 6.2.2 (`wordpress:6.2.2-php8.0-apache`) | 8.0 | 7.9.2 | legacy | classic | Action Scheduler | pass | pass |
| wc7-hpos | 6.2.2 (`wordpress:6.2.2-php8.0-apache`) | 8.0 | 7.9.2 | HPOS | classic | Action Scheduler | pass | pass |
| wc8 | 6.5.5 (`wordpress:6.5.5-php8.1-apache`) | 8.1 | 8.9.5 | HPOS | blocks | Action Scheduler | pass | pass |
| wc9 | 6.8.3 (`wordpress:6.8.3-php8.3-apache`) | 8.3 | 9.9.7 | HPOS | blocks | Action Scheduler | pass | pass |
| latest | 7.1.3 (`wordpress:7.1.3-php8.3-apache`) | 8.3 | 11.2.0 | HPOS | blocks | Action Scheduler | pass | pass |

Database for all targets: `mariadb:10.6.21`. WP-CLI 2.11.0.

Scope of this verification (Step 45): activation, schema install/re-install, environment detection, scheduling, secret storage, REST permissions, public challenge endpoint, pairing, signed heartbeat, rotation. Order/refund snapshot behaviour is not yet implemented and therefore not yet verified.

Not verified: WooCommerce Stripe gateway versions, themes, multisite, PHP 7.4 with WooCommerce ≥ 7 (official WordPress images for newer WP versions no longer ship PHP 7.4), WP-Cron-only path (Action Scheduler is bundled in every WooCommerce ≥ 6.0 tested).

## Backend

- PHP 8.4 (Homebrew `php@8.4` locally), Laravel 13, PostgreSQL 18 (local Docker), SQLite in-memory for the fast suite.
