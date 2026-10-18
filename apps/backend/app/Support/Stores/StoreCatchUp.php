<?php

namespace App\Support\Stores;

use App\Jobs\SyncIntegration;
use App\Models\Integration;
use App\Support\Integrations\IntegrationSyncRunner;
use Illuminate\Support\Carbon;

/**
 * After a capacity pause (ADR 0037 point 7) nothing of the paused period was kept: the store's connections re-read
 * it. Integrations the service polls get an audit sync at once; the WooCommerce plugin is asked through its next
 * heartbeat to send again every order changed since the pause began. The resume time also gives freshness a fresh
 * start, so the paused period is not read as a connector that stopped reporting.
 */
class StoreCatchUp
{
    public const HEALTH_RESUMED_AT = 'capacity_resumed_at';

    public const HEALTH_PAUSED_FROM = 'capacity_paused_from';

    public function __construct(private readonly IntegrationSyncRunner $runner) {}

    public function resumed(string $tenantId, string $storeId, Carbon $pausedFrom): void
    {
        $now = Carbon::now();

        Integration::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])
            ->get()
            ->each(function (Integration $integration) use ($now, $pausedFrom): void {
                $integration->forceFill(['health' => array_merge($integration->health ?? [], [
                    self::HEALTH_RESUMED_AT => $now->toJSON(),
                    self::HEALTH_PAUSED_FROM => $pausedFrom->toJSON(),
                ])])->save();

                if ($this->runner->eligible($integration)) {
                    SyncIntegration::dispatch($integration->id, IntegrationSyncRunner::MODE_AUDIT)->afterCommit();
                }
            });
    }

    public static function resumedAt(Integration $integration): ?Carbon
    {
        return self::time($integration, self::HEALTH_RESUMED_AT);
    }

    public static function pausedFrom(Integration $integration): ?Carbon
    {
        return self::time($integration, self::HEALTH_PAUSED_FROM);
    }

    private static function time(Integration $integration, string $key): ?Carbon
    {
        $at = $integration->health[$key] ?? null;

        return is_string($at) ? Carbon::parse($at) : null;
    }
}
