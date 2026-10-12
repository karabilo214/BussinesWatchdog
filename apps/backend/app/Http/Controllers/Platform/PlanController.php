<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingPlanVersion;
use App\Models\PlatformStaff;
use App\Support\Account\MfaService;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\PlanAdmin;
use App\Support\Billing\PlanAdminRejected;
use App\Support\Platform\PlatformAudit;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlanController extends Controller
{
    public function __construct(
        private readonly PlanAdmin $plans,
        private readonly MfaService $mfa,
        private readonly PlatformAudit $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->plans->plans(),
            'mode' => PaddleConfig::mode(),
            'provider_configured' => PaddleConfig::configured(),
            'catalog' => [
                'features' => FeatureCatalog::FEATURES,
                'limits' => FeatureCatalog::LIMITS,
                'per_store_limits' => FeatureCatalog::PER_STORE_LIMITS,
                'currencies' => PlanAdmin::CURRENCIES,
                'intervals' => PlanAdmin::INTERVALS,
                'max_interval_count' => PlanAdmin::MAX_INTERVAL_COUNT,
                'max_trial_days' => PlanAdmin::MAX_TRIAL_DAYS,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->run(fn (): JsonResponse => response()->json($this->dto($this->plans->createPlan($this->staff($request)->id, $validated['code'], $validated['name'])), 201));
    }

    public function update(Request $request, string $version): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'array'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'array'],
            'description.*' => ['nullable', 'string', 'max:500'],
            'trial_days' => ['sometimes', 'integer'],
            'entitlements' => ['sometimes', 'array'],
            'entitlements.features' => ['sometimes', 'array'],
            'entitlements.limits' => ['sometimes', 'array'],
        ]);

        return $this->run(fn (): JsonResponse => response()->json($this->dto($this->plans->updateDraft($this->staff($request)->id, $version, $validated))));
    }

    public function addPrice(Request $request, string $version): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'amount_minor' => ['required', 'string', 'max:12'],
            'interval' => ['required', 'string'],
            'interval_count' => ['required', 'integer'],
        ]);

        return $this->run(function () use ($request, $version, $validated): JsonResponse {
            $this->plans->addPrice($this->staff($request)->id, $version, $validated['kind'], $validated['currency'], $validated['amount_minor'], $validated['interval'], (int) $validated['interval_count']);

            return response()->json($this->dto(BillingPlanVersion::query()->findOrFail($version)), 201);
        });
    }

    public function removePrice(Request $request, string $price): Response|JsonResponse
    {
        return $this->run(function () use ($request, $price): Response {
            $this->plans->removePrice($this->staff($request)->id, $price);

            return response()->noContent();
        });
    }

    public function newVersion(Request $request, string $plan): JsonResponse
    {
        return $this->run(fn (): JsonResponse => response()->json($this->dto($this->plans->newVersion($this->staff($request)->id, $plan)), 201));
    }

    public function publish(Request $request, string $version): JsonResponse
    {
        return $this->confirmed($request, 'plan_version.published', $version, fn (string $reason): JsonResponse => response()->json($this->dto($this->plans->publish($this->staff($request)->id, $version, $reason))));
    }

    public function retire(Request $request, string $version): JsonResponse
    {
        return $this->confirmed($request, 'plan_version.retired', $version, fn (string $reason): JsonResponse => response()->json($this->dto($this->plans->retire($this->staff($request)->id, $version, $reason))));
    }

    /**
     * @param  Closure(string): JsonResponse  $action
     */
    private function confirmed(Request $request, string $auditAction, string $version, Closure $action): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $staff = $this->staff($request);

        if (! $this->mfa->verify($staff->user, $validated['code'])) {
            $this->audit->record($staff->id, $auditAction, 'billing_plan_version', $version, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up']);

            return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
        }

        return $this->run(fn (): JsonResponse => $action($validated['reason']));
    }

    /**
     * @param  Closure(): (JsonResponse|Response)  $action
     */
    private function run(Closure $action): JsonResponse|Response
    {
        try {
            return $action();
        } catch (PlanAdminRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The plan change was rejected.'], $exception->status);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dto(BillingPlanVersion $version): array
    {
        return $this->plans->versionDto($version, 0) + ['plan_id' => $version->plan_id];
    }

    private function staff(Request $request): PlatformStaff
    {
        return $request->attributes->get('platform_staff');
    }
}
