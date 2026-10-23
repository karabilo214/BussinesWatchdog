<?php

namespace App\Support\Privacy;

use App\Models\Store;
use App\Support\Billing\TrialStoreHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Delete one store's data in the same foreign-key order as an organization purge. */
class StoreDeletion
{
    /** Children before parents. Missing tables are skipped for older deployed schemas. */
    private const TABLES = [
        'support_access_grants',
        'check_steps', 'artifacts', 'check_attempts', 'check_runs', 'check_scenarios',
        'incident_signals', 'incident_activity', 'suppressions', 'incidents', 'signals',
        'reconciliation_subject_states', 'reconciliation_findings', 'reconciliation_runs', 'reconciliation_dirty_subjects',
        'refund_allocations', 'payment_allocations', 'refunds', 'financial_transactions', 'payments', 'order_revisions', 'orders',
        'payment_attempt_windows', 'observed_payment_attempts', 'checkout_observation_windows', 'checkout_observation_collected_reports',
        'provider_object_states', 'event_inbox', 'integration_credentials', 'integrations', 'pairing_codes', 'store_verifications',
        'store_order_volumes', 'exports', 'audit_log',
    ];

    public function delete(Store $store): void
    {
        $this->purgeData($store);

        // Keep only the keyed anti-abuse marker, as organization deletion does.
        DB::table('trial_store_hosts')->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)
            ->where('host', 'not like', TrialStoreHosts::HASH_PREFIX.'%')
            ->get(['host'])
            ->each(fn ($row) => DB::table('trial_store_hosts')->where('host', $row->host)->update(['host' => TrialStoreHosts::hashed($row->host)]));

        DB::table('stores')->where('tenant_id', $store->tenant_id)->where('id', $store->id)->delete();
        DB::table('store_deletion_journal')->insertOrIgnore(['store_id' => $store->id, 'tenant_id' => $store->tenant_id, 'deleted_at' => now()]);
    }

    /** Purge every store-scoped record while retaining the store and trial-use marker for a platform correction. */
    public function purgeData(Store $store): void
    {
        $uncovered = array_values(array_filter(
            Schema::getTableListing(null, false),
            fn (string $table): bool => ! in_array($table, [...self::TABLES, 'trial_store_hosts', 'store_deletion_journal'], true) && Schema::hasColumn($table, 'store_id'),
        ));

        if ($uncovered !== []) {
            throw new RuntimeException('store_deletion_table_uncovered:'.implode(',', $uncovered));
        }

        $scope = fn (string $table) => DB::table($table)->where('tenant_id', $store->tenant_id)->where('store_id', $store->id);

        foreach (['artifacts' => 'watchdog.artifacts.disk', 'exports' => 'watchdog.exports.disk'] as $table => $diskConfig) {
            $disk = Storage::disk((string) config($diskConfig));
            $scope($table)->whereNotNull('object_key')->orderBy('id')->select(['id', 'object_key'])
                ->chunkById(500, function ($rows) use ($disk): void {
                    foreach ($rows as $row) {
                        if (! $disk->delete($row->object_key)) {
                            throw new RuntimeException('store_object_delete_failed');
                        }
                    }
                });
        }

        DB::table('notification_deliveries')
            ->where('tenant_id', $store->tenant_id)
            ->whereIn('incident_id', $scope('incidents')->select('id'))
            ->delete();

        DB::table('domain_outbox')->where('tenant_id', $store->tenant_id)
            ->where(function ($query) use ($store, $scope): void {
                $query->where('payload->store_id', $store->id)
                    ->orWhereIn('payload->incident_id', $scope('incidents')->selectRaw('CAST(id AS TEXT)'));
            })->delete();

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'store_id')) {
                $scope($table)->delete();
            }
        }

    }
}
