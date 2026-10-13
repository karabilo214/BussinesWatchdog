<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\Billing\Concerns\RespondsToBilling;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Billing\BillingCatalog;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\TrialService;
use App\Support\Site\Policies;
use App\Support\Site\PolicyAcceptanceRequired;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plans the tenant can buy and our own trial (platform-billing-api.yaml).
 */
class PlanCatalogController extends Controller
{
    use RespondsToBilling;

    public function __construct(private readonly Policies $policies) {}

    public function plans(Request $request, TenantContext $tenantContext, BillingCatalog $catalog): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read plans');
        $trialAvailable = Tenant::query()->whereKey($tenantId)->whereNull('trial_consumed_at')->exists()
            && ! Subscription::query()->where('tenant_id', $tenantId)->exists();

        return response()->json([
            'data' => $catalog->plans($tenantId),
            'mode' => PaddleConfig::mode(),
            'checkout_available' => PaddleConfig::configured() && PaddleConfig::clientToken() !== null,
            'trial_available' => $trialAvailable,
            'documents' => $this->policies->required(Policies::PURPOSE_PURCHASE, $this->locale($request)),
        ]);
    }

    public function trial(Request $request, TenantContext $tenantContext, TrialService $trials, EntitlementCalculator $calculator): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('start trial');
        $validated = $request->validate(['plan_version_id' => ['required', 'uuid'], ...self::POLICY_RULES]);

        if ($request->user()->email_verified_at === null) {
            return response()->json(['code' => 'email_not_verified', 'message' => 'Confirm your email address first.'], 403);
        }

        try {
            $accepted = $this->policies->verify(Policies::PURPOSE_TRIAL, $validated['policy_version_ids'] ?? [], $this->locale($request));
            $subscription = $trials->start($tenantId, $validated['plan_version_id']);
            $this->policies->record((string) $request->user()->getAuthIdentifier(), $tenantId, Policies::PURPOSE_TRIAL, $accepted, $subscription->id);
        } catch (PolicyAcceptanceRequired $exception) {
            return $this->policyRequired($exception);
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The trial cannot be started.'], $exception->status());
        }

        $calculator->forget();

        return response()->json($this->subscriptionDto($subscription, $calculator), 201);
    }
}
