<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingCheckoutSession;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Billing\BillingCatalog;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\Paddle\PaddleCheckout;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\TrialService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The tenant owner's subscription (platform-billing-api.yaml): start a checkout, follow it, read the subscription.
 * Paid access comes only from the provider's verified state, never from the browser returning from the checkout.
 */
class BillingController extends Controller
{
    public function checkout(Request $request, TenantContext $tenantContext, PaddleCheckout $checkout): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('start checkout');
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 128) {
            return response()->json(['code' => 'idempotency_key_required', 'message' => 'Idempotency-Key header is required.'], 400);
        }

        $validated = $request->validate([
            'price_id' => ['required', 'uuid'],
            'extra_stores' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        try {
            $session = $checkout->start($tenantId, (string) $request->user()->getAuthIdentifier(), $validated['price_id'], (int) ($validated['extra_stores'] ?? 0), $key);
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The checkout cannot be started.'], $exception->status());
        }

        return response()->json($this->checkoutDto($session, $tenantId), 202);
    }

    public function showCheckout(TenantContext $tenantContext, string $checkout): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read checkout');
        /** @var BillingCheckoutSession|null $session */
        $session = Str::isUuid($checkout) ? BillingCheckoutSession::query()->where('tenant_id', $tenantId)->whereKey($checkout)->first() : null;

        if ($session === null) {
            return response()->json(['code' => 'not_found', 'message' => 'Checkout not found.'], 404);
        }

        return response()->json($this->checkoutDto($session, $tenantId));
    }

    public function trial(Request $request, TenantContext $tenantContext, TrialService $trials, EntitlementCalculator $calculator): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('start trial');
        $validated = $request->validate(['plan_version_id' => ['required', 'uuid']]);

        if ($request->user()->email_verified_at === null) {
            return response()->json(['code' => 'email_not_verified', 'message' => 'Confirm your email address first.'], 403);
        }

        try {
            $subscription = $trials->start($tenantId, $validated['plan_version_id']);
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The trial cannot be started.'], $exception->status());
        }

        $calculator->forget();

        return response()->json($this->subscriptionDto($subscription, $calculator), 201);
    }

    public function plans(TenantContext $tenantContext, BillingCatalog $catalog): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read plans');
        $trialAvailable = Tenant::query()->whereKey($tenantId)->whereNull('trial_consumed_at')->exists()
            && ! Subscription::query()->where('tenant_id', $tenantId)->exists();

        return response()->json([
            'data' => $catalog->plans(),
            'mode' => PaddleConfig::mode(),
            'checkout_available' => PaddleConfig::configured() && PaddleConfig::clientToken() !== null,
            'trial_available' => $trialAvailable,
        ]);
    }

    public function subscription(TenantContext $tenantContext, EntitlementCalculator $calculator): JsonResponse|Response
    {
        $tenantId = $tenantContext->requireTenantId('read subscription');
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();

        return $subscription === null ? response()->noContent() : response()->json($this->subscriptionDto($subscription, $calculator));
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionDto(Subscription $subscription, EntitlementCalculator $calculator): array
    {
        $entitlements = $calculator->forTenant($subscription->tenant_id);

        return [
            'id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'provider' => $subscription->provider,
            'plan_code' => $subscription->plan_code,
            'plan_version_id' => $subscription->plan_version_id,
            'status' => $subscription->status,
            'access_state' => $entitlements->access,
            'access_reason_codes' => $entitlements->reasons,
            'paid_until' => $subscription->status === Subscription::STATUS_ACTIVE ? $subscription->period_end?->toJSON() : null,
            'trial_ends_at' => $subscription->trial_ends_at?->toJSON(),
            'grace_until' => $subscription->past_due_since?->copy()->addDays((int) config('watchdog.billing.past_due_grace_days'))->toJSON(),
            'period_end' => $subscription->period_end?->toJSON(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'extra_store_quantity' => $subscription->extra_store_quantity,
            'version' => $subscription->version,
            'entitlements' => EntitlementCalculator::withStores($subscription->entitlements, $subscription->extra_store_quantity),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutDto(BillingCheckoutSession $session, string $tenantId): array
    {
        $open = $session->status === BillingCheckoutSession::STATUS_PENDING && $session->expires_at->isFuture() && $session->provider_session_ref !== null;

        return [
            'id' => $session->id,
            'status' => $session->status === BillingCheckoutSession::STATUS_PENDING && $session->expires_at->isPast() ? BillingCheckoutSession::STATUS_EXPIRED : $session->status,
            'error_code' => $session->error_code,
            'expires_at' => $session->expires_at->toJSON(),
            'extra_store_quantity' => $session->extra_store_quantity,
            'access_state' => app(EntitlementCalculator::class)->forTenant($tenantId)->access,
            'provider_checkout' => $open ? [
                'provider' => $session->provider,
                'environment' => PaddleConfig::environment(),
                'client_token' => PaddleConfig::clientToken(),
                'transaction_id' => $session->provider_session_ref,
            ] : null,
        ];
    }
}
