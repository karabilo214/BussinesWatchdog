<?php

namespace App\Support\Privacy;

use App\Models\DataDeletionRequest;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\TrialStoreHosts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Removes an organization's data once its 30 days have passed (ADR 0069), in resumable stages: revoke connections,
 * delete stored files, purge the tenant tables in foreign-key order, anonymize members nobody else needs, keep the
 * billing records and the trial-domain hashes, and write the deletion journal. Every stage can run again safely.
 */
class OrganizationPurger
{
    public const CHUNK = 500;

    public const MAX_ATTEMPTS = 5;

    public const LEASE_SECONDS = 600;

    /** Children before parents; each table is emptied for the tenant before the next one starts. */
    public const PURGED_TABLES = [
        'check_steps',
        'artifacts',
        'check_attempts',
        'check_runs',
        'check_scenarios',
        'notification_deliveries',
        'notification_channel_verifications',
        'notification_channels',
        'incident_signals',
        'incident_activity',
        'suppressions',
        'incidents',
        'signals',
        'reconciliation_subject_states',
        'reconciliation_findings',
        'reconciliation_runs',
        'reconciliation_dirty_subjects',
        'refund_allocations',
        'payment_allocations',
        'refunds',
        'financial_transactions',
        'payments',
        'order_revisions',
        'orders',
        'payment_attempt_windows',
        'observed_payment_attempts',
        'checkout_observation_windows',
        'checkout_observation_collected_reports',
        'provider_object_states',
        'event_inbox',
        'integration_credentials',
        'integrations',
        'pairing_codes',
        'store_verifications',
        'store_order_volumes',
        'exports',
        'audit_log',
        'billing_discount_applications',
        'billing_discount_grants',
        'billing_discount_tenants',
        'billing_plan_changes',
        'billing_checkout_sessions',
        'billing_event_inbox',
        'entitlement_overrides',
        'subscriptions',
        'usage_entries',
        'usage_buckets',
        'tenant_reminder_messages',
        'tenant_reminders',
        'invitations',
        'idempotency_keys',
        'domain_outbox',
        'stores',
        'memberships',
    ];

    /** Tenant tables that stay after the purge (ADR 0069 p. 3): billing records, terms acceptances, hashed trial hosts. */
    public const KEPT_TABLES = [
        'billing_customers',
        'billing_subscription_contracts',
        'billing_transactions',
        'billing_refunds',
        'policy_acceptances',
        'trial_store_hosts',
        'data_deletion_requests',
        'data_deletion_journal',
        'store_deletion_journal',
    ];

    private const STAGES = [
        DataDeletionRequest::STATUS_REVOKING,
        DataDeletionRequest::STATUS_DELETING_OBJECTS,
        DataDeletionRequest::STATUS_PURGING,
    ];

    /**
     * @return array{processed: int, completed: int, failed: int}
     */
    public function processDue(int $limit = 5, ?int $chunkBudget = null): array
    {
        $totals = ['processed' => 0, 'completed' => 0, 'failed' => 0];
        $now = Carbon::now();

        $requests = DataDeletionRequest::query()
            ->whereIn('status', [DataDeletionRequest::STATUS_REQUESTED, ...self::STAGES])
            ->where('purge_after', '<=', $now)
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', $now))
            ->orderBy('purge_after')
            ->limit($limit)
            ->get();

        foreach ($requests as $request) {
            if (! $this->lease($request, $now)) {
                continue;
            }

            $totals['processed']++;
            $status = $this->run($request->fresh(), $chunkBudget ?? 400);

            if ($status === DataDeletionRequest::STATUS_COMPLETED) {
                $totals['completed']++;
            } elseif ($status === DataDeletionRequest::STATUS_FAILED) {
                $totals['failed']++;
            }
        }

        return $totals;
    }

