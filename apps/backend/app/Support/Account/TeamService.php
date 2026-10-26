<?php

namespace App\Support\Account;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantPermissions;
use App\Support\Auth\TenantRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Team rules (ADR 0019, ADR 0079): the owner has every permission and manages everyone else; a member with
 * `team.manage` manages only members whose permissions they have themselves and gives only those; nobody changes
 * their own permissions; the owner role is never assigned here and the owner cannot leave.
 */
class TeamService
{
    public const INVITATION_TTL_DAYS = 7;

    public const ASSIGNABLE = [TenantRoles::ADMIN, TenantRoles::OPERATOR, TenantRoles::VIEWER];

    public function __construct(
        private readonly AccountMailer $mailer,
    ) {}

    /**
     * Presets the actor may apply in full.
     *
     * @return list<string>
     */
    public function assignableBy(?Membership $actor): array
    {
        $permissions = $this->assignablePermissionsBy($actor);

        return array_values(array_filter(self::ASSIGNABLE, fn (string $role): bool => array_diff(TenantPermissions::preset($role, false), $permissions) === []));
    }

    /**
     * Permissions the actor may give: the owner every assignable one, a team manager only their own.
     *
     * @return list<string>
     */
    public function assignablePermissionsBy(?Membership $actor): array
    {
        if ($actor === null) {
            return [];
        }

        if ($actor->role === TenantRoles::OWNER) {
            return TenantPermissions::assignable();
        }

        $own = TenantPermissions::forMembership($actor);

        return in_array(TenantPermissions::TEAM_MANAGE, $own, true) ? $own : [];
    }

    public function membership(string $tenantId, string $userId): ?Membership
    {
        return Membership::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
    }

    public function roleOf(string $tenantId, string $userId): ?string
    {
        return $this->membership($tenantId, $userId)?->role;
    }

    /**
     * @return Collection<int, Membership>
     */
    public function members(string $tenantId): Collection
    {
        return Membership::query()->where('tenant_id', $tenantId)->with('user')->orderBy('created_at')->get();
    }

    /** A preset keeps the member's choice about amounts. */
    public function changeRole(string $tenantId, User $actor, string $targetUserId, string $role): Membership
    {
        $current = $this->membership($tenantId, $targetUserId);

        return $this->changePermissions($tenantId, $actor, $targetUserId, TenantPermissions::preset($role, TenantPermissions::allows($current, TenantPermissions::MONEY_VIEW)));
    }

    /**
     * Takes effect on the member's next request, also in open sessions, because every check reads the stored list.
     *
     * @param  list<string>  $permissions
     */
    public function changePermissions(string $tenantId, User $actor, string $targetUserId, array $permissions): Membership
    {
        [$membership, $before, $after] = DB::transaction(function () use ($tenantId, $actor, $targetUserId, $permissions): array {
            [$actorMembership, $target] = $this->lockPair($tenantId, $actor, $targetUserId);

            if ($target->user_id === $actor->id) {
                throw new AccountRejected('cannot_change_own_role');
            }

            $this->assertManages($actorMembership, $target);
            $next = $this->grantable($actorMembership, $permissions);
            $before = ['role' => $target->role, 'permissions' => TenantPermissions::forMembership($target)];
            $after = ['role' => TenantPermissions::roleFor($next), 'permissions' => $next];

            if ($before !== $after) {
                $this->storePermissions($tenantId, $targetUserId, $next);
                $this->audit($tenantId, $actor->id, 'membership.permissions_changed', 'membership', $targetUserId, ['before' => $before, 'after' => $after]);
            }

            return [Membership::query()->where('tenant_id', $tenantId)->where('user_id', $targetUserId)->with('user')->firstOrFail(), $before, $after];
        });

        if ($before !== $after) {
            $this->notifyPermissionsChanged($membership, $before['permissions'], $after['permissions']);
        }

        return $membership;
    }

