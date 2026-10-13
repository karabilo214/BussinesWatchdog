<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Support\Account\AccountRejected;
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
