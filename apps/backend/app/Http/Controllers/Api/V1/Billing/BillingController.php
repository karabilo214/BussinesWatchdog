<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingCheckoutSession;
use App\Models\BillingPlanChange;
use App\Models\BillingPrice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Billing\BillingCatalog;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\DiscountLedger;
use App\Support\Billing\DiscountResolver;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\Paddle\PaddleCheckout;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleSubscriptionActions;
use App\Support\Billing\StoreCapacity;
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
            'discount_code' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        try {
            $session = $checkout->start($tenantId, (string) $request->user()->getAuthIdentifier(), $validated['price_id'], (int) ($validated['extra_stores'] ?? 0), $key, $validated['discount_code'] ?? null);
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

    public function checkDiscount(Request $request, TenantContext $tenantContext, BillingCatalog $catalog, DiscountResolver $discounts): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('check discount');
        $validated = $request->validate(['code' => ['required', 'string', 'max:32'], 'price_id' => ['required', 'uuid']]);
        /** @var BillingPrice|null $price */
        $price = $catalog->prices()->with('planVersion')->whereKey($validated['price_id'])->where('price_kind', BillingPrice::KIND_BASE)->first();

        if ($price === null) {
            return response()->json(['code' => 'price_not_available', 'message' => 'The price is not available.'], 422);
        }

        try {
            $discount = $discounts->byCode($price, $validated['code']);
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The code cannot be used.'], $exception->status());
        }

        return response()->json(DiscountResolver::dto($discount));
    }

    public function plans(TenantContext $tenantContext, BillingCatalog $catalog): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read plans');
        $trialAvailable = Tenant::query()->whereKey($tenantId)->whereNull('trial_consumed_at')->exists()
            && ! Subscription::query()->where('tenant_id', $tenantId)->exists();

        return response()->json([
            'data' => $catalog->plans($tenantId),
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

    public function previewPlanChange(Request $request, TenantContext $tenantContext, PaddleSubscriptionActions $actions): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('preview plan change');
        $validated = $request->validate([
            'price_id' => ['required', 'uuid'],
            'extra_stores' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'retained_store_ids' => ['sometimes', 'nullable', 'array', 'max:1000'],
            'retained_store_ids.*' => ['string', 'uuid'],
        ]);

        return $this->rejectable(fn (): JsonResponse => response()->json(PaddleSubscriptionActions::dto($actions->preview(
            $tenantId,
            (string) $request->user()->getAuthIdentifier(),
            $validated['price_id'],
            (int) ($validated['extra_stores'] ?? 0),
            $validated['retained_store_ids'] ?? null,
        ))));
    }

    public function changePlan(Request $request, TenantContext $tenantContext, PaddleSubscriptionActions $actions, EntitlementCalculator $calculator): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('change plan');
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 128) {
            return response()->json(['code' => 'idempotency_key_required', 'message' => 'Idempotency-Key header is required.'], 400);
        }

        $validated = $request->validate(['preview_token' => ['required', 'string', 'max:64']]);

        return $this->rejectable(function () use ($actions, $tenantId, $request, $validated, $key, $calculator): JsonResponse {
            $change = $actions->confirm($tenantId, (string) $request->user()->getAuthIdentifier(), $validated['preview_token'], $key);
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

    public function cancel(Request $request, TenantContext $tenantContext, PaddleSubscriptionActions $actions, EntitlementCalculator $calculator): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('cancel subscription');
        $validated = $request->validate(['at_period_end' => ['required', 'accepted'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->rejectable(function () use ($actions, $tenantId, $request, $validated, $calculator): JsonResponse {
            $actions->cancel($tenantId, (string) $request->user()->getAuthIdentifier(), isset($validated['reason']) ? trim($validated['reason']) : null);
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

    /**
     * @param  callable(): JsonResponse  $action
     */
    private function rejectable(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(array_filter([
                'code' => $exception->reasonCode,
                'message' => 'The subscription cannot be changed.',
                'details' => $exception->details === [] ? null : $exception->details,
            ], fn (mixed $value): bool => $value !== null), $exception->status());
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentSubscription(string $tenantId, EntitlementCalculator $calculator): ?array
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();

        return $subscription === null ? null : $this->subscriptionDto($subscription, $calculator);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionDto(Subscription $subscription, EntitlementCalculator $calculator): array
    {
        $entitlements = $calculator->forTenant($subscription->tenant_id);
        $actions = app(PaddleSubscriptionActions::class);
        $contract = $actions->liveContract($subscription->tenant_id);
        $scheduled = $contract === null ? null : $actions->scheduledChange($contract->id);

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
            'price' => $this->priceDto($actions->liveContract($subscription->tenant_id)?->price_id, $scheduled),
            'scheduled_change' => $scheduled === null ? null : PaddleSubscriptionActions::dto($scheduled),
            'capacity' => app(StoreCapacity::class)->state($subscription->tenant_id),
            'version' => $subscription->version,
            'entitlements' => EntitlementCalculator::withStores($subscription->entitlements, $subscription->extra_store_quantity),
        ];
    }

    /**
     * The price the tenant pays for the current period: with a cheaper plan scheduled, the provider already holds the
     * next period's price, the period being paid is still the old one.
     *
     * @return array<string, mixed>|null
     */
    private function priceDto(?string $priceId, ?BillingPlanChange $scheduled): ?array
    {
        /** @var BillingPrice|null $price */
        $price = BillingPrice::query()->find($scheduled?->from_price_id ?? $priceId);

        return $price === null ? null : [
            'id' => $price->id,
            'amount_minor' => (string) $price->amount_minor,
            'currency' => $price->currency,
            'currency_exponent' => $price->currency_exponent,
            'billing_interval' => $price->billing_interval,
            'billing_interval_count' => $price->billing_interval_count,
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
            'discounts' => app(DiscountLedger::class)->checkoutBreakdown($session->id),
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
