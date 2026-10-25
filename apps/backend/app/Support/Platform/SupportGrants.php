<?php

namespace App\Support\Platform;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\PlatformStaff;
use App\Models\Store;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountMailer;
use App\Support\Auth\TenantRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportGrants
{
    public function __construct(private readonly PlatformAudit $audit, private readonly AccountMailer $mailer) {}

    /** @param list<string> $scopes */
    public function request(string $tenantId, string $storeId, PlatformStaff $staff, array $scopes, int $hours, string $reason, ?User $customer): SupportAccessGrant
    {
        abort_unless($staff->active() && PlatformCapabilities::allows($staff, PlatformCapabilities::TENANTS_SUPPORT), 403);
        abort_unless($hours >= 1 && $hours <= 72 && $scopes !== [] && array_values(array_unique($scopes)) === $scopes && array_diff($scopes, SupportAccessGrant::SCOPES) === [], 422);

        $grant = DB::transaction(function () use ($tenantId, $storeId, $staff, $scopes, $hours, $reason, $customer): SupportAccessGrant {
            // Serialize requests for this store; a retry with identical parameters returns the same pending request.
            $store = Store::query()->where('tenant_id', $tenantId)->whereKey($storeId)->lockForUpdate()->firstOrFail();
            $open = SupportAccessGrant::query()->where('tenant_id', $tenantId)->where('store_id', $store->id)
                ->where('staff_id', $staff->id)->where('state', SupportAccessGrant::PENDING)->get();

            foreach ($open as $existing) {
                if ($existing->scopes === $scopes && $existing->duration_hours === $hours && $existing->reason === trim($reason)) {
                    return $existing;
                }
            }

            $nextNumber = DB::table('support_case_counters')->where('name', 'support_access')->lockForUpdate()->value('next_number');
            abort_if($nextNumber === null, 500, 'support_case_counter_missing');
            DB::table('support_case_counters')->where('name', 'support_access')->update(['next_number' => (int) $nextNumber + 1]);

            $grant = SupportAccessGrant::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => $store->id,
                'staff_id' => $staff->id,
                'requested_by_staff_id' => $customer === null ? $staff->id : null,
                'requested_by_user_id' => $customer?->id,
                'scopes' => $scopes,
                'duration_hours' => $hours,
                'reason' => trim($reason),
                'case_reference' => sprintf('SUP-%06d', $nextNumber),
                'state' => SupportAccessGrant::PENDING,
                'version' => 1,
            ]);
            $this->tenantAudit($tenantId, $store->id, $customer?->id, 'support_grant.requested', $grant->id, ['staff_id' => $staff->id, 'scopes' => $scopes, 'duration_hours' => $hours]);

            if ($customer === null) {
                $this->audit->record($staff->id, 'support_grant.requested', 'support_grant', $grant->id, PlatformAudit::RESULT_SUCCEEDED, ['store_id' => $store->id, 'scopes' => $scopes, 'duration_hours' => $hours], $reason, $tenantId);
            }

            return $grant;
        });

        if ($grant->wasRecentlyCreated) {
            $this->notifyOwners($grant, $staff);
        }

        return $grant;
    }

    public function decide(SupportAccessGrant $grant, User $owner, bool $approve): SupportAccessGrant
    {
        return DB::transaction(function () use ($grant, $owner, $approve): SupportAccessGrant {
            $locked = SupportAccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== SupportAccessGrant::PENDING) {
                abort_unless($locked->state === ($approve ? SupportAccessGrant::APPROVED : SupportAccessGrant::DENIED) && $locked->approved_by === $owner->id, 409);

                return $locked;
            }

            $now = Carbon::now();
            $locked->forceFill([
                'state' => $approve ? SupportAccessGrant::APPROVED : SupportAccessGrant::DENIED,
                'approved_by' => $owner->id,
                'approved_at' => $approve ? $now : null,
                'expires_at' => $approve ? $now->copy()->addHours($locked->duration_hours) : null,
                'version' => $locked->version + 1,
            ])->save();
            $this->tenantAudit($locked->tenant_id, $locked->store_id, $owner->id, $approve ? 'support_grant.approved' : 'support_grant.denied', $locked->id, ['staff_id' => $locked->staff_id, 'scopes' => $locked->scopes, 'expires_at' => $locked->expires_at?->toJSON()]);

            return $locked->refresh();
        });
    }

    public function revoke(SupportAccessGrant $grant, User $owner): SupportAccessGrant
    {
        return DB::transaction(function () use ($grant, $owner): SupportAccessGrant {
            $locked = SupportAccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === SupportAccessGrant::REVOKED) {
                return $locked;
            }

            abort_unless($locked->state === SupportAccessGrant::APPROVED, 409);
            $locked->forceFill(['state' => SupportAccessGrant::REVOKED, 'revoked_at' => Carbon::now(), 'version' => $locked->version + 1])->save();
            $this->tenantAudit($locked->tenant_id, $locked->store_id, $owner->id, 'support_grant.revoked', $locked->id, ['staff_id' => $locked->staff_id]);

            return $locked->refresh();
        });
    }

    /** The requesting employee withdraws a request the owner has not decided yet; the e-mail link stops working. */
    public function cancel(SupportAccessGrant $grant, PlatformStaff $staff): SupportAccessGrant
    {
        return DB::transaction(function () use ($grant, $staff): SupportAccessGrant {
            $locked = SupportAccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === SupportAccessGrant::CANCELLED) {
                return $locked;
            }

            abort_unless($locked->state === SupportAccessGrant::PENDING, 409, 'support_grant_not_pending');
            $locked->forceFill(['state' => SupportAccessGrant::CANCELLED, 'revoked_at' => Carbon::now(), 'version' => $locked->version + 1])->save();
            $this->tenantAudit($locked->tenant_id, $locked->store_id, null, 'support_grant.cancelled', $locked->id, ['staff_id' => $staff->id]);
            $this->audit->record($staff->id, 'support_grant.cancelled', 'support_grant', $locked->id, PlatformAudit::RESULT_SUCCEEDED, ['store_id' => $locked->store_id, 'case_reference' => $locked->case_reference], null, $locked->tenant_id);

            return $locked->refresh();
        });
    }

    /**
     * «Send again»: a request still waiting gets its e-mail to the owners once more; any decided, revoked, expired or
     * cancelled request is asked again as a new request with the same store, scopes, duration and reason.
     */
    public function resend(SupportAccessGrant $grant, PlatformStaff $staff): SupportAccessGrant
    {
        if ($grant->state === SupportAccessGrant::PENDING) {
            $this->audit->record($staff->id, 'support_grant.resent', 'support_grant', $grant->id, PlatformAudit::RESULT_SUCCEEDED, ['store_id' => $grant->store_id, 'case_reference' => $grant->case_reference], null, $grant->tenant_id);
            $this->notifyOwners($grant, $staff);

            return $grant;
        }

        return $this->request($grant->tenant_id, $grant->store_id, $staff, $grant->scopes, $grant->duration_hours, $grant->reason, null);
    }

    public function active(PlatformStaff $staff, string $tenantId, string $storeId, string $scope): ?SupportAccessGrant
    {
        if (! $staff->active() || ! PlatformCapabilities::allows($staff, PlatformCapabilities::TENANTS_SUPPORT)) {
            return null;
        }

        $grants = SupportAccessGrant::query()->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->where('staff_id', $staff->id)->where('state', SupportAccessGrant::APPROVED)
            ->where('expires_at', '>', Carbon::now())->orderByDesc('approved_at')->get();

        foreach ($grants as $grant) {
            if (in_array($scope, $grant->scopes, true)) {
                return $grant;
            }
        }

        return null;
    }

    public function require(PlatformStaff $staff, string $tenantId, string $storeId, string $scope, string $targetType, string $targetId): SupportAccessGrant
    {
        $grant = $this->active($staff, $tenantId, $storeId, $scope);
        abort_if($grant === null, 403, 'support_grant_required');

        // Reads of detailed customer evidence have a durable record visible to the tenant owner.
        $this->tenantAudit($tenantId, $storeId, null, $scope === SupportAccessGrant::READ_ARTIFACTS ? 'support_access.artifact' : 'support_access.opened', $targetId, [
            'staff_id' => $staff->id, 'grant_id' => $grant->id, 'target_type' => $targetType, 'scope' => $scope,
        ]);
        $this->audit->record($staff->id, 'support_access.opened', $targetType, $targetId, PlatformAudit::RESULT_SUCCEEDED, ['store_id' => $storeId, 'grant_id' => $grant->id, 'scope' => $scope], null, $tenantId);

        return $grant;
    }

    /** @param array<string, mixed> $changes */
    private function tenantAudit(string $tenantId, string $storeId, ?string $userId, string $action, string $entityId, array $changes): void
    {
        AuditLog::query()->create([
            'tenant_id' => $tenantId, 'store_id' => $storeId, 'actor_user_id' => $userId,
            'actor_type' => $userId === null ? AuditLog::ACTOR_SYSTEM : AuditLog::ACTOR_USER,
            'action' => $action, 'entity_type' => 'support_grant', 'entity_id' => $entityId,
            'changes' => $changes,
            'request_id' => (string) (request()?->attributes->get('request_id') ?? Str::uuid()),
            'created_at' => Carbon::now(),
        ]);
    }

    private function notifyOwners(SupportAccessGrant $grant, PlatformStaff $staff): void
    {
        $tenant = Tenant::query()->find($grant->tenant_id);
        $store = Store::query()->where('tenant_id', $grant->tenant_id)->find($grant->store_id);
        $owners = User::query()->whereIn('id', Membership::query()->where('tenant_id', $grant->tenant_id)->where('role', TenantRoles::OWNER)->select('user_id'))->get();

        foreach ($owners as $owner) {
            try {
                $this->mailer->send($owner->email, $owner->locale, 'support_access_requested', [
                    'tenant' => (string) $tenant?->name,
                    'store' => (string) $store?->name,
                    'staff' => (string) $staff->user?->name,
                    'case' => $grant->case_reference,
                    'link' => $this->mailer->link('/app/settings/support'),
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
