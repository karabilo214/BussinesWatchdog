<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\Billing\Concerns\RespondsToBilling;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\Paddle\PaddleRefunds;
use App\Support\Billing\Paddle\PaddleSubscriptionActions;
use App\Support\Billing\StoreCapacity;
use App\Support\Site\Policies;
use App\Support\Site\PolicyAcceptanceRequired;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The tenant owner's subscription (platform-billing-api.yaml): read it, change the plan, cancel, resume, choose stores.
 */
class SubscriptionController extends Controller
{
    use RespondsToBilling;

    public function __construct(private readonly Policies $policies) {}

    public function show(TenantContext $tenantContext, EntitlementCalculator $calculator): JsonResponse|Response
    {
        $tenantId = $tenantContext->requireTenantId('read subscription');
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();

        return $subscription === null ? response()->noContent() : response()->json($this->subscriptionDto($subscription, $calculator));
    }

    public function previewPlanChange(Request $request, TenantContext $tenantContext, PaddleSubscriptionActions $actions): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('preview plan change');
        $validated = $request->validate([
            'price_id' => ['required', 'uuid'],
            'extra_stores' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'retained_store_ids' => ['sometimes', 'nullable', 'array', 'max:1000'],
            'retained_store_ids.*' => ['string', 'uuid'],
            'faster_checks' => ['sometimes', 'boolean'],
        ]);

        return $this->rejectable(fn (): JsonResponse => response()->json(PaddleSubscriptionActions::dto($actions->preview(
            $tenantId,
            (string) $request->user()->getAuthIdentifier(),
            $validated['price_id'],
            (int) ($validated['extra_stores'] ?? 0),
            $validated['retained_store_ids'] ?? null,
            (bool) ($validated['faster_checks'] ?? false),
        ))));
    }

    public function changePlan(Request $request, TenantContext $tenantContext, PaddleSubscriptionActions $actions, EntitlementCalculator $calculator): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('change plan');
        $key = $this->idempotencyKey($request);

        if ($key === null) {
            return $this->idempotencyKeyRequired();
        }

        $validated = $request->validate(['preview_token' => ['required', 'string', 'max:64'], ...self::POLICY_RULES]);

        try {
            $accepted = $this->policies->verify(Policies::PURPOSE_PLAN_CHANGE, $validated['policy_version_ids'] ?? [], $this->locale($request));
        } catch (PolicyAcceptanceRequired $exception) {
            return $this->policyRequired($exception);
        }

        return $this->rejectable(function () use ($actions, $tenantId, $request, $validated, $key, $calculator, $accepted): JsonResponse {
            $change = $actions->confirm($tenantId, (string) $request->user()->getAuthIdentifier(), $validated['preview_token'], $key);
            $this->policies->record((string) $request->user()->getAuthIdentifier(), $tenantId, Policies::PURPOSE_PLAN_CHANGE, $accepted, $change->id);
            $calculator->forget();

            return response()->json(['change' => PaddleSubscriptionActions::dto($change), 'subscription' => $this->currentSubscription($tenantId, $calculator)], 202);
        });
    }

    public function cancelPlanChange(Request $request, TenantContext $tenantContext, PaddleSubscriptionActions $actions, EntitlementCalculator $calculator): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('cancel plan change');

        return $this->rejectable(function () use ($actions, $tenantId, $request, $calculator): JsonResponse {
            $change = $actions->cancelScheduledChange($tenantId, (string) $request->user()->getAuthIdentifier());
            $calculator->forget();

            return response()->json(['change' => PaddleSubscriptionActions::dto($change), 'subscription' => $this->currentSubscription($tenantId, $calculator)]);
        });
    }

    public function cancel(Request $request, TenantContext $tenantContext, PaddleRefunds $refunds, EntitlementCalculator $calculator): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('cancel subscription');
        $validated = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->rejectable(function () use ($refunds, $tenantId, $request, $validated, $calculator): JsonResponse {
            $refunds->withdraw($tenantId, (string) $request->user()->getAuthIdentifier(), isset($validated['reason']) ? trim($validated['reason']) : null);
            $calculator->forget();

            return response()->json($this->currentSubscription($tenantId, $calculator));
        });
    }

    public function resume(Request $request, TenantContext $tenantContext, PaddleSubscriptionActions $actions, EntitlementCalculator $calculator): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('resume subscription');
        $validated = $request->validate(['expected_version' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        return $this->rejectable(function () use ($actions, $tenantId, $request, $validated, $calculator): JsonResponse {
            $actions->resume($tenantId, (string) $request->user()->getAuthIdentifier(), isset($validated['expected_version']) ? (int) $validated['expected_version'] : null);
            $calculator->forget();

            return response()->json($this->currentSubscription($tenantId, $calculator));
        });
    }

    public function retainStores(Request $request, TenantContext $tenantContext, StoreCapacity $capacity): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('retain stores');
        $validated = $request->validate(['store_ids' => ['required', 'array', 'min:1', 'max:1000'], 'store_ids.*' => ['string', 'uuid']]);

        return $this->rejectable(function () use ($capacity, $tenantId, $validated): JsonResponse {
            $capacity->retain($tenantId, $validated['store_ids']);

            return response()->json($capacity->state($tenantId));
        });
    }
}