    /**
     * Reapplies the purge to every organization of a deletion journal (after restoring an older backup).
     *
     * @param  list<array{tenant_id: string, request_id: string}>  $entries
     */
    public function replay(array $entries): int
    {
        $replayed = 0;

        foreach ($entries as $entry) {
            $tenant = Tenant::query()->whereKey($entry['tenant_id'])->first();

            $done = DataDeletionRequest::query()->whereKey($entry['request_id'])->where('status', DataDeletionRequest::STATUS_COMPLETED)->exists();

            if ($tenant === null || $done) {
                continue;
            }

            $request = DataDeletionRequest::query()->whereKey($entry['request_id'])->first();

            if ($request === null) {
                $request = new DataDeletionRequest;
                $request->forceFill([
                    'id' => $entry['request_id'],
                    'tenant_id' => $tenant->id,
                    'requested_by' => $this->anyUserId($tenant->id),
                    'requested_via' => DataDeletionRequest::VIA_PLATFORM,
                    'reason' => 'journal_replay',
                    'previous_status' => $tenant->status,
                    'status' => DataDeletionRequest::STATUS_REQUESTED,
                    'cursor' => [],
                    'attempts' => 0,
                    'requested_at' => Carbon::now(),
                    'purge_after' => Carbon::now(),
                ])->save();
            }

            $request->forceFill(['status' => DataDeletionRequest::STATUS_REQUESTED, 'cursor' => [], 'purge_after' => Carbon::now(), 'lease_until' => null])->save();
            Tenant::query()->whereKey($tenant->id)->update(['status' => 'deleting']);

            while (! in_array($this->run($request->fresh(), PHP_INT_MAX), [DataDeletionRequest::STATUS_COMPLETED, DataDeletionRequest::STATUS_FAILED], true));

            $replayed++;
        }

        return $replayed;
    }

    private function lease(DataDeletionRequest $request, Carbon $now): bool
    {
        return DataDeletionRequest::query()
            ->whereKey($request->id)
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', $now))
            ->update(['lease_until' => $now->copy()->addSeconds(self::LEASE_SECONDS)]) === 1;
    }

    private function run(DataDeletionRequest $request, int $chunkBudget): string
    {
        try {
            if ($request->status === DataDeletionRequest::STATUS_REQUESTED) {
                $started = DataDeletionRequest::query()
                    ->whereKey($request->id)
                    ->where('status', DataDeletionRequest::STATUS_REQUESTED)
                    ->update(['status' => DataDeletionRequest::STATUS_REVOKING, 'cursor' => json_encode([
                        'members' => $this->memberIds($request->tenant_id),
                        'owners' => app(OrganizationDeletion::class)->owners($request->tenant_id)->pluck('id')->all(),
                    ])]);

                if ($started !== 1) {
                    return (string) DataDeletionRequest::query()->whereKey($request->id)->value('status');
                }

                $request = $request->fresh();
            }

            if ($request->status === DataDeletionRequest::STATUS_REVOKING) {
                $this->revoke($request->tenant_id);
                $request = $this->advance($request, DataDeletionRequest::STATUS_DELETING_OBJECTS);
            }

            if ($request->status === DataDeletionRequest::STATUS_DELETING_OBJECTS) {
                $this->deleteObjects($request->tenant_id);
                $request = $this->advance($request, DataDeletionRequest::STATUS_PURGING);
            }

            if ($request->status === DataDeletionRequest::STATUS_PURGING && ! $this->purge($request, $chunkBudget)) {
                $request->forceFill(['lease_until' => null])->save();

                return $request->status;
            }

            $this->finish($request);

            return DataDeletionRequest::STATUS_COMPLETED;
        } catch (Throwable $exception) {
            $attempts = $request->attempts + 1;
            Log::error('privacy.deletion_failed', ['request_id' => $request->id, 'stage' => $request->status, 'exception' => $exception::class]);
            $failed = $attempts >= self::MAX_ATTEMPTS;
            $request->forceFill([
                'attempts' => $attempts,
                'error_code' => Str::limit(class_basename($exception), 60, ''),
                'status' => $failed ? DataDeletionRequest::STATUS_FAILED : $request->status,
                'cursor' => $failed ? array_merge($request->cursor ?? [], ['failed_stage' => $request->status]) : $request->cursor,
                'lease_until' => null,
            ])->save();

            return $request->status;
        }
    }

    /**
     * @param  array<string, mixed>  $cursor
     */
    private function advance(DataDeletionRequest $request, string $status, array $cursor = []): DataDeletionRequest
    {
        $request->forceFill(['status' => $status, 'cursor' => array_merge($request->cursor ?? [], $cursor)])->save();

        return $request;
    }

    /**
     * @return list<string>
     */
    private function memberIds(string $tenantId): array
    {
        return Membership::query()->where('tenant_id', $tenantId)->pluck('user_id')->unique()->values()->all();
    }

    private function revoke(string $tenantId): void
    {
        Integration::query()->where('tenant_id', $tenantId)->update(['status' => Integration::STATUS_REVOKED]);
        DB::table('integration_credentials')->where('tenant_id', $tenantId)->delete();
        DB::table('pairing_codes')->where('tenant_id', $tenantId)->delete();
        DB::table('notification_channels')->where('tenant_id', $tenantId)->update(['enabled' => false]);
    }