    public function remove(string $tenantId, User $actor, string $targetUserId): void
    {
        DB::transaction(function () use ($tenantId, $actor, $targetUserId): void {
            [$actorMembership, $target] = $this->lockPair($tenantId, $actor, $targetUserId);

            if ($target->role === TenantRoles::OWNER) {
                throw new AccountRejected($target->user_id === $actor->id ? 'owner_cannot_leave' : 'team_forbidden', $target->user_id === $actor->id ? 422 : 403);
            }

            if ($target->user_id !== $actor->id) {
                $this->assertManages($actorMembership, $target);
            }

            Membership::query()->where('tenant_id', $tenantId)->where('user_id', $targetUserId)->delete();
            $this->audit($tenantId, $actor->id, $target->user_id === $actor->id ? 'membership.left' : 'membership.removed', 'membership', $targetUserId, ['role' => $target->role]);
        });
    }

    /**
     * Platform staff change a member's role at the customer's request (ADR 0048). The owner role is never given or
     * taken here — it only moves through an ownership transfer.
     */
    public function changeRoleForPlatform(string $tenantId, string $targetUserId, string $role, string $reason): Membership
    {
        return DB::transaction(function () use ($tenantId, $targetUserId, $role, $reason): Membership {
            $target = $this->lockedMember($tenantId, $targetUserId);

            if ($target->role === TenantRoles::OWNER || $role === TenantRoles::OWNER) {
                throw new AccountRejected('owner_role_protected', 409);
            }

            if (! in_array($role, self::ASSIGNABLE, true)) {
                throw new AccountRejected('role_not_assignable');
            }

            $before = ['role' => $target->role, 'permissions' => TenantPermissions::forMembership($target)];
            $next = TenantPermissions::preset($role, TenantPermissions::allows($target, TenantPermissions::MONEY_VIEW));
            $this->storePermissions($tenantId, $targetUserId, $next);
            $this->audit($tenantId, null, 'membership.permissions_changed', 'membership', $targetUserId, [
                'before' => $before,
                'after' => ['role' => TenantPermissions::roleFor($next), 'permissions' => $next],
                'by' => 'platform',
                'reason' => $reason,
            ]);

            return Membership::query()->where('tenant_id', $tenantId)->where('user_id', $targetUserId)->with('user')->firstOrFail();
        });
    }

    public function removeForPlatform(string $tenantId, string $targetUserId, string $reason): Membership
    {
        return DB::transaction(function () use ($tenantId, $targetUserId, $reason): Membership {
            $target = $this->lockedMember($tenantId, $targetUserId);

            if ($target->role === TenantRoles::OWNER) {
                throw new AccountRejected('owner_role_protected', 409);
            }

            Membership::query()->where('tenant_id', $tenantId)->where('user_id', $targetUserId)->delete();
            $this->audit($tenantId, null, 'membership.removed', 'membership', $targetUserId, ['role' => $target->role, 'by' => 'platform', 'reason' => $reason]);

            return $target;
        });
    }

