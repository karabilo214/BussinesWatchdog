<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Support\Platform\SupportGrants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportGrantController extends Controller
{
    public function __construct(private readonly SupportGrants $grants) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['tenant_id' => ['required', 'uuid'], 'store_id' => ['sometimes', 'uuid']]);
        $tenant = Tenant::query()->whereKey($validated['tenant_id'])->whereNotIn('status', ['deleting', 'deleted'])->firstOrFail();
        $query = SupportAccessGrant::query()->where('tenant_id', $tenant->id)->where('staff_id', $this->staff($request)->id)->whereNull('staff_hidden_at');

        if (isset($validated['store_id'])) {
            $query->where('store_id', $validated['store_id']);
        }

        return response()->json(['data' => $query->orderByDesc('created_at')->limit(100)->get()->map(fn (SupportAccessGrant $grant): array => $this->dto($grant))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'store_id' => ['required', 'uuid'],
            'scopes' => ['required', 'array', 'min:1', 'max:3'],
            'scopes.*' => ['required', 'string', Rule::in(SupportAccessGrant::SCOPES)],
            'duration_hours' => ['sometimes', 'integer', 'between:1,72'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'case_reference' => ['prohibited'],
        ]);
        $tenant = Tenant::query()->whereKey($validated['tenant_id'])->whereNotIn('status', ['deleting', 'deleted'])->firstOrFail();
        $grant = $this->grants->request($tenant->id, $validated['store_id'], $this->staff($request), array_values($validated['scopes']), (int) ($validated['duration_hours'] ?? 24), trim($validated['reason']), null);

        return response()->json($this->dto($grant), 201);
    }

    public function cancel(Request $request, string $grant): JsonResponse
    {
        return response()->json($this->dto($this->grants->cancel($this->own($request, $grant), $this->staff($request))));
    }

    public function resend(Request $request, string $grant): JsonResponse
    {
        $model = $this->own($request, $grant);
        abort_if(Tenant::query()->whereKey($model->tenant_id)->whereIn('status', ['deleting', 'deleted'])->exists(), 404);
        $result = $this->grants->resend($model, $this->staff($request));

        return response()->json($this->dto($result), $result->id === $model->id ? 200 : 201);
    }

    public function hide(Request $request, string $grant): JsonResponse
    {
        $this->grants->hide($this->own($request, $grant), $this->staff($request));

        return response()->json(null, 204);
    }

    private function own(Request $request, string $grant): SupportAccessGrant
    {
        return SupportAccessGrant::query()->whereKey($grant)->where('staff_id', $this->staff($request)->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function dto(SupportAccessGrant $grant): array
    {
        return [
            'id' => $grant->id, 'tenant_id' => $grant->tenant_id, 'store_id' => $grant->store_id,
            'staff_id' => $grant->staff_id, 'scopes' => $grant->scopes, 'state' => $grant->state === SupportAccessGrant::APPROVED && $grant->expires_at?->isPast() ? SupportAccessGrant::EXPIRED : $grant->state,
            'reason' => $grant->reason, 'case_reference' => $grant->case_reference,
            'duration_hours' => $grant->duration_hours, 'version' => $grant->version,
            'created_at' => $grant->created_at?->toJSON(), 'approved_at' => $grant->approved_at?->toJSON(),
            'expires_at' => $grant->expires_at?->toJSON(), 'revoked_at' => $grant->revoked_at?->toJSON(),
        ];
    }

    private function staff(Request $request): PlatformStaff
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        return $staff;
    }
}
