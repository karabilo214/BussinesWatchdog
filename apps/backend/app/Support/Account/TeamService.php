<?php

namespace App\Support\Account;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Team rules (ADR 0019): owners and admins manage the team; an admin manages only operators and viewers;
 * nobody changes their own role; the owner role is never assigned here and the owner cannot leave.
 */
class TeamService
{
    public const INVITATION_TTL_DAYS = 7;

    public const ASSIGNABLE = [TenantRoles::ADMIN, TenantRoles::OPERATOR, TenantRoles::VIEWER];

    public function __construct(
        private readonly AccountMailer $mailer,
    ) {}

    /**
     * @return list<string>
     */
    public function assignableBy(string $actorRole): array
    {
        return match ($actorRole) {
            TenantRoles::OWNER => self::ASSIGNABLE,
            TenantRoles::ADMIN => [TenantRoles::OPERATOR, TenantRoles::VIEWER],
            default => [],
        };
    }

    public function roleOf(string $tenantId, string $userId): ?string
    {
        return Membership::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->value('role');
    }

    /**
     * @return Collection<int, Membership>
     */
    public function members(string $tenantId): Collection
    {
        return Membership::query()->where('tenant_id', $tenantId)->with('user')->orderBy('created_at')->get();
    }

    public function changeRole(string $tenantId, User $actor, string $targetUserId, string $role): Membership
    {
        return DB::transaction(function () use ($tenantId, $actor, $targetUserId, $role): Membership {
            [$actorRole, $target] = $this->lockPair($tenantId, $actor, $targetUserId);

            if ($target->user_id === $actor->id) {
                throw new AccountRejected('cannot_change_own_role');
            }

            $this->assertManages($actorRole, $target->role);

            if (! in_array($role, $this->assignableBy($actorRole), true)) {
                throw new AccountRejected('role_not_assignable');
            }

            $previous = $target->role;
            Membership::query()->where('tenant_id', $tenantId)->where('user_id', $targetUserId)->update(['role' => $role]);
            $this->audit($tenantId, $actor->id, 'membership.role_changed', 'membership', $targetUserId, ['role' => ['from' => $previous, 'to' => $role]]);

            return Membership::query()->where('tenant_id', $tenantId)->where('user_id', $targetUserId)->with('user')->firstOrFail();
        });
    }

    public function remove(string $tenantId, User $actor, string $targetUserId): void
    {
        DB::transaction(function () use ($tenantId, $actor, $targetUserId): void {
            [$actorRole, $target] = $this->lockPair($tenantId, $actor, $targetUserId);

            if ($target->role === TenantRoles::OWNER) {
                throw new AccountRejected($target->user_id === $actor->id ? 'owner_cannot_leave' : 'team_forbidden', $target->user_id === $actor->id ? 422 : 403);
            }

            if ($target->user_id !== $actor->id) {
                $this->assertManages($actorRole, $target->role);
            }

            Membership::query()->where('tenant_id', $tenantId)->where('user_id', $targetUserId)->delete();
            $this->audit($tenantId, $actor->id, $target->user_id === $actor->id ? 'membership.left' : 'membership.removed', 'membership', $targetUserId, ['role' => $target->role]);
        });
    }

    /**
     * The owner hands the team to a member with a confirmed address and stays as admin; both changes happen together,
     * so the team always has exactly one owner (ADR 0021). Re-authentication is checked by the caller.
     */
    public function transferOwnership(string $tenantId, User $actor, string $targetUserId): Membership
    {
        return DB::transaction(function () use ($tenantId, $actor, $targetUserId): Membership {
            [$actorRole, $target] = $this->lockPair($tenantId, $actor, $targetUserId);

            if ($actorRole !== TenantRoles::OWNER) {
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

            Membership::query()->where('tenant_id', $tenantId)->where('user_id', $actor->id)->update(['role' => TenantRoles::ADMIN]);
            Membership::query()->where('tenant_id', $tenantId)->where('user_id', $target->user_id)->update(['role' => TenantRoles::OWNER]);
            $this->audit($tenantId, $actor->id, 'membership.ownership_transferred', 'membership', $target->user_id, [
                'owner' => ['from' => $actor->id, 'to' => $target->user_id],
                'previous_owner_role' => TenantRoles::ADMIN,
                'new_owner_previous_role' => $target->role,
            ]);

            return Membership::query()->where('tenant_id', $tenantId)->where('user_id', $target->user_id)->with('user')->firstOrFail();
        });
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

    public function invite(Tenant $tenant, User $actor, string $email, string $role): Invitation
    {
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($tenant, $actor, $email, $role, $token): Invitation {
            $actorRole = $this->roleOf($tenant->id, $actor->id);

            if (! in_array($role, $this->assignableBy((string) $actorRole), true)) {
                throw new AccountRejected('role_not_assignable');
            }

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
                'token_hash' => hash('sha256', $token),
                'invited_by' => $actor->id,
                'expires_at' => Carbon::now()->addDays(self::INVITATION_TTL_DAYS),
            ]);
            $this->audit($tenant->id, $actor->id, 'invitation.created', 'invitation', $invitation->id, ['role' => $role]);

            return $invitation;
        });

        $locale = (string) ($tenant->locale ?? $actor->locale);
        $this->mailer->send($email, $locale, 'invitation', [
            'inviter' => $actor->name,
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

            $this->assertManages((string) $this->roleOf($tenantId, $actor->id), $invitation->role);

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

            Membership::query()->create(['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id, 'role' => $invitation->role]);
            $invitation->forceFill(['accepted_at' => Carbon::now()])->save();

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => Carbon::now()])->save();
            }

            $this->audit($invitation->tenant_id, $user->id, 'invitation.accepted', 'invitation', $invitation->id, ['role' => $invitation->role]);

            return Membership::query()->where('tenant_id', $invitation->tenant_id)->where('user_id', $user->id)->with('user')->firstOrFail();
        });
    }

    /**
     * @return array{0: string, 1: Membership}
     */
    private function lockPair(string $tenantId, User $actor, string $targetUserId): array
    {
        $actorRole = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $actor->id)->lockForUpdate()->value('role');
        /** @var Membership|null $target */
        $target = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $targetUserId)->lockForUpdate()->first();

        if ($actorRole === null || $target === null) {
            throw new AccountRejected('member_not_found', 404);
        }

        return [$actorRole, $target];
    }

    private function assertManages(string $actorRole, string $targetRole): void
    {
        $manages = match ($actorRole) {
            TenantRoles::OWNER => $targetRole !== TenantRoles::OWNER,
            TenantRoles::ADMIN => in_array($targetRole, [TenantRoles::OPERATOR, TenantRoles::VIEWER], true),
            default => false,
        };

        if (! $manages) {
            throw new AccountRejected('team_forbidden', 403);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(string $tenantId, string $actorId, string $action, string $entityType, string $entityId, array $changes): void
    {
        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'store_id' => null,
            'actor_user_id' => $actorId,
            'actor_type' => AuditLog::ACTOR_USER,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes,
            'request_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);
    }
}