    /**
     * The owner hands the team to a member with a confirmed address and stays as admin; both changes happen together,
     * so the team always has exactly one owner (ADR 0021). Re-authentication is checked by the caller.
     */
    public function transferOwnership(string $tenantId, User $actor, string $targetUserId): Membership
    {
        $newOwner = DB::transaction(function () use ($tenantId, $actor, $targetUserId): Membership {
            [$actorMembership, $target] = $this->lockPair($tenantId, $actor, $targetUserId);

            if ($actorMembership->role !== TenantRoles::OWNER) {
                throw new AccountRejected('team_forbidden', 403);
            }

            if ($target->user_id === $actor->id) {
                throw new AccountRejected('cannot_transfer_to_self');
            }

            /** @var User|null $targetUser */
            $targetUser = User::query()->whereKey($target->user_id)->whereNull('disabled_at')->first();

            if ($targetUser === null) {
                throw new AccountRejected('member_not_found', 404);
            }

            if ($targetUser->email_verified_at === null) {
                throw new AccountRejected('target_email_unverified');
            }

            $this->storePermissions($tenantId, $actor->id, TenantPermissions::preset(TenantRoles::ADMIN, true));
            Membership::query()->where('tenant_id', $tenantId)->where('user_id', $target->user_id)->update(['role' => TenantRoles::OWNER, 'permissions' => null]);
            $this->audit($tenantId, $actor->id, 'membership.ownership_transferred', 'membership', $target->user_id, [
                'owner' => ['from' => $actor->id, 'to' => $target->user_id],
                'previous_owner_role' => TenantRoles::ADMIN,
                'new_owner_previous_role' => $target->role,
            ]);

            return Membership::query()->where('tenant_id', $tenantId)->where('user_id', $target->user_id)->with('user')->firstOrFail();
        });

        $user = $newOwner->user;
        if ($user !== null && $user->mfa_confirmed_at === null) {
            try {
                $this->mailer->send($user->email, $user->locale, 'new_owner_mfa_warning', [
                    'tenant' => Tenant::query()->whereKey($tenantId)->value('name') ?? '',
                    'link' => $this->mailer->link('/app/settings/profile'),
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $newOwner;
    }

    /**
     * @return Collection<int, Invitation>
     */
    public function pendingInvitations(string $tenantId): Collection
    {
        return Invitation::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now())
            ->with('inviter')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  list<string>  $permissions
     */
    public function invite(Tenant $tenant, User $actor, string $email, array $permissions): Invitation
    {
        $granted = $this->grantable($this->membership($tenant->id, $actor->id), $permissions);

        return $this->issue($tenant, $actor, $actor->name, $actor->locale, $email, $granted, true);
    }

    /**
     * A pending invitation can be corrected until it is accepted; the link stays the same.
     *
     * @param  list<string>  $permissions
     */
    public function updateInvitation(string $tenantId, User $actor, string $invitationId, array $permissions): Invitation
    {
        return DB::transaction(function () use ($tenantId, $actor, $invitationId, $permissions): Invitation {
            /** @var Invitation|null $invitation */
            $invitation = Invitation::query()->where('tenant_id', $tenantId)->whereKey($invitationId)->lockForUpdate()->first();

            if ($invitation === null) {
                throw new AccountRejected('invitation_not_found', 404);
            }

            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
                throw new AccountRejected('invitation_invalid');
            }

            $actorMembership = $this->membership($tenantId, $actor->id);
            $this->assertManagesPermissions($actorMembership, (array) $invitation->permissions);
            $next = $this->grantable($actorMembership, $permissions);
            $before = ['role' => $invitation->role, 'permissions' => TenantPermissions::normalize((array) $invitation->permissions)];
            $after = ['role' => TenantPermissions::roleFor($next), 'permissions' => $next];

            if ($before !== $after) {
                $invitation->forceFill(['role' => $after['role'], 'permissions' => $next])->save();
                $this->audit($tenantId, $actor->id, 'invitation.permissions_changed', 'invitation', $invitation->id, ['before' => $before, 'after' => $after]);
            }

            return $invitation->refresh()->load('inviter');
        });
    }

    /** Platform staff invite on the organisation's behalf: any assignable role, the letter names the service, not a person. */
    public function inviteForPlatform(Tenant $tenant, User $staffUser, string $email, string $role): Invitation
    {
        if (! in_array($role, self::ASSIGNABLE, true)) {
            throw new AccountRejected('role_not_assignable');
        }

        $locale = (string) ($tenant->locale ?? $staffUser->locale);

        return $this->issue($tenant, $staffUser, trans('account.platform_inviter', [], in_array($locale, AccountMailer::LOCALES, true) ? $locale : 'en'), $staffUser->locale, $email, TenantPermissions::preset($role, true), false);
    }

    public function revokeForPlatform(string $tenantId, string $invitationId): Invitation
    {
        return DB::transaction(function () use ($tenantId, $invitationId): Invitation {
            /** @var Invitation|null $invitation */
            $invitation = Invitation::query()->where('tenant_id', $tenantId)->whereKey($invitationId)->lockForUpdate()->first();

            if ($invitation === null) {
                throw new AccountRejected('invitation_not_found', 404);
            }

            if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
                $invitation->forceFill(['revoked_at' => Carbon::now()])->save();
                $this->audit($tenantId, null, 'invitation.revoked', 'invitation', $invitation->id, ['by' => 'platform']);
            }

            return $invitation->refresh()->load('inviter');
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function issue(Tenant $tenant, User $actor, string $inviterName, ?string $actorLocale, string $email, array $permissions, bool $byMember): Invitation
    {
        $token = Str::random(48);
        $role = TenantPermissions::roleFor($permissions);

        $invitation = DB::transaction(function () use ($tenant, $actor, $email, $role, $permissions, $token, $byMember): Invitation {

            $alreadyMember = Membership::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('user_id', User::query()->where('email', $email)->select('id'))
                ->exists();

            if ($alreadyMember) {
                throw new AccountRejected('already_member', 409);
            }

            Invitation::query()
                ->where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now()]);

            $invitation = Invitation::query()->create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'role' => $role,
                'permissions' => $permissions,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $actor->id,
                'expires_at' => Carbon::now()->addDays(self::INVITATION_TTL_DAYS),
            ]);
            $this->audit($tenant->id, $byMember ? $actor->id : null, 'invitation.created', 'invitation', $invitation->id, $byMember ? ['role' => $role, 'permissions' => $permissions] : ['role' => $role, 'permissions' => $permissions, 'by' => 'platform']);

            return $invitation;
        });

        $locale = (string) ($tenant->locale ?? $actorLocale);
        $this->mailer->send($email, $locale, 'invitation', [
            'inviter' => $inviterName,
            'tenant' => $tenant->name,
            'role' => $this->mailer->role($role, $locale),
            'expires' => $invitation->expires_at->copy()->setTimezone((string) $tenant->timezone)->format('d.m.Y H:i T'),
            'link' => $this->mailer->link('/app/invitation?'.http_build_query(['token' => $token])),
        ]);

        return $invitation->load('inviter');
    }

    public function revoke(string $tenantId, User $actor, string $invitationId): Invitation
    {
        return DB::transaction(function () use ($tenantId, $actor, $invitationId): Invitation {
            /** @var Invitation|null $invitation */
            $invitation = Invitation::query()->where('tenant_id', $tenantId)->whereKey($invitationId)->lockForUpdate()->first();

            if ($invitation === null) {
                throw new AccountRejected('invitation_not_found', 404);
            }

            $this->assertManagesPermissions($this->membership($tenantId, $actor->id), (array) $invitation->permissions);

            if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
                $invitation->forceFill(['revoked_at' => Carbon::now()])->save();
                $this->audit($tenantId, $actor->id, 'invitation.revoked', 'invitation', $invitation->id, []);
            }

            return $invitation->refresh()->load('inviter');
        });
    }

