<?php

namespace App\Support\Privacy;

use App\Models\AuditLog;
use App\Models\DataDeletionRequest;
use App\Models\Membership;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountMailer;
use App\Support\Auth\TenantRoles;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\Paddle\PaddleSubscriptionActions;
use App\Support\Stores\StoreCatchUp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Requesting and cancelling the deletion of an organization (ADR 0069). A request stops the service at once, ends a
 * paid subscription first, and leaves 30 days to cancel before `OrganizationPurger` removes the data.
 */
class OrganizationDeletion
{
    public const GRACE_DAYS = 30;

    public const DELETING_STATUSES = ['deleting', 'deleted'];

    public function __construct(
        private readonly PaddleSubscriptionActions $subscriptions,
        private readonly StoreCatchUp $catchUp,
        private readonly AccountMailer $mailer,
    ) {}

    /**
     * Tells every owner of the organization (ADR 0069); a mail failure never undoes the deletion step.
     *
     * @param  iterable<User>  $owners
     */
    public function notify(iterable $owners, string $template, Tenant $tenant, string $via, ?Carbon $date = null): void
    {
        foreach ($owners as $owner) {
            try {
                $this->mailer->send($owner->email, $owner->locale, $template, [
                    'tenant' => $tenant->name,
                    'by' => trans('account.organization_deletion_by.'.$via, [], in_array($owner->locale, AccountMailer::LOCALES, true) ? $owner->locale : 'en'),
                    'date' => $date?->copy()->setTimezone($tenant->timezone ?: 'UTC')->format('Y-m-d') ?? '',
                    'link' => $this->mailer->link('/app/'),
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function owners(string $tenantId): Collection
    {
        return User::query()
            ->whereIn('id', Membership::query()->where('tenant_id', $tenantId)->where('role', TenantRoles::OWNER)->select('user_id'))
            ->get();
    }

    public function open(string $tenantId): ?DataDeletionRequest
    {
        return DataDeletionRequest::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', DataDeletionRequest::OPEN_STATUSES)
            ->first();
    }

    public function request(Tenant $tenant, string $actorUserId, string $via, ?string $reason): DataDeletionRequest
    {
        if (in_array($tenant->status, self::DELETING_STATUSES, true) || $this->open($tenant->id) !== null) {
            throw new DeletionRejected('deletion_already_requested');
        }

        try {
            $this->subscriptions->cancelImmediately($tenant->id, $via === DataDeletionRequest::VIA_OWNER ? $actorUserId : null);
        } catch (BillingCheckoutRejected $exception) {
            throw new DeletionRejected('subscription_cancel_failed', 503);
        }

        $request = DB::transaction(function () use ($tenant, $actorUserId, $via, $reason): DataDeletionRequest {
            /** @var Tenant $locked */
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, self::DELETING_STATUSES, true)) {
                throw new DeletionRejected('deletion_already_requested');
            }

            $now = Carbon::now();
            $request = DataDeletionRequest::query()->create([
                'tenant_id' => $locked->id,
                'requested_by' => $actorUserId,
                'requested_via' => $via,
                'reason' => $reason,
                'previous_status' => $locked->status,
                'status' => DataDeletionRequest::STATUS_REQUESTED,
                'cursor' => [],
                'attempts' => 0,
                'requested_at' => $now,
                'purge_after' => $now->copy()->addDays(self::GRACE_DAYS),
            ]);

            $locked->forceFill(['status' => 'deleting'])->save();
            $this->audit($locked->id, $actorUserId, 'organization.deletion_requested', $request->id, [
                'via' => $via,
                'purge_after' => $request->purge_after->toJSON(),
            ]);

            return $request;
        });

        $this->notify($this->owners($tenant->id), 'organization_deletion_requested', $tenant, $via, $request->purge_after);

        return $request;
    }

    public function cancel(Tenant $tenant, string $actorUserId): DataDeletionRequest
    {
        $request = DB::transaction(function () use ($tenant, $actorUserId): DataDeletionRequest {
            /** @var DataDeletionRequest|null $request */
            $request = DataDeletionRequest::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', DataDeletionRequest::OPEN_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw new DeletionRejected('deletion_not_requested', 404);
            }

            if ($request->status !== DataDeletionRequest::STATUS_REQUESTED) {
                throw new DeletionRejected('deletion_in_progress');
            }

            $now = Carbon::now();
            $request->forceFill(['status' => DataDeletionRequest::STATUS_CANCELLED, 'cancelled_at' => $now, 'cancelled_by' => $actorUserId])->save();
            Tenant::query()->whereKey($tenant->id)->update(['status' => $request->previous_status, 'updated_at' => $now]);

            Store::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('capacity_paused_at')
                ->whereNotIn('status', ['deleted', 'deleting'])
                ->pluck('id')
                ->each(fn (string $storeId) => $this->catchUp->resumed($tenant->id, $storeId, $request->requested_at));

            $this->audit($tenant->id, $actorUserId, 'organization.deletion_cancelled', $request->id, []);

            return $request;
        });

        $via = Membership::query()->where('tenant_id', $tenant->id)->where('user_id', $actorUserId)->exists() ? DataDeletionRequest::VIA_OWNER : DataDeletionRequest::VIA_PLATFORM;
        $this->notify($this->owners($tenant->id), 'organization_deletion_cancelled', $tenant, $via);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(string $tenantId, string $actorUserId, string $action, string $requestId, array $changes): void
    {
        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'actor_type' => AuditLog::ACTOR_USER,
            'action' => $action,
            'entity_type' => 'data_deletion_request',
            'entity_id' => $requestId,
            'changes' => $changes,
            'request_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);
    }
}
