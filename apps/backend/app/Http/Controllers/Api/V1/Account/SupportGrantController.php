<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\PlatformStaff;
use App\Models\Store;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Support\Auth\TenantRoles;
use App\Support\Platform\PlatformCapabilities;
use App\Support\Platform\SupportGrants;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportGrantController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly SupportGrants $grants) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->ownerTenant($request);
        $grants = SupportAccessGrant::query()->where('tenant_id', $tenantId)->orderByDesc('created_at')->limit(100)->get();
        $staff = PlatformStaff::query()->with('user')->whereIn('id', $grants->pluck('staff_id'))->get()->keyBy('id');
        $stores = Store::query()->where('tenant_id', $tenantId)->whereIn('id', $grants->pluck('store_id'))->pluck('name', 'id');

        return response()->json(['data' => $grants->map(fn (SupportAccessGrant $grant): array => $this->dto($grant, $staff->get($grant->staff_id), $stores->get($grant->store_id)))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->ownerTenant($request);
        $validated = $request->validate([
            'store_id' => ['required', 'uuid'],
            'staff_email' => ['required', 'email', 'max:255'],
            'scopes' => ['required', 'array', 'min:1', 'max:3'],
            'scopes.*' => ['required', 'string', Rule::in(SupportAccessGrant::SCOPES)],
            'duration_hours' => ['sometimes', 'integer', 'between:1,72'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'case_reference' => ['prohibited'],
        ]);
        $email = mb_strtolower(trim($validated['staff_email']));
        $staff = PlatformStaff::query()->whereIn('user_id', User::query()->whereRaw('lower(email) = ?', [$email])->select('id'))->first();
        abort_unless($staff !== null && $staff->active() && PlatformCapabilities::allows($staff, PlatformCapabilities::TENANTS_SUPPORT), 422, 'support_staff_not_found');
        $grant = $this->grants->request($tenantId, $validated['store_id'], $staff, array_values($validated['scopes']), (int) ($validated['duration_hours'] ?? 24), trim($validated['reason']), $request->user());

        return response()->json($this->dto($grant, $staff->load('user'), Store::query()->whereKey($grant->store_id)->value('name')), 201);
    }

    public function approve(Request $request, string $grant): JsonResponse
    {
        return $this->decide($request, $grant, true);
    }

    public function deny(Request $request, string $grant): JsonResponse
    {
        return $this->decide($request, $grant, false);
    }

    public function revoke(Request $request, string $grant): JsonResponse
    {
        $tenantId = $this->ownerTenant($request);
        $model = SupportAccessGrant::query()->where('tenant_id', $tenantId)->whereKey($grant)->firstOrFail();
        $updated = $this->grants->revoke($model, $request->user());

        return response()->json($this->dto($updated, PlatformStaff::query()->with('user')->find($updated->staff_id), Store::query()->whereKey($updated->store_id)->value('name')));
    }

    public function activity(Request $request, string $grant): JsonResponse
    {
        $tenantId = $this->ownerTenant($request);
        $model = SupportAccessGrant::query()->where('tenant_id', $tenantId)->whereKey($grant)->firstOrFail();
        $entries = AuditLog::query()->where('tenant_id', $tenantId)->where('store_id', $model->store_id)
            ->where(function ($query) use ($model): void {
                $query->where('entity_id', $model->id)->orWhere('changes->grant_id', $model->id);
            })
            ->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $entries->map(fn (AuditLog $entry): array => [
            'id' => $entry->id,
            'action' => $entry->action,
            'at' => $entry->created_at?->toJSON(),
            'staff_id' => $entry->changes['staff_id'] ?? null,
            'target_type' => $entry->changes['target_type'] ?? null,
            'target_id' => $entry->changes['target_type'] ?? null ? $entry->entity_id : null,
        ])->all()]);
    }

    private function decide(Request $request, string $grant, bool $approve): JsonResponse
    {
        $tenantId = $this->ownerTenant($request);
        $model = SupportAccessGrant::query()->where('tenant_id', $tenantId)->whereKey($grant)->firstOrFail();
        $updated = $this->grants->decide($model, $request->user(), $approve);

        return response()->json($this->dto($updated, PlatformStaff::query()->with('user')->find($updated->staff_id), Store::query()->whereKey($updated->store_id)->value('name')));
    }

    private function ownerTenant(Request $request): string
    {
        $tenantId = $this->context->requireTenantId('support grant');
        abort_unless(Membership::query()->where('tenant_id', $tenantId)->where('user_id', $request->user()->id)->where('role', TenantRoles::OWNER)->exists(), 403);

        return $tenantId;
    }

    /** @return array<string, mixed> */
    private function dto(SupportAccessGrant $grant, ?PlatformStaff $staff, ?string $storeName): array
    {
        return [
            'id' => $grant->id, 'tenant_id' => $grant->tenant_id, 'store_id' => $grant->store_id, 'store_name' => $storeName,
            'staff_id' => $grant->staff_id,
            'staff' => $staff === null ? null : ['id' => $staff->id, 'name' => $staff->user?->name, 'email' => $staff->user?->email],
            'scopes' => $grant->scopes, 'state' => $grant->state === SupportAccessGrant::APPROVED && $grant->expires_at?->isPast() ? SupportAccessGrant::EXPIRED : $grant->state,
            'reason' => $grant->reason, 'case_reference' => $grant->case_reference,
            'duration_hours' => $grant->duration_hours, 'version' => $grant->version,
            'created_at' => $grant->created_at?->toJSON(), 'approved_at' => $grant->approved_at?->toJSON(),
            'expires_at' => $grant->expires_at?->toJSON(), 'revoked_at' => $grant->revoked_at?->toJSON(),
        ];
    }
}
