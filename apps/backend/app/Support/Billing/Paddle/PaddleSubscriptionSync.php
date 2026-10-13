<?php

namespace App\Support\Billing\Paddle;

use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Support\Billing\DiscountLedger;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\SubscriptionProjector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads one Paddle subscription as it is now (event order does not matter) and projects it. A subscription belongs to a
 * tenant only through a transaction our server created for that tenant's checkout (origin `api`, our checkout id in
 * custom data): custom data set in a browser checkout cannot claim someone else's tenant.
 */
class PaddleSubscriptionSync
{
    private const STATE = [
        'active' => BillingSubscriptionContract::STATE_ACTIVE,
        'trialing' => BillingSubscriptionContract::STATE_TRIAL,
        'past_due' => BillingSubscriptionContract::STATE_PAST_DUE,
        'paused' => BillingSubscriptionContract::STATE_UNPAID,
        'canceled' => BillingSubscriptionContract::STATE_CANCELLED,
    ];

    public function __construct(
        private readonly PaddleClient $client,
        private readonly SubscriptionProjector $projector,
    ) {}

    /**
     * @return 'projected'|'not_linked'|'stale'
     */
    public function sync(string $subscriptionRef): string
    {
        $subscription = $this->client->get('/subscriptions/'.rawurlencode($subscriptionRef))['data'];
        $contract = $this->contract($subscriptionRef);
        $session = null;

        if ($contract === null) {
            $session = $this->linkedSession($subscriptionRef, $subscription);

            if ($session === null) {
                return 'not_linked';
            }
        }

        $tenantId = $contract?->tenant_id ?? $session->tenant_id;
        $updatedAt = Carbon::parse((string) ($subscription['updated_at'] ?? 'now'));

        return DB::transaction(function () use ($subscriptionRef, $subscription, $tenantId, $updatedAt, $session): string {
            /** @var BillingSubscriptionContract|null $contract */
            $contract = $this->contract($subscriptionRef, lock: true);

            if ($contract !== null && $contract->source_updated_at->greaterThan($updatedAt)) {
                return 'stale';
            }

            $customer = $this->customer($tenantId, $subscription['customer_id'] ?? null, $updatedAt);
            [$base, $extraQuantity, $problem, $faster] = $this->items($subscription['items'] ?? []);
            $state = $problem === null ? (self::STATE[$subscription['status'] ?? ''] ?? BillingSubscriptionContract::STATE_UNKNOWN) : BillingSubscriptionContract::STATE_UNKNOWN;
            $period = is_array($subscription['current_billing_period'] ?? null) ? $subscription['current_billing_period'] : [];
            $periodEnd = isset($period['ends_at']) ? Carbon::parse($period['ends_at']) : null;
            $scheduled = is_array($subscription['scheduled_change'] ?? null) ? $subscription['scheduled_change'] : [];
            $attributes = [
                'tenant_id' => $tenantId,
                'customer_id' => $customer?->id,
                'provider_state' => is_string($subscription['status'] ?? null) ? $subscription['status'] : null,
                'normalized_state' => $state,
                'currency' => is_string($subscription['currency_code'] ?? null) ? $subscription['currency_code'] : null,
                'period_start' => isset($period['starts_at']) ? Carbon::parse($period['starts_at']) : null,
                'period_end' => $periodEnd,
                'next_charge_at' => isset($subscription['next_billed_at']) ? Carbon::parse($subscription['next_billed_at']) : null,
                'cancel_at_period_end' => ($scheduled['action'] ?? null) === 'cancel',
                'problem_code' => $problem,
                'source_updated_at' => $updatedAt,
            ];

            if ($base !== null) {
                $attributes += [
                    'plan_version_id' => $base->plan_version_id,
                    'price_id' => $base->id,
                    'extra_store_quantity' => $extraQuantity,
                    'faster_checks' => $faster,
                    'entitlement_snapshot' => EntitlementCalculator::withFasterChecks(EntitlementCalculator::withStores(FeatureCatalog::normalize($base->planVersion->entitlements), $extraQuantity), $faster ? $base->planVersion->faster_check_interval_minutes : null),
                ];
            }

            if ($state === BillingSubscriptionContract::STATE_ACTIVE) {
                $attributes['paid_until'] = $periodEnd;
            }

            if ($contract === null) {
                $contract = BillingSubscriptionContract::query()->create([
                    'provider' => PaddleConfig::PROVIDER,
                    'provider_account_ref' => PaddleConfig::accountRef(),
                    'mode' => PaddleConfig::mode(),
                    'provider_subscription_ref' => $subscriptionRef,
                    'entitlement_snapshot' => ['features' => [], 'limits' => []],
                    ...$attributes,
                ]);
            } else {
                $contract->fill($attributes);

                if ($contract->isDirty()) {
                    $contract->version++;
                    $contract->save();
                }
            }

            $this->flagDuplicate($contract);

            if ($session !== null) {
                $session->forceFill(['contract_id' => $contract->id])->save();
                app(DiscountLedger::class)->linkContract($session, $contract);
            }

            $this->projector->project($tenantId, PaddleConfig::mode());
            app(EntitlementCalculator::class)->forget();

            return 'projected';
        });
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function linkedSession(string $subscriptionRef, array $subscription): ?BillingCheckoutSession
    {
        $checkoutId = $subscription['custom_data']['checkout_id'] ?? null;

        if (! is_string($checkoutId) || ! Str::isUuid($checkoutId)) {
            return null;
        }

        /** @var BillingCheckoutSession|null $session */
        $session = BillingCheckoutSession::query()
            ->whereKey($checkoutId)
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->first();

        if ($session === null || ($subscription['custom_data']['tenant_id'] ?? null) !== $session->tenant_id) {
            return null;
        }

        $transactions = $this->client->get('/transactions', ['subscription_id' => $subscriptionRef, 'per_page' => 50])['data'];

        foreach ($transactions as $transaction) {
            if (is_array($transaction)
                && ($transaction['origin'] ?? null) === 'api'
                && ($transaction['custom_data']['checkout_id'] ?? null) === $session->id
                && ($session->provider_session_ref === null || $session->provider_session_ref === ($transaction['id'] ?? null))) {
                if ($session->provider_session_ref === null) {
                    $session->forceFill(['provider_session_ref' => $transaction['id']])->save();
                }

                return $session;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?BillingPrice, 1: int, 2: ?string, 3: bool}
     */
    private function items(mixed $items): array
    {
        $base = null;
        $extra = 0;
        $faster = false;

        foreach (is_array($items) ? $items : [] as $item) {
            $ref = $item['price']['id'] ?? null;

            if (! is_array($item) || ! is_string($ref) || ($item['status'] ?? 'active') !== 'active') {
                continue;
            }

            /** @var BillingPrice|null $price */
            $price = BillingPrice::query()
                ->with('planVersion')
                ->where('provider', PaddleConfig::PROVIDER)
                ->where('provider_account_ref', PaddleConfig::accountRef())
                ->where('mode', PaddleConfig::mode())
                ->where('provider_price_ref', $ref)
                ->first();

            if ($price === null) {
                return [null, 0, 'unknown_price', false];
            }

            if ($price->price_kind === BillingPrice::KIND_BASE) {
                if ($base !== null) {
                    return [null, 0, 'several_base_prices', false];
                }

                $base = $price;
            } elseif ($price->price_kind === BillingPrice::KIND_FASTER_CHECKS) {
                $faster = true;
            } else {
                $extra += (int) ($item['quantity'] ?? 0);
            }
        }

        if ($base === null) {
            return [null, 0, 'base_price_missing', false];
        }

        return [$base, $extra, null, $faster];
    }

    private function customer(string $tenantId, mixed $customerRef, Carbon $updatedAt): ?BillingCustomer
    {
        if (! is_string($customerRef) || $customerRef === '') {
            return null;
        }

        return BillingCustomer::query()->firstOrCreate([
            'provider' => PaddleConfig::PROVIDER,
            'provider_account_ref' => PaddleConfig::accountRef(),
            'mode' => PaddleConfig::mode(),
            'provider_customer_ref' => $customerRef,
        ], ['tenant_id' => $tenantId, 'source_updated_at' => $updatedAt]);
    }

    private function flagDuplicate(BillingSubscriptionContract $contract): void
    {
        $live = [BillingSubscriptionContract::STATE_ACTIVE, BillingSubscriptionContract::STATE_TRIAL, BillingSubscriptionContract::STATE_PAST_DUE];

        if (! in_array($contract->normalized_state, $live, true)) {
            return;
        }

        $others = BillingSubscriptionContract::query()
            ->where('tenant_id', $contract->tenant_id)
            ->where('mode', $contract->mode)
            ->whereKeyNot($contract->id)
            ->whereIn('normalized_state', $live)
            ->exists();

        if ($others && $contract->problem_code === null) {
            $contract->forceFill(['problem_code' => 'duplicate_live_contract'])->save();
        }
    }

    private function contract(string $subscriptionRef, bool $lock = false): ?BillingSubscriptionContract
    {
        $query = BillingSubscriptionContract::query()
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->where('provider_subscription_ref', $subscriptionRef);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