    /** A usable invitation for this token, or a uniform rejection (unknown, expired, revoked or accepted). */
    public function findPending(string $token): Invitation
    {
        /** @var Invitation|null $invitation */
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->with('tenant')->first();

        if ($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
            throw new AccountRejected('invitation_invalid');
        }

        return $invitation;
    }

    /** The token was mailed to the invited address, so accepting it also confirms that address (ADR 0019). */
    public function accept(User $user, string $token): Membership
    {
        return DB::transaction(function () use ($user, $token): Membership {
            $invitation = $this->findPending($token);
            $invitation = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
                throw new AccountRejected('invitation_invalid');
            }

            if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
                throw new AccountRejected('invitation_email_mismatch');
            }

            if ($this->roleOf($invitation->tenant_id, $user->id) !== null) {
                throw new AccountRejected('already_member', 409);
            }

            Membership::query()->create(['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id, 'role' => $invitation->role, 'permissions' => TenantPermissions::normalize((array) $invitation->permissions)]);
            $invitation->forceFill(['accepted_at' => Carbon::now()])->save();

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => Carbon::now()])->save();
            }

            $this->audit($invitation->tenant_id, $user->id, 'invitation.accepted', 'invitation', $invitation->id, ['role' => $invitation->role, 'permissions' => TenantPermissions::normalize((array) $invitation->permissions)]);

