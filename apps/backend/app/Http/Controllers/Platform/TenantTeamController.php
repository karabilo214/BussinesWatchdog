<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Support\Account\AccountRejected;
use App\Support\Account\MfaService;
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

        $this->audit->record($this->staff($request)->id, 'tenant.invitation_revoked', 'invitation', $revoked->id, PlatformAudit::RESULT_SUCCEEDED, ['role' => $revoked->role], trim($validated['reason']), $model->id);

        return response()->json($this->invitation($revoked));
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

        if (! $this->mfa->verify($staff->user, $validated['confirmation_code'])) {
            $this->audit->record($staff->id, $action, 'user', $user, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up'], null, $model->id);

            return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
        }

        try {
            $target = $this->mfa->resetForAssistance($model->id, $user, null, 'platform_staff', trim($validated['reason']));
        } catch (AccountRejected $exception) {
            $this->audit->record($staff->id, $action, 'user', $user, PlatformAudit::RESULT_DENIED, ['code' => $exception->reasonCode], trim($validated['reason']), $model->id);

            return $this->rejected($exception);
        }

        $this->audit->record($staff->id, $action, 'user', $user, PlatformAudit::RESULT_SUCCEEDED, ['sessions_revoked' => true], trim($validated['reason']), $model->id);
        $this->mfa->notifyReset($target);

        return response()->json(null, 204);
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
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The invitation change was rejected.'], $exception->status);
    }
}
