<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountRejected;
use App\Support\Account\MfaService;
use App\Support\Account\StepUp;
use App\Support\Account\TeamService;
use App\Support\Auth\TenantPermissions;
use App\Support\Auth\TenantRoles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function __construct(
        private readonly TeamService $team,
        private readonly MfaService $mfa,
    ) {}

    public function members(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list members');
        $actor = $this->team->membership($tenantId, $request->user()->id);
        $assignable = $this->team->assignablePermissionsBy($actor);

        return response()->json([
            'data' => $this->team->members($tenantId)->map(fn (Membership $membership): array => $this->member($membership, $request->user(), $assignable))->values()->all(),
            'assignable_roles' => $this->team->assignableBy($actor),
            'assignable_permissions' => $assignable,
            'permission_catalog' => [
                'sections' => TenantPermissions::SECTIONS,
                'owner_only' => TenantPermissions::OWNER_ONLY,
                'base' => TenantPermissions::BASE,
                'implies' => TenantPermissions::IMPLIES,
                'presets' => array_map(fn (array $preset): array => TenantPermissions::normalize($preset), TenantPermissions::PRESETS),
            ],
        ]);
    }

    /** Either a preset `role` (keeps the member's choice about amounts) or an exact `permissions` list (ADR 0079). */
    public function updateMember(Request $request, string $user, TenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate($this->permissionRules());
        $tenantId = $tenantContext->requireTenantId('change member permissions');

        try {
            $membership = isset($validated['permissions'])
                ? $this->team->changePermissions($tenantId, $request->user(), $user, $validated['permissions'])
                : $this->team->changeRole($tenantId, $request->user(), $user, $validated['role']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->member($membership, $request->user(), $this->team->assignablePermissionsBy($this->team->membership($tenantId, $request->user()->id))));
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

    public function resetMemberMfa(Request $request, string $user, TenantContext $tenantContext, StepUp $stepUp): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'current_password' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        $tenantId = $tenantContext->requireTenantId('reset member MFA');

        if ($user === $request->user()->id) {
            return $this->rejected(new AccountRejected('cannot_reset_own_mfa', 403));
        }

        $stepUp->require($request->user(), $validated['current_password'], $validated['code'] ?? null);

        try {
            $target = $this->mfa->resetForAssistance($tenantId, $user, $request->user(), 'tenant_owner', trim($validated['reason']));
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        $this->mfa->notifyReset($target);

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
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255'], ...$this->permissionRules()]);
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantContext->requireTenantId('invite member'));

        try {
            $invitation = $this->team->invite($tenant, $request->user(), $validated['email'], $validated['permissions'] ?? TenantPermissions::preset($validated['role'], true));
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->invitation($invitation), 201);
    }

    public function updateInvitation(Request $request, string $invitation, TenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate($this->permissionRules());
        $tenantId = $tenantContext->requireTenantId('change invitation permissions');
        $current = Invitation::query()->where('tenant_id', $tenantId)->whereKey($invitation)->first();
        $permissions = $validated['permissions']
            ?? TenantPermissions::preset($validated['role'], in_array(TenantPermissions::MONEY_VIEW, (array) $current?->permissions, true));

        try {
            $updated = $this->team->updateInvitation($tenantId, $request->user(), $invitation, $permissions);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->invitation($updated));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function permissionRules(): array
    {
        return [
            'role' => ['required_without:permissions', Rule::in(TeamService::ASSIGNABLE)],
            'permissions' => ['required_without:role', 'array', 'max:'.count(TenantPermissions::all())],
            'permissions.*' => ['string', 'distinct', Rule::in(TenantPermissions::all())],
        ];
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
            'permissions' => TenantPermissions::normalize((array) $invitation->permissions),
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

        return response()->json($this->member($membership, $request->user(), []));
    }

    /**
     * @param  list<string>  $assignable
     * @return array<string, mixed>
     */
    private function member(Membership $membership, User $viewer, array $assignable): array
    {
        $permissions = TenantPermissions::forMembership($membership);

        return [
            'user_id' => $membership->user_id,
            'tenant_id' => $membership->tenant_id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'email_verified' => $membership->user?->email_verified_at !== null,
            'mfa_enabled' => $membership->user?->mfa_confirmed_at !== null,
            'role' => $membership->role,
            'permissions' => $permissions,
            'can_edit' => $membership->role !== TenantRoles::OWNER && $membership->user_id !== $viewer->id
                && $assignable !== [] && array_diff($permissions, $assignable) === [],
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
            'permissions' => TenantPermissions::normalize((array) $invitation->permissions),
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
