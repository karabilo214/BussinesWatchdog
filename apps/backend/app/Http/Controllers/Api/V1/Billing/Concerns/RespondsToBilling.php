<?php

namespace App\Http\Controllers\Api\V1\Billing\Concerns;

use App\Models\BillingPlanChange;
use App\Models\BillingPrice;
use App\Models\Subscription;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\OrderVolume;
use App\Support\Billing\Paddle\PaddleRefunds;
use App\Support\Billing\Paddle\PaddleSubscriptionActions;
use App\Support\Billing\StoreCapacity;
use App\Support\Site\PolicyAcceptanceRequired;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsToBilling
{
    private const POLICY_RULES = [
        'policy_version_ids' => ['sometimes', 'array', 'max:10'],
        'policy_version_ids.*' => ['string', 'uuid'],
    ];

    private function locale(Request $request): string
    {
        $locale = (string) ($request->user()?->locale ?? 'en');

        return in_array($locale, ['ru', 'en', 'de'], true) ? $locale : 'en';
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        return $key === '' || strlen($key) > 128 ? null : $key;
    }

    private function idempotencyKeyRequired(): JsonResponse
    {
        return response()->json(['code' => 'idempotency_key_required', 'message' => 'Idempotency-Key header is required.'], 400);
    }

    private function policyRequired(PolicyAcceptanceRequired $exception): JsonResponse
    {
        return response()->json(['code' => 'policy_acceptance_required', 'message' => 'Accept the current terms first.', 'details' => ['documents' => $exception->required]], 422);
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
            'withdrawal_ends_at' => $contract?->started_at?->copy()->timezone('Europe/Berlin')->addDays(14)->toJSON(),
            'withdrawal_pending' => $contract !== null && app(PaddleRefunds::class)->withdrawalPending($contract),
            'withdrawal_requested' => $contract !== null && app(PaddleRefunds::class)->withdrawalRequested($contract),
            'extra_store_quantity' => $subscription->extra_store_quantity,
            'faster_check_interval_minutes' => $subscription->faster_check_interval_minutes,
            'price' => $this->priceDto($actions->liveContract($subscription->tenant_id)?->price_id, $scheduled),
            'scheduled_change' => $scheduled === null ? null : PaddleSubscriptionActions::dto($scheduled),
            'capacity' => app(StoreCapacity::class)->state($subscription->tenant_id),
            'order_volume' => app(OrderVolume::class)->summary($subscription->tenant_id, $subscription->plan_code),
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
}
