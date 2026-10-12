<?php

namespace App\Support\Billing;

use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrialStoreHosts
{
    public static function host(string $baseUrl): ?string
    {
        $host = strtolower(rtrim((string) parse_url(trim($baseUrl), PHP_URL_HOST), '.'));

        if ($host === '') {
            return null;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($ascii) && $ascii !== '' ? strtolower($ascii) : $host;
        }

        if (str_starts_with($host, 'www.') && str_contains(substr($host, 4), '.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * @param  list<string>  $hosts
     */
    public function claimedByOther(string $tenantId, array $hosts): bool
    {
        return $hosts !== [] && DB::table('trial_store_hosts')
            ->whereIn('host', $hosts)
            ->where('tenant_id', '!=', $tenantId)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    public function verifiedStoreHosts(string $tenantId): array
    {
        $hosts = [];

        Store::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('verified_at')
            ->whereNotIn('status', ['deleted', 'deleting'])
            ->orderBy('created_at')
            ->get(['id', 'base_url'])
            ->each(function (Store $store) use (&$hosts): void {
                $host = self::host($store->base_url);

                if ($host !== null && ! isset($hosts[$host])) {
                    $hosts[$host] = $store->id;
                }
            });

        return $hosts;
    }

    public function claim(string $tenantId, string $storeId, string $host, Carbon $now): void
    {
        DB::table('trial_store_hosts')->insertOrIgnore([
            'host' => $host,
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'claimed_at' => $now,
        ]);
    }

    public function blocksVerification(Store $store, Carbon $now): bool
    {
        $host = self::host($store->base_url);

        if ($host === null) {
            return false;
        }

        $onTrial = Subscription::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('provider', TrialService::PROVIDER)
            ->where('status', Subscription::STATUS_TRIAL)
            ->where('trial_ends_at', '>', $now)
            ->exists();

        return $onTrial && $this->claimedByOther($store->tenant_id, [$host]);
    }

    public function claimForVerifiedStore(Store $store, Carbon $now): void
    {
        $host = self::host($store->base_url);

        if ($host !== null && Tenant::query()->whereKey($store->tenant_id)->whereNotNull('trial_consumed_at')->exists()) {
            $this->claim($store->tenant_id, $store->id, $host, $now);
        }
    }
}
