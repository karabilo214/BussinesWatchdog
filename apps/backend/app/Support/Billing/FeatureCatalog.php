<?php

namespace App\Support\Billing;

use InvalidArgumentException;

/**
 * The sold functions and limits (TZ §29.1). Codes are stable: they live in published plan versions and subscription
 * snapshots. A limit of null means "no limit".
 */
final class FeatureCatalog
{
    public const CHECKOUT_MONITORING = 'checkout_monitoring';

    public const PAYMENT_ATTEMPTS_SERVER = 'payment_attempts_server';

    public const PAYMENT_ATTEMPTS_SCRIPT = 'payment_attempts_script';

    public const MONEY_RECONCILIATION = 'money_reconciliation';

    public const ORDER_OUTCOMES = 'order_outcomes';

    public const NOTIFICATIONS_TELEGRAM = 'notifications_telegram';

    public const AGENCY_WORKSPACE = 'agency_workspace';

    public const FEATURES = [
        self::CHECKOUT_MONITORING,
        self::PAYMENT_ATTEMPTS_SERVER,
        self::PAYMENT_ATTEMPTS_SCRIPT,
        self::MONEY_RECONCILIATION,
        self::ORDER_OUTCOMES,
        self::NOTIFICATIONS_TELEGRAM,
        self::AGENCY_WORKSPACE,
    ];

    public const MAX_ACTIVE_STORES = 'max_active_stores';

    public const MIN_CHECK_INTERVAL_MINUTES = 'min_check_interval_minutes';

    public const RETENTION_DAYS = 'retention_days';

    public const MONTHLY_EVENT_BUDGET = 'monthly_event_budget';

    public const MANUAL_RUNS_PER_DAY = 'manual_runs_per_day';

    /** New real orders a month one store may have, measured as the average of the last three complete months (ADR 0041). */
    public const MAX_ORDERS_PER_MONTH = 'max_orders_per_month';

    /** Most stores an extra-store quantity may add on top of `max_active_stores` (null: no cap). */
    public const MAX_EXTRA_STORES = 'max_extra_stores';

    /** Limits that grow with the number of stores: the plan value is per store (ADR 0029). */
    public const PER_STORE_LIMITS = [self::MONTHLY_EVENT_BUDGET, self::MANUAL_RUNS_PER_DAY];

    public const LIMITS = [
        self::MAX_ACTIVE_STORES,
        self::MIN_CHECK_INTERVAL_MINUTES,
        self::RETENTION_DAYS,
        self::MONTHLY_EVENT_BUDGET,
        self::MANUAL_RUNS_PER_DAY,
        self::MAX_EXTRA_STORES,
        self::MAX_ORDERS_PER_MONTH,
    ];

    /**
     * Normalizes and validates an entitlement document: {features: [codes], limits: {code: int|null}}.
     *
     * @param  array<string, mixed>  $entitlements
     * @return array{features: list<string>, limits: array<string, ?int>}
     */
    public static function normalize(array $entitlements): array
    {
        $features = $entitlements['features'] ?? [];
        $limits = $entitlements['limits'] ?? [];

        if (! is_array($features) || ! is_array($limits) || array_diff(array_keys($entitlements), ['features', 'limits']) !== []) {
            throw new InvalidArgumentException('entitlements_invalid');
        }

        foreach ($features as $feature) {
            if (! in_array($feature, self::FEATURES, true)) {
                throw new InvalidArgumentException('entitlement_feature_unknown');
            }
        }

        $normalizedLimits = [];

        foreach ($limits as $key => $value) {
            if (! in_array($key, self::LIMITS, true) || ! ($value === null || (is_int($value) && $value >= 0))) {
                throw new InvalidArgumentException('entitlement_limit_invalid');
            }

            $normalizedLimits[$key] = $value;
        }

        $features = array_values(array_unique($features));
        sort($features);
        ksort($normalizedLimits);

        return ['features' => $features, 'limits' => $normalizedLimits];
    }

    /**
     * @return array{features: list<string>, limits: array<string, ?int>}
     */
    public static function everything(): array
    {
        return ['features' => self::FEATURES, 'limits' => array_fill_keys(self::LIMITS, null)];
    }
}
