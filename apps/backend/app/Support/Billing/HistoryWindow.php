<?php

namespace App\Support\Billing;

use Illuminate\Support\Carbon;

/**
 * How far back an organisation sees its history (ADR 0041, owner's decision 2026-10-13): the package's
 * `retention_days`; older history is hidden in the cabinet and exports and deleted `PURGE_AFTER_DAYS` later, so moving
 * to a package with a longer retention within that time brings it back. No limit (or pilot mode) shows everything.
 */
class HistoryWindow
{
    public const PURGE_AFTER_DAYS = 30;

    public function __construct(private readonly EntitlementCalculator $entitlements) {}

    public function cutoff(string $tenantId, ?Carbon $now = null): ?Carbon
    {
        $now ??= Carbon::now();
        $days = $this->entitlements->forTenant($tenantId, $now)->limit(FeatureCatalog::RETENTION_DAYS);

        return $days === null ? null : $now->copy()->subDays($days);
    }

    public function purgeBefore(string $tenantId, ?Carbon $now = null): ?Carbon
    {
        return $this->cutoff($tenantId, $now)?->subDays(self::PURGE_AFTER_DAYS);
    }
}
