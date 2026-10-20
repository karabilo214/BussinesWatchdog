<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Controllers\Controller;
use App\Models\DataDeletionRequest;
use App\Models\Membership;
use App\Models\Tenant;
use App\Support\Account\StepUp;
use App\Support\Auth\TenantRoles;
use App\Support\Privacy\DeletionRejected;
use App\Support\Privacy\OrganizationDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner deletes the active organization (ADR 0069). These routes resolve the organization from the session
 * themselves, because a tenant being deleted has no tenant context any more and these are the only actions left.
 */
class OrganizationDeletionController extends Controller
{
    public function __construct(
        private readonly OrganizationDeletion $deletion,
    ) {}

    public function show(Request $request): JsonResponse
    {
        [$tenant, $role] = $this->activeTenant($request);

        if ($tenant === null) {
            return $this->forbidden();
        }

        return response()->json($this->state($tenant, $role));
    }

    public function store(Request $request, StepUp $stepUp): JsonResponse
    {
        [$tenant, $role] = $this->activeTenant($request);

        if ($tenant === null || $role !== TenantRoles::OWNER) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'current_password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        $stepUp->require($request->user(), $validated['current_password'], $validated['code'] ?? null);

        if (trim($validated['organization_name']) !== trim($tenant->name)) {
            return response()->json(['code' => 'organization_name_mismatch', 'message' => 'Type the organization name exactly to confirm.'], 422);
        }

        try {
            $this->deletion->request($tenant, $request->user()->id, DataDeletionRequest::VIA_OWNER, null);
        } catch (DeletionRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->state($tenant->refresh(), $role), 202);
    }

    public function cancel(Request $request, StepUp $stepUp): JsonResponse
    {
        [$tenant, $role] = $this->activeTenant($request);

        if ($tenant === null || $role !== TenantRoles::OWNER) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        $stepUp->require($request->user(), $validated['current_password'], $validated['code'] ?? null);

        try {
            $this->deletion->cancel($tenant, $request->user()->id);
        } catch (DeletionRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->state($tenant->refresh(), $role));
    }

    /**
     * @return array{?Tenant, ?string}
     */
    private function activeTenant(Request $request): array
    {
        $tenantId = $request->hasSession() ? $request->session()->get('active_tenant_id') : null;

        if (! is_string($tenantId)) {
            return [null, null];
        }

        $role = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $request->user()->id)->value('role');

        return $role === null ? [null, null] : [Tenant::query()->whereKey($tenantId)->first(), $role];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Tenant $tenant, string $role): array
    {
        $open = $this->deletion->open($tenant->id);

        return [
            'tenant_status' => $tenant->status,
            'can_request' => $role === TenantRoles::OWNER && $open === null && ! in_array($tenant->status, OrganizationDeletion::DELETING_STATUSES, true),
            'request' => $open === null ? null : [
                'id' => $open->id,
                'status' => $open->status,
                'requested_via' => $open->requested_via,
                'requested_at' => $open->requested_at->toJSON(),
                'purge_after' => $open->purge_after->toJSON(),
                'can_cancel' => $role === TenantRoles::OWNER && $open->status === DataDeletionRequest::STATUS_REQUESTED,
            ],
        ];
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['code' => 'tenant_forbidden', 'message' => 'No access to an active team.'], 403);
    }

    private function rejected(DeletionRejected $exception): JsonResponse
    {
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The deletion request was refused.'], $exception->status);
    }
}