    private function deleteObjects(string $tenantId): void
    {
        foreach (['artifacts' => 'watchdog.artifacts.disk', 'exports' => 'watchdog.exports.disk'] as $table => $diskConfig) {
            $disk = Storage::disk((string) config($diskConfig));

            DB::table($table)
                ->where('tenant_id', $tenantId)
                ->whereNotNull('object_key')
                ->orderBy('id')
                ->select(['id', 'object_key'])
                ->chunkById(self::CHUNK, function ($rows) use ($disk): void {
                    foreach ($rows as $row) {
                        $disk->delete($row->object_key);
                    }
                });
        }
    }

    /** Returns false when the chunk budget ran out before every table was empty; the cursor keeps the position. */
    private function purge(DataDeletionRequest $request, int $chunkBudget): bool
    {
        $tenantId = $request->tenant_id;
        $cursor = $request->cursor ?? [];
        $start = (int) ($cursor['table_index'] ?? 0);

        foreach (array_slice(self::PURGED_TABLES, $start, null, true) as $index => $table) {
            if ($table === 'billing_discount_applications') {
                DB::table('billing_discount_application_lines')
                    ->whereIn('application_id', DB::table('billing_discount_applications')->where('tenant_id', $tenantId)->select('id'))
                    ->delete();
            }

            do {
                if ($chunkBudget-- <= 0) {
                    $request->forceFill(['cursor' => array_merge($cursor, ['table_index' => $index])])->save();

                    return false;
                }

                $deleted = DB::table($table)->where('tenant_id', $tenantId)->limit(self::CHUNK)->delete();
            } while ($deleted === self::CHUNK);

            $cursor['table_index'] = $index + 1;
            $request->forceFill(['cursor' => $cursor])->save();
        }

        return true;
    }

    private function finish(DataDeletionRequest $request): void
    {
        if (! ($request->cursor['notified'] ?? false)) {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($request->tenant_id)->firstOrFail();
            app(OrganizationDeletion::class)->notify(
                User::query()->whereIn('id', $request->cursor['owners'] ?? [])->where('email', 'not like', '%@deleted.invalid')->get(),
                'organization_deletion_completed',
                $tenant,
                $request->requested_via,
            );
            $request = $this->advance($request, $request->status, ['notified' => true]);
        }

        DB::transaction(function () use ($request): void {
            $now = Carbon::now();

            foreach ($request->cursor['members'] ?? [] as $userId) {
                $this->anonymizeIfUnused((string) $userId);
            }

            DB::table('trial_store_hosts')
                ->where('tenant_id', $request->tenant_id)
                ->where('host', 'not like', TrialStoreHosts::HASH_PREFIX.'%')
                ->get(['host'])
                ->each(fn ($row) => DB::table('trial_store_hosts')->where('host', $row->host)->update(['host' => TrialStoreHosts::hashed($row->host)]));

            Tenant::query()->whereKey($request->tenant_id)->update([
                'name' => 'Deleted organization',
                'status' => 'deleted',
                'updated_at' => $now,
            ]);

            DB::table('data_deletion_journal')->insertOrIgnore([
                'request_id' => $request->id,
                'tenant_id' => $request->tenant_id,
                'requested_via' => $request->requested_via,
                'requested_at' => $request->requested_at,
                'completed_at' => $now,
            ]);

            $request->forceFill([
                'status' => DataDeletionRequest::STATUS_COMPLETED,
                'reason' => null,
                'cursor' => [],
                'error_code' => null,
                'lease_until' => null,
                'completed_at' => $now,
            ])->save();
        });
    }

    private function anonymizeIfUnused(string $userId): void
    {
        if (Membership::query()->where('user_id', $userId)->exists() || PlatformStaff::query()->where('user_id', $userId)->exists()) {
            return;
        }

        /** @var User|null $user */
        $user = User::query()->whereKey($userId)->first();

        if ($user === null || str_ends_with($user->email, '@deleted.invalid')) {
            return;
        }

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('sessions')->where('user_id', $userId)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $userId)->delete();
        DB::table('mfa_recovery_codes')->where('user_id', $userId)->delete();

        $user->forceFill([
            'email' => 'deleted-'.$userId.'@deleted.invalid',
            'name' => 'Deleted user',
            'password_hash' => Hash::make(Str::random(64)),
            'email_verified_at' => null,
            'disabled_at' => Carbon::now(),
            'remember_token' => null,
            'mfa_secret_ciphertext' => null,
            'mfa_key_version' => null,
            'mfa_confirmed_at' => null,
            'mfa_last_used_step' => null,
        ])->save();
    }

    private function anyUserId(string $tenantId): string
    {
        return (string) (DB::table('memberships')->where('tenant_id', $tenantId)->value('user_id')
            ?? DB::table('policy_acceptances')->where('tenant_id', $tenantId)->value('user_id')
            ?? User::query()->value('id'));
    }
}
