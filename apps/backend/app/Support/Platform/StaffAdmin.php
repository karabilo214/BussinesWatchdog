<?php

namespace App\Support\Platform;

use App\Models\PlatformStaff;
use App\Models\PlatformStaffInvitation;
use App\Models\User;
use App\Support\Account\AccountMailer;
use App\Support\Account\MfaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffAdmin
{
    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly AccountMailer $mailer,
        private readonly MfaService $mfa,
    ) {}

    /**
     * @return array{staff: list<array<string, mixed>>, invitations: list<array<string, mixed>>}
     */
    public function catalog(): array
    {
        $staff = PlatformStaff::query()->with('user')->orderBy('created_at')->get();
        $logins = DB::table('platform_audit_log')->where('action', 'staff.login')->where('result', PlatformAudit::RESULT_SUCCEEDED)->whereIn('actor_staff_id', $staff->pluck('id'))
            ->groupBy('actor_staff_id')->select('actor_staff_id', DB::raw('max(created_at) as last_login_at'))->pluck('last_login_at', 'actor_staff_id');
        $invitations = PlatformStaffInvitation::query()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', Carbon::now())->orderByDesc('created_at')->get();

        return [
            'staff' => $staff->map(fn (PlatformStaff $member): array => [
                'id' => $member->id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'role' => $member->role,
                'state' => $member->state,
                'mfa_enabled' => $member->user !== null && $this->mfa->enabled($member->user),
                'created_at' => $member->created_at?->toJSON(),
                'disabled_at' => $member->disabled_at?->toJSON(),
                'last_login_at' => isset($logins[$member->id]) ? Carbon::parse($logins[$member->id])->toJSON() : null,
            ])->values()->all(),
            'invitations' => $invitations->map(fn (PlatformStaffInvitation $invitation): array => $this->invitation($invitation))->values()->all(),
        ];
    }

    public function invite(PlatformStaff $actor, string $email, string $role, string $reason): PlatformStaffInvitation
    {
        $email = mb_strtolower(trim($email));

        if (! in_array($role, PlatformStaff::ROLES, true)) {
            throw new StaffRejected('role_invalid');
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null && PlatformStaff::query()->where('user_id', $user->id)->exists()) {
            throw new StaffRejected('already_staff', 409);
        }

        $token = Str::random(48);
        $invitation = DB::transaction(function () use ($actor, $email, $role, $token): PlatformStaffInvitation {
            PlatformStaffInvitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => Carbon::now()]);

            return PlatformStaffInvitation::query()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $actor->id,
                'expires_at' => Carbon::now()->addHours(PlatformStaffInvitation::TTL_HOURS),
            ]);
        });
        $locale = in_array($actor->user?->locale, AccountMailer::LOCALES, true) ? (string) $actor->user->locale : 'en';
        $this->mailer->send($email, $locale, 'staff_invitation', [
            'inviter' => (string) $actor->user?->name,
            'role' => trans("account.staff_roles.{$role}", [], $locale),
            'hours' => PlatformStaffInvitation::TTL_HOURS,
            'link' => rtrim((string) config('watchdog.site.owner_url'), '/').'/staff-invitation?'.http_build_query(['token' => $token]),
        ]);
        $this->audit->record($actor->id, 'staff.invited', 'staff_invitation', $invitation->id, PlatformAudit::RESULT_SUCCEEDED, ['role' => $role, 'email_hash' => hash('sha256', $email)], $reason);

        return $invitation;
    }

    public function revokeInvitation(PlatformStaff $actor, string $invitationId, string $reason): void
    {
        $invitation = PlatformStaffInvitation::query()->whereKey($invitationId)->first();

        if ($invitation === null || ! $invitation->pending()) {
            throw new StaffRejected('invitation_not_found', 404);
        }

        $invitation->forceFill(['revoked_at' => Carbon::now()])->save();
        $this->audit->record($actor->id, 'staff.invitation_revoked', 'staff_invitation', $invitation->id, PlatformAudit::RESULT_SUCCEEDED, ['role' => $invitation->role], $reason);
    }

    /**
     * @param  array{role?: string, state?: string}  $changes
     */
    public function update(PlatformStaff $actor, string $staffId, array $changes, string $reason): PlatformStaff
    {
        return DB::transaction(function () use ($actor, $staffId, $changes, $reason): PlatformStaff {
            /** @var PlatformStaff|null $member */
            $member = PlatformStaff::query()->whereKey($staffId)->lockForUpdate()->first();

            if ($member === null) {
                throw new StaffRejected('staff_not_found', 404);
            }

            if ($member->id === $actor->id) {
                throw new StaffRejected('self_change', 409);
            }

            $role = $changes['role'] ?? $member->role;
            $state = $changes['state'] ?? $member->state;

            if (! in_array($role, PlatformStaff::ROLES, true) || ! in_array($state, [PlatformStaff::STATE_ACTIVE, PlatformStaff::STATE_DISABLED], true)) {
                throw new StaffRejected('role_invalid');
            }

            $losesOwner = $member->role === PlatformStaff::ROLE_OWNER && $member->active() && ($role !== PlatformStaff::ROLE_OWNER || $state !== PlatformStaff::STATE_ACTIVE);

            if ($losesOwner && PlatformStaff::query()->where('role', PlatformStaff::ROLE_OWNER)->where('state', PlatformStaff::STATE_ACTIVE)->whereKeyNot($member->id)->lockForUpdate()->pluck('id')->isEmpty()) {
                throw new StaffRejected('last_owner', 409);
            }

            $before = ['role' => $member->role, 'state' => $member->state];
            $member->forceFill([
                'role' => $role,
                'state' => $state,
                'disabled_at' => $state === PlatformStaff::STATE_DISABLED ? ($member->disabled_at ?? Carbon::now()) : null,
                'permission_version' => $member->permission_version + 1,
            ])->save();
            $this->audit->record($actor->id, 'staff.updated', 'staff', $member->id, PlatformAudit::RESULT_SUCCEEDED, ['before' => $before, 'after' => ['role' => $role, 'state' => $state]], $reason);

            return $member;
        });
    }

    public function revokeSessions(PlatformStaff $actor, string $staffId, string $reason): void
    {
        $member = PlatformStaff::query()->whereKey($staffId)->first();

        if ($member === null) {
            throw new StaffRejected('staff_not_found', 404);
        }

        if ($member->id === $actor->id) {
            throw new StaffRejected('self_change', 409);
        }

        $member->forceFill(['permission_version' => $member->permission_version + 1])->save();
        $this->audit->record($actor->id, 'staff.sessions_revoked', 'staff', $member->id, PlatformAudit::RESULT_SUCCEEDED, [], $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function invitation(PlatformStaffInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toJSON(),
            'created_at' => $invitation->created_at?->toJSON(),
        ];
    }
}