            return Membership::query()->where('tenant_id', $invitation->tenant_id)->where('user_id', $user->id)->with('user')->firstOrFail();
        });
    }

    private function lockedMember(string $tenantId, string $userId): Membership
    {
        /** @var Membership|null $member */
        $member = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->lockForUpdate()->first();

        return $member ?? throw new AccountRejected('member_not_found', 404);
    }

    /**
     * @return array{0: Membership, 1: Membership}
     */
    private function lockPair(string $tenantId, User $actor, string $targetUserId): array
    {
        /** @var Membership|null $actorMembership */
        $actorMembership = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $actor->id)->lockForUpdate()->first();
        /** @var Membership|null $target */
        $target = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $targetUserId)->lockForUpdate()->first();

        if ($actorMembership === null || $target === null) {
            throw new AccountRejected('member_not_found', 404);
        }

        return [$actorMembership, $target];
    }

    /** The owner is never managed; a team manager manages only members who have nothing the manager lacks. */
    private function assertManages(Membership $actor, Membership $target): void
    {
        if ($target->role === TenantRoles::OWNER) {
            throw new AccountRejected('team_forbidden', 403);
        }

        $this->assertManagesPermissions($actor, TenantPermissions::forMembership($target));
    }

    /**
     * @param  array<int, mixed>  $targetPermissions
     */
    private function assertManagesPermissions(?Membership $actor, array $targetPermissions): void
    {
        $assignable = $this->assignablePermissionsBy($actor);

        if ($assignable === [] || array_diff(TenantPermissions::normalize($targetPermissions), $assignable) !== []) {
            throw new AccountRejected('team_forbidden', 403);
        }
    }

    /**
     * @param  array<int, mixed>  $requested
     * @return list<string>
     */
    private function grantable(?Membership $actor, array $requested): array
    {
        $assignable = $this->assignablePermissionsBy($actor);

        if ($assignable === []) {
            throw new AccountRejected('team_forbidden', 403);
        }

        $unknown = array_diff(array_filter($requested, 'is_string'), TenantPermissions::assignable());
        $next = TenantPermissions::normalize($requested);

        if ($unknown !== [] || array_diff($next, $assignable) !== []) {
            throw new AccountRejected('permission_not_assignable');
        }

        return $next;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function storePermissions(string $tenantId, string $userId, array $permissions): void
    {
        Membership::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->update(['role' => TenantPermissions::roleFor($permissions), 'permissions' => json_encode($permissions)]);
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function notifyPermissionsChanged(Membership $membership, array $before, array $after): void
    {
        $user = $membership->user;

        if ($user === null) {
            return;
        }

        $locale = in_array($user->locale, AccountMailer::LOCALES, true) ? $user->locale : 'en';
        $names = fn (array $permissions): string => $permissions === []
            ? trans('account.permissions_changed.none', [], $locale)
            : implode("\n", array_map(fn (string $permission): string => '— '.trans('account.permissions.'.str_replace('.', '_', $permission), [], $locale), $permissions));

        try {
            $this->mailer->send($user->email, $locale, 'permissions_changed', [
                'tenant' => Tenant::query()->whereKey($membership->tenant_id)->value('name') ?? '',
                'added' => $names(array_values(array_diff($after, $before))),
                'removed' => $names(array_values(array_diff($before, $after))),
                'link' => $this->mailer->link('/app/settings/team'),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(string $tenantId, ?string $actorId, string $action, string $entityType, string $entityId, array $changes): void
    {
        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'store_id' => null,
            'actor_user_id' => $actorId,
            'actor_type' => $actorId === null ? AuditLog::ACTOR_SYSTEM : AuditLog::ACTOR_USER,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes,
            'request_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);
    }
}
