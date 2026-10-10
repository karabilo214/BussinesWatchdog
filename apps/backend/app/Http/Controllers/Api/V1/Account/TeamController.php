<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountRejected;
use App\Support\Account\StepUp;
use App\Support\Account\TeamService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function __construct(
        private readonly TeamService $team,
    ) {}

    public function members(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list members');
        $actorRole = (string) $this->team->roleOf($tenantId, $request->user()->id);

        return response()->json([
            'data' => $this->team->members($tenantId)->map(fn (Membership $membership): array => $this->member($membership, $request->user()))->values()->all(),
            'assignable_roles' => $this->team->assignableBy($actorRole),
        ]);
    }

    public function updateMember(Request $request, string $user, TenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::in(TeamService::ASSIGNABLE)]]);

        try {
            $membership = $this->team->changeRole($tenantContext->requireTenantId('change member role'), $request->user(), $user, $validated['role']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->member($membership, $request->user()));
    }

    public function transferOwnership(Request $request, TenantContext $tenantContext, StepUp $stepUp): JsonResponse
    {
        $validated = $request->validate([
            'target_user_id' => ['required', 'string', 'uuid'],
            'current_password' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        $tenantId = $tenantContext->requireTenantId('transfer ownership');
        $stepUp->require($request->user(), $validated['current_password'], $validated['code'] ?? null);

        try {
            $this->team->transferOwnership($tenantId, $request->user(), $validated['target_user_id']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json(null, 204);
    }

    public function removeMember(Request $request, string $user, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('remove member');

        try {
            $this->team->remove($tenantId, $request->user(), $user);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        if ($user === $request->user()->id) {
            $request->session()->forget('active_tenant_id');
        }

        return response()->json(null, 204);
    }

    public function invitations(TenantContext $tenantContext): JsonResponse
    {
        return response()->json([
            'data' => $this->team->pendingInvitations($tenantContext->requireTenantId('list invitations'))->map(fn (Invitation $invitation): array => $this->invitation($invitation))->values()->all(),
        ]);
    }

    public function invite(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(TeamService::ASSIGNABLE)],
        ]);
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantContext->requireTenantId('invite member'));

        try {
            $invitation = $this->team->invite($tenant, $request->user(), $validated['email'], $validated['role']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->invitation($invitation), 201);
    }

    public function revoke(Request $request, string $invitation, TenantContext $tenantContext): JsonResponse
    {
        try {
            $revoked = $this->team->revoke($tenantContext->requireTenantId('revoke invitation'), $request->user(), $invitation);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->invitation($revoked));
    }

    /** Public: what the invitation link offers, so the page can show it before sign-in. */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:128']]);

        try {
            $invitation = $this->team->findPending($validated['token']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json([
            'tenant_name' => $invitation->tenant->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toJSON(),
            'account_exists' => User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:128']]);

        try {
            $membership = $this->team->accept($request->user(), $validated['token']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        $request->session()->put('active_tenant_id', $membership->tenant_id);

        return response()->json($this->member($membership, $request->user()));
    }

    /**
     * @return array<string, mixed>
     */
    private function member(Membership $membership, User $viewer): array
    {
        return [
            'user_id' => $membership->user_id,
            'tenant_id' => $membership->tenant_id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'email_verified' => $membership->user?->email_verified_at !== null,
            'role' => $membership->role,
            'is_you' => $membership->user_id === $viewer->id,
            'created_at' => $membership->created_at?->toJSON(),
        ];
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
            'state' => match (true) {
                $invitation->accepted_at !== null => 'accepted',
                $invitation->revoked_at !== null => 'revoked',
                $invitation->expires_at->isPast() => 'expired',
                default => 'pending',
            },
            'invited_by' => $invitation->inviter?->name,
            'expires_at' => $invitation->expires_at->toJSON(),
            'created_at' => $invitation->created_at?->toJSON(),
        ];
    }

    private function rejected(AccountRejected $exception): JsonResponse
    {
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
    }
}
