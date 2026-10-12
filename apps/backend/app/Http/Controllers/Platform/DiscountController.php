<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Support\Account\MfaService;
use App\Support\Billing\DiscountAdmin;
use App\Support\Billing\DiscountLedger;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\PlanAdminRejected;
use App\Support\Platform\PlatformAudit;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiscountController extends Controller
{
    public function __construct(
        private readonly DiscountAdmin $discounts,
        private readonly MfaService $mfa,
        private readonly PlatformAudit $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->discounts->discounts(), 'mode' => PaddleConfig::mode()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules() + [
            'audience' => ['required', 'string'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'kind' => ['required', 'string'],
            'percent_bps' => ['sometimes', 'nullable', 'integer'],
            'amount_minor' => ['sometimes', 'nullable', 'string', 'max:12'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'duration' => ['required', 'string'],
            'duration_periods' => ['sometimes', 'nullable', 'integer'],
        ]);

        return $this->confirmed($request, 'discount.created', 'new', fn (PlatformStaff $staff, string $reason): JsonResponse => response()->json($this->discounts->dto($this->discounts->create($staff->id, $validated, $reason)), 201));
    }

    public function update(Request $request, string $discount): JsonResponse
    {
        $validated = $request->validate($this->rules());

        return $this->confirmed($request, 'discount.updated', $discount, fn (PlatformStaff $staff, string $reason): JsonResponse => response()->json($this->discounts->dto($this->discounts->update($staff->id, $discount, $validated, $reason))));
    }

    public function archive(Request $request, string $discount): JsonResponse
    {
        return $this->confirmed($request, 'discount.archived', $discount, fn (PlatformStaff $staff, string $reason): JsonResponse => response()->json($this->discounts->dto($this->discounts->archive($staff->id, $discount, $reason))));
    }

    public function applications(Request $request, DiscountLedger $ledger): JsonResponse
    {
        $validated = $request->validate(['tenant_id' => ['sometimes', 'uuid'], 'cursor' => ['sometimes', 'uuid']]);
        $rows = DB::table('billing_discount_applications')
            ->join('tenants', 'tenants.id', '=', 'billing_discount_applications.tenant_id')
            ->join('billing_checkout_sessions', 'billing_checkout_sessions.id', '=', 'billing_discount_applications.checkout_session_id')
            ->where(fn ($query) => $query->where('billing_discount_applications.period_number', '>', 1)->orWhere('billing_checkout_sessions.status', 'confirmed'))
            ->when(isset($validated['tenant_id']), fn ($query) => $query->where('billing_discount_applications.tenant_id', $validated['tenant_id']))
            ->when(isset($validated['cursor']), fn ($query) => $query->where('billing_discount_applications.id', '<', $validated['cursor']))
            ->orderByDesc('billing_discount_applications.id')
            ->limit(51)
            ->get(['billing_discount_applications.*', 'tenants.name as tenant_name']);
        $page = $rows->take(50);

        return response()->json([
            'data' => $page->map(fn (object $row): array => $ledger->breakdown($row) + ['tenant' => ['id' => $row->tenant_id, 'name' => $row->tenant_name], 'created_at' => Carbon::parse($row->created_at)->toJSON(), 'applied_at' => $row->applied_at === null ? null : Carbon::parse($row->applied_at)->toJSON()])->values()->all(),
            'next_cursor' => $rows->count() > 50 ? $page->last()->id : null,
        ]);
    }

    public function tenants(Request $request): JsonResponse
    {
        $validated = $request->validate(['query' => ['required', 'string', 'min:2', 'max:100']]);
        $query = trim($validated['query']);
        $tenants = Tenant::query()
            ->where(fn ($builder) => $builder->whereRaw('lower(name) like ?', ['%'.mb_strtolower($query).'%'])->when(Str::isUuid($query), fn ($match) => $match->orWhere('id', $query)))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'created_at']);

        return response()->json(['data' => $tenants->map(fn (Tenant $tenant): array => ['id' => $tenant->id, 'name' => $tenant->name, 'created_at' => $tenant->created_at?->toJSON()])->values()->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'label' => ['sometimes', 'array'],
            'label.*' => ['nullable', 'string', 'max:120'],
            'plan_ids' => ['sometimes', 'array'],
            'plan_ids.*' => ['uuid'],
            'tenant_ids' => ['sometimes', 'array'],
            'tenant_ids.*' => ['uuid'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'usage_limit' => ['sometimes', 'nullable', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmation_code' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @param  Closure(PlatformStaff, string): JsonResponse  $action
     */
    private function confirmed(Request $request, string $auditAction, string $target, Closure $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], 'confirmation_code' => ['required', 'string', 'max:32']]);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        if (! $this->mfa->verify($staff->user, $validated['confirmation_code'])) {
            $this->audit->record($staff->id, $auditAction, 'billing_discount', $target, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up']);

            return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
        }

        try {
            return $action($staff, $validated['reason']);
        } catch (PlanAdminRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The discount change was rejected.'], $exception->status);
        }
    }
}
