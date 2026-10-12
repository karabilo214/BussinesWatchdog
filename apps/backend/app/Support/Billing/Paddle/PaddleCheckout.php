<?php

namespace App\Support\Billing\Paddle;

use App\Models\BillingCheckoutSession;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Models\Tenant;
use App\Support\Billing\BillingCatalog;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\DiscountLedger;
use App\Support\Billing\DiscountResolver;
use App\Support\Billing\FeatureCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Starts a purchase (spec/panels/ADMIN-BACKEND.md §5): a local pending session is written first, then a Paddle
 * transaction is created outside the database transaction. The browser opens Paddle's checkout for that transaction;
 * access is granted only from Paddle's own subscription state, never from the browser.
 */
class PaddleCheckout
{
    public function __construct(
        private readonly PaddleClient $client,
        private readonly BillingCatalog $catalog,
        private readonly DiscountResolver $discounts,
        private readonly DiscountLedger $ledger,
    ) {}

    public function start(string $tenantId, string $userId, string $priceId, int $extraStores, string $idempotencyKey, ?string $discountCode = null): BillingCheckoutSession
    {
        if (! PaddleConfig::configured() || PaddleConfig::clientToken() === null) {
            throw new BillingCheckoutRejected('billing_not_configured');
        }

        $discountCode = $discountCode === null || trim($discountCode) === '' ? null : DiscountResolver::normalizeCode($discountCode);
        $request = $discountCode === null ? compact('priceId', 'extraStores') : compact('priceId', 'extraStores', 'discountCode');
        $hash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

        [$session, $superseded] = DB::transaction(function () use ($tenantId, $userId, $priceId, $extraStores, $idempotencyKey, $hash, $discountCode): array {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            /** @var BillingCheckoutSession|null $replay */
            $replay = BillingCheckoutSession::query()->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();

            if ($replay !== null) {
                return $replay->request_hash === $hash ? [$replay, []] : throw new BillingCheckoutRejected('idempotency_conflict');
            }

            $live = BillingSubscriptionContract::query()
                ->where('tenant_id', $tenantId)
                ->where('mode', PaddleConfig::mode())
                ->whereIn('normalized_state', [BillingSubscriptionContract::STATE_ACTIVE, BillingSubscriptionContract::STATE_TRIAL, BillingSubscriptionContract::STATE_PAST_DUE])
                ->exists();

            if ($live) {
                throw new BillingCheckoutRejected('subscription_exists');
            }

            $base = $this->basePrice($priceId);
            /** @var BillingPlanVersion $version */
            $version = $base->planVersion;
            $maxExtra = $version->entitlements['limits'][FeatureCatalog::MAX_EXTRA_STORES] ?? null;

            if ($extraStores < 0 || ($maxExtra !== null && $extraStores > $maxExtra)) {
                throw new BillingCheckoutRejected('extra_stores_out_of_range');
            }

            $extra = $extraStores > 0 ? $this->extraStorePrice($base) : null;
            $discounts = $this->discounts->stack($tenantId, $base, $discountCode);
            $pending = BillingCheckoutSession::query()
                ->where('tenant_id', $tenantId)
                ->where('mode', PaddleConfig::mode())
                ->whereIn('status', [BillingCheckoutSession::STATUS_CREATING, BillingCheckoutSession::STATUS_PENDING])
                ->get();
            BillingCheckoutSession::query()->whereKey($pending->modelKeys())->update(['status' => 'cancelled', 'error_code' => 'superseded', 'updated_at' => Carbon::now()]);

            $session = BillingCheckoutSession::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $userId,
                'plan_version_id' => $version->id,
                'price_id' => $base->id,
                'extra_store_price_id' => $extra?->id,
                'extra_store_quantity' => $extraStores,
                'provider' => PaddleConfig::PROVIDER,
                'provider_account_ref' => PaddleConfig::accountRef(),
                'mode' => PaddleConfig::mode(),
                'status' => BillingCheckoutSession::STATUS_CREATING,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'expires_at' => Carbon::now()->addHours((int) config('watchdog.billing.checkout_expires_hours')),
            ]);

            $amount = (int) $base->amount_minor + ($extra === null ? 0 : (int) $extra->amount_minor * $extraStores);
            $this->ledger->recordCheckout($session, $discounts, $amount, $base->currency, $base->currency_exponent);

            return [$session, $pending->pluck('provider_session_ref')->filter()->values()->all()];
        });

        if ($session->status !== BillingCheckoutSession::STATUS_CREATING || $session->wasRecentlyCreated === false) {
            return $session;
        }

        foreach ($superseded as $transactionRef) {
            $this->cancelQuietly($transactionRef);
        }

        $items = [['price_id' => BillingPrice::query()->whereKey($session->price_id)->value('provider_price_ref'), 'quantity' => 1]];

        if ($session->extra_store_quantity > 0) {
            $items[] = ['price_id' => BillingPrice::query()->whereKey($session->extra_store_price_id)->value('provider_price_ref'), 'quantity' => $session->extra_store_quantity];
        }

        try {
            $applicationId = DB::table('billing_discount_applications')->where('checkout_session_id', $session->id)->where('period_number', 1)->value('id');
            $discountRef = $applicationId === null ? null : $this->ledger->checkoutDiscountRef($applicationId);
            $result = $this->client->post('/transactions', array_filter([
                'items' => $items,
                'discount_id' => $discountRef,
                'collection_mode' => 'automatic',
                'custom_data' => ['tenant_id' => $tenantId, 'checkout_id' => $session->id],
            ], fn (mixed $value): bool => $value !== null));
            $session->forceFill(['status' => BillingCheckoutSession::STATUS_PENDING, 'provider_session_ref' => (string) $result['data']['id']])->save();
        } catch (PaddleRequestFailed $exception) {
            $session->forceFill([
                'status' => $exception->outcomeUnknown() ? BillingCheckoutSession::STATUS_UNKNOWN : BillingCheckoutSession::STATUS_FAILED,
                'error_code' => $exception->reasonCode,
            ])->save();
        }

        return $session;
    }

    private function basePrice(string $priceId): BillingPrice
    {
        /** @var BillingPrice|null $price */
        $price = $this->prices()
            ->whereKey($priceId)
            ->where('price_kind', BillingPrice::KIND_BASE)
            ->whereHas('planVersion', fn ($query) => $query->whereIn('state', $this->sellableStates())
                ->whereHas('plan', fn ($query) => $query->whereIn('state', $this->sellableStates())))
            ->first();

        return $price ?? throw new BillingCheckoutRejected('price_not_available');
    }

    private function extraStorePrice(BillingPrice $base): BillingPrice
    {
        /** @var BillingPrice|null $price */
        $price = $this->prices()
            ->where('plan_version_id', $base->plan_version_id)
            ->where('price_kind', BillingPrice::KIND_EXTRA_STORE)
            ->where('billing_interval', $base->billing_interval)
            ->where('billing_interval_count', $base->billing_interval_count)
            ->where('currency', $base->currency)
            ->first();

        return $price ?? throw new BillingCheckoutRejected('extra_stores_out_of_range');
    }

    /**
     * @return Builder<BillingPrice>
     */
    private function prices(): Builder
    {
        return $this->catalog->prices();
    }

    /**
     * @return list<string>
     */
    private function sellableStates(): array
    {
        return BillingCatalog::sellableStates();
    }

    private function cancelQuietly(string $transactionRef): void
    {
        try {
            $this->client->patch('/transactions/'.$transactionRef, ['status' => 'canceled']);
        } catch (PaddleRequestFailed) {
        }
    }
}
