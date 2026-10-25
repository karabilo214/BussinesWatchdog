<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountRejected;
use App\Support\Account\MfaService;
use App\Support\Account\PasswordResetService;
use App\Support\Account\TeamService;
use App\Support\Platform\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantTeamController extends Controller
{
    public function __construct(
        private readonly TeamService $team,
        private readonly PlatformAudit $audit,
        private readonly MfaService $mfa,
        private readonly PasswordResetService $passwordResets,
    ) {}

    public function invitations(string $tenant): JsonResponse
    {
        $model = $this->tenant($tenant);

        if ($model === null) {
            return $this->missing();
        }

        return response()->json(['data' => $this->team->pendingInvitations($model->id)->map(fn (Invitation $invitation): array => $this->invitation($invitation))->values()->all()]);
    }

    public function invite(Request $request, string $tenant): JsonResponse
    {
        $model = $this->tenant($tenant);

        if ($model === null) {
            return $this->missing();
        }

        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(TeamService::ASSIGNABLE)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $staff = $this->staff($request);

        try {
            $invitation = $this->team->inviteForPlatform($model, $staff->user, $validated['email'], $validated['role']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        $this->audit->record($staff->id, 'tenant.member_invited', 'invitation', $invitation->id, PlatformAudit::RESULT_SUCCEEDED, ['role' => $validated['role']], trim($validated['reason']), $model->id);

        return response()->json($this->invitation($invitation), 201);
    }

    public function revoke(Request $request, string $tenant, string $invitation): JsonResponse
    {
        $model = $this->tenant($tenant);

        if ($model === null || ! Str::isUuid($invitation)) {
            return $this->missing();
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            $revoked = $this->team->revokeForPlatform($model->id, $invitation);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        $this->audit->record($this->staff($request)->id, 'tenant.invitation_revoked', 'invitation', $revoked->id, PlatformAudit::RESULT_SUCCEEDED, ['role' => $revoked->role, 'before' => ['revoked_at' => null], 'after' => ['revoked_at' => $revoked->revoked_at?->toJSON()]], trim($validated['reason']), $model->id);

        return response()->json($this->invitation($revoked));
    }

    public function changeRole(Request $request, string $tenant, string $user): JsonResponse
    {
        $model = $this->tenant($tenant);

        if ($model === null || ! Str::isUuid($user)) {
            return $this->missing();
        }

        $validated = $request->validate(['role' => ['required', 'string'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $previousRole = Membership::query()->where('tenant_id', $model->id)->where('user_id', $user)->value('role');

        try {
            $member = $this->team->changeRoleForPlatform($model->id, $user, $validated['role'], trim($validated['reason']));
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        $this->audit->record($this->staff($request)->id, 'tenant.member_role_changed', 'user', $user, PlatformAudit::RESULT_SUCCEEDED, ['before' => ['role' => $previousRole], 'after' => ['role' => $member->role]], trim($validated['reason']), $model->id);

        return response()->json(['user_id' => $user, 'role' => $member->role]);
    }

    public function removeMember(Request $request, string $tenant, string $user): JsonResponse
    {
        $model = $this->tenant($tenant);

        if ($model === null || ! Str::isUuid($user)) {
            return $this->missing();
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            $removed = $this->team->removeForPlatform($model->id, $user, trim($validated['reason']));
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        $this->audit->record($this->staff($request)->id, 'tenant.member_removed', 'user', $user, PlatformAudit::RESULT_SUCCEEDED, ['before' => ['role' => $removed->role], 'after' => ['role' => null]], trim($validated['reason']), $model->id);

        return response()->json(null, 204);
    }

    public function resetMfa(Request $request, string $tenant, string $user): JsonResponse
    {
        $model = $this->tenant($tenant);
        if ($model === null || ! Str::isUuid($user)) {
            return $this->missing();
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmation_code' => ['required', 'string', 'max:32'],
        ]);
        $staff = $this->staff($request);
        $action = 'tenant.member_mfa_reset';

        // Owner decision 2026-10-24: switching off a customer's second factor is reserved for the service owner.
        if ($staff->role !== PlatformStaff::ROLE_OWNER) {
            $this->audit->record($staff->id, $action, 'user', $user, PlatformAudit::RESULT_DENIED, ['stage' => 'owner_only'], null, $model->id);

            return response()->json(['code' => 'owner_only', 'message' => 'Only the service owner can reset a customer\'s MFA.'], 403);
        }

        if (! $this->mfa->verify($staff->user, $validated['confirmation_code'])) {
            $this->audit->record($staff->id, $action, 'user', $user, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up'], null, $model->id);

            return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
        }

        $mfaBefore = User::query()->whereKey($user)->first();
        $mfaBefore = $mfaBefore === null ? null : $this->mfa->enabled($mfaBefore);

        try {
            $target = $this->mfa->resetForAssistance($model->id, $user, null, 'platform_staff', trim($validated['reason']));
        } catch (AccountRejected $exception) {
            $this->audit->record($staff->id, $action, 'user', $user, PlatformAudit::RESULT_DENIED, ['code' => $exception->reasonCode], trim($validated['reason']), $model->id);

            return $this->rejected($exception);
        }

        $this->audit->record($staff->id, $action, 'user', $user, PlatformAudit::RESULT_SUCCEEDED, ['sessions_revoked' => true, 'before' => ['mfa_enabled' => $mfaBefore], 'after' => ['mfa_enabled' => false]], trim($validated['reason']), $model->id);
        $this->mfa->notifyReset($target);

        return response()->json(null, 204);
    }

    /**
     * Sends the member the standard password reset e-mail. Staff never see or set the password; the link goes only to
     * the member's own verified address.
     */
    public function sendPasswordReset(Request $request, string $tenant, string $user): JsonResponse
    {
        $model = $this->tenant($tenant);
        if ($model === null || ! Str::isUuid($user)) {
            return $this->missing();
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $member = Membership::query()->where('tenant_id', $model->id)->where('user_id', $user)->with('user')->first();

        if ($member === null || $member->user === null) {
            return $this->missing();
        }

        $this->passwordResets->request(mb_strtolower((string) $member->user->email));
        $this->audit->record($this->staff($request)->id, 'tenant.member_password_reset_sent', 'user', $user, PlatformAudit::RESULT_SUCCEEDED, ['role' => $member->role], trim($validated['reason']), $model->id);

        return response()->json(null, 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function invitation(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toJSON(),
            'created_at' => $invitation->created_at?->toJSON(),
            'revoked_at' => $invitation->revoked_at?->toJSON(),
        ];
    }

    private function tenant(string $tenant): ?Tenant
    {
        return Str::isUuid($tenant) ? Tenant::query()->whereKey($tenant)->whereNotIn('status', ['deleting', 'deleted'])->first() : null;
    }

    private function staff(Request $request): PlatformStaff
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        return $staff;
    }

    private function missing(): JsonResponse
    {
        return response()->json(['code' => 'not_found', 'message' => 'Not found.'], 404);
    }

    private function rejected(AccountRejected $exception): JsonResponse
    {
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The team change was rejected.'], $exception->status);
    }
}
