<?php

namespace App\Support\Billing\Paddle;

use App\Models\AuditLog;
use App\Models\BillingPlanChange;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Billing\BillingCatalog;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\DiscountLedger;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\StoreCapacity;
use App\Support\Billing\SubscriptionProjector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The owner's own subscription actions (spec/panels/PUBLIC-SUBSCRIPTION-FRONTEND.md §7, ADR 0037). A plan change is
 * previewed first (Paddle's proration, our next-period price with discounts, a token valid for 10 minutes) and confirmed
 * by its token. A more expensive plan takes effect at once with Paddle charging the prorated difference — if the
 * payment fails Paddle does not change the subscription. A cheaper plan takes effect at the next period: Paddle has no
 * scheduled item change, so the items are changed now without billing and the paid plan is kept in our projection
 * until the period ends. Cancellation and resume use Paddle's scheduled cancel. Access always follows the subscription
 * read back from Paddle.
 */
class PaddleSubscriptionActions
{
    private const PREVIEW_MINUTES = 10;

    public function __construct(
        private readonly PaddleClient $client,
        private readonly BillingCatalog $catalog,
        private readonly DiscountLedger $ledger,
        private readonly PaddleSubscriptionSync $sync,
        private readonly SubscriptionProjector $projector,
        private readonly StoreCapacity $capacity,
    ) {}

    public function liveContract(string $tenantId): ?BillingSubscriptionContract
    {
        return BillingSubscriptionContract::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->whereIn('normalized_state', [BillingSubscriptionContract::STATE_ACTIVE, BillingSubscriptionContract::STATE_PAST_DUE])
            ->orderByDesc('period_end')
            ->first();
    }

    public function scheduledChange(string $contractId): ?BillingPlanChange
    {
        return BillingPlanChange::query()->where('contract_id', $contractId)->where('status', BillingPlanChange::STATUS_SCHEDULED)->first();
    }

    /**
     * @param  list<string>|null  $retainedStoreIds
     */
    public function preview(string $tenantId, string $userId, string $priceId, int $extraStores, ?array $retainedStoreIds): BillingPlanChange
    {
        $contract = $this->activeContract($tenantId);
        $scheduled = $this->scheduledChange($contract->id);
        /** @var BillingPrice|null $from */
        $from = BillingPrice::query()->with('planVersion.plan')->find($scheduled?->from_price_id ?? $contract->price_id);
        $fromExtra = $scheduled?->from_extra_store_quantity ?? (int) $contract->extra_store_quantity;

        if ($from === null) {
            throw new BillingCheckoutRejected('subscription_unknown');
        }

        $target = $this->catalog->sellableBasePrice($priceId);
        $entitlements = FeatureCatalog::normalize($target->planVersion->entitlements);
        $maxExtra = $entitlements['limits'][FeatureCatalog::MAX_EXTRA_STORES] ?? null;

        if ($extraStores < 0 || ($maxExtra !== null && $extraStores > $maxExtra)) {
            throw new BillingCheckoutRejected('extra_stores_out_of_range');
        }

        if ($target->currency !== $from->currency) {
            throw new BillingCheckoutRejected('currency_change_unsupported');
        }

        if ($target->billing_interval !== $from->billing_interval || $target->billing_interval_count !== $from->billing_interval_count) {
            throw new BillingCheckoutRejected('interval_change_unsupported');
        }

        if ($target->id === $from->id && $extraStores === $fromExtra) {
            throw new BillingCheckoutRejected($scheduled === null ? 'plan_unchanged' : 'scheduled_change_pending');
        }

        $extraPrice = $extraStores > 0 ? $this->catalog->extraStorePriceFor($target) : null;
        $fromAmount = $this->recurringAmount($from, $fromExtra);
        $toAmount = (int) $target->amount_minor + ($extraPrice === null ? 0 : (int) $extraPrice->amount_minor * $extraStores);
        $kind = $fromAmount !== null && $toAmount >= $fromAmount ? BillingPlanChange::KIND_IMMEDIATE : BillingPlanChange::KIND_NEXT_PERIOD;

        if ($scheduled !== null && $kind === BillingPlanChange::KIND_IMMEDIATE) {
            throw new BillingCheckoutRejected('scheduled_change_pending');
        }

        $retained = $this->retainedStores($tenantId, EntitlementCalculator::withStores($entitlements, $extraStores)['limits'][FeatureCatalog::MAX_ACTIVE_STORES] ?? null, $retainedStoreIds);

        try {
            $preview = $this->client->patch('/subscriptions/'.rawurlencode((string) $contract->provider_subscription_ref).'/preview', [
                'items' => $this->items($target, $extraPrice, $extraStores),
                'proration_billing_mode' => $kind === BillingPlanChange::KIND_IMMEDIATE ? 'prorated_immediately' : 'do_not_bill',
            ])['data'];
        } catch (PaddleRequestFailed) {
            throw new BillingCheckoutRejected('provider_unavailable');
        }

        $dueNow = $kind === BillingPlanChange::KIND_IMMEDIATE ? (string) ($preview['immediate_transaction']['details']['totals']['grand_total'] ?? '') : '0';

        if (preg_match('/^\d{1,15}$/', $dueNow) !== 1) {
            throw new BillingCheckoutRejected('provider_unavailable');
        }

        $quote = $this->ledger->nextPeriodQuote($contract, $target, $toAmount);

        return DB::transaction(function () use ($tenantId, $userId, $contract, $kind, $from, $fromExtra, $target, $extraPrice, $extraStores, $retained, $dueNow, $quote): BillingPlanChange {
            BillingPlanChange::query()->where('tenant_id', $tenantId)->where('status', BillingPlanChange::STATUS_PREVIEWED)->update(['status' => BillingPlanChange::STATUS_EXPIRED, 'updated_at' => Carbon::now()]);

            return BillingPlanChange::query()->create([
                'tenant_id' => $tenantId,
                'contract_id' => $contract->id,
                'actor_user_id' => $userId,
                'kind' => $kind,
                'status' => BillingPlanChange::STATUS_PREVIEWED,
                'from_price_id' => $from->id,
                'from_extra_store_quantity' => $fromExtra,
                'to_plan_version_id' => $target->plan_version_id,
                'to_price_id' => $target->id,
                'to_extra_store_price_id' => $extraPrice?->id,
                'to_extra_store_quantity' => $extraStores,
                'retained_store_ids' => $retained,
                'currency' => $target->currency,
                'currency_exponent' => $target->currency_exponent,
                'due_now_minor' => $dueNow,
                'next_amount_before_minor' => $quote['amount_before'],
                'next_amount_minor' => $quote['amount_after'],
                'contract_version' => $contract->version,
                'preview_expires_at' => Carbon::now()->addMinutes(self::PREVIEW_MINUTES),
                'effective_at' => $kind === BillingPlanChange::KIND_NEXT_PERIOD ? $contract->period_end : null,
            ]);
        });
    }

    public function confirm(string $tenantId, string $userId, string $changeId, string $idempotencyKey): BillingPlanChange
    {
        [$change, $fresh] = DB::transaction(function () use ($tenantId, $userId, $changeId, $idempotencyKey): array {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            /** @var BillingPlanChange|null $replay */
            $replay = BillingPlanChange::query()->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();

            if ($replay !== null) {
                return $replay->id === $changeId ? [$replay, false] : throw new BillingCheckoutRejected('idempotency_conflict');
            }

            /** @var BillingPlanChange|null $change */
            $change = Str::isUuid($changeId) ? BillingPlanChange::query()->where('tenant_id', $tenantId)->whereKey($changeId)->lockForUpdate()->first() : null;

            if ($change === null) {
                throw new BillingCheckoutRejected('preview_not_found');
            }

            if ($change->status !== BillingPlanChange::STATUS_PREVIEWED) {
                throw new BillingCheckoutRejected($change->status === BillingPlanChange::STATUS_EXPIRED ? 'preview_expired' : 'preview_used');
            }

            if ($change->preview_expires_at->isPast()) {
                throw new BillingCheckoutRejected('preview_expired');
            }

            /** @var BillingSubscriptionContract $contract */
            $contract = BillingSubscriptionContract::query()->whereKey($change->contract_id)->lockForUpdate()->firstOrFail();

            if ($contract->version !== $change->contract_version || $contract->normalized_state !== BillingSubscriptionContract::STATE_ACTIVE) {
                throw new BillingCheckoutRejected('preview_stale');
            }

            $change->forceFill(['status' => BillingPlanChange::STATUS_APPLYING, 'idempotency_key' => $idempotencyKey, 'actor_user_id' => $userId])->save();

            return [$change, true];
        });

        if (! $fresh) {
            return $change;
        }

        /** @var BillingSubscriptionContract $contract */
        $contract = BillingSubscriptionContract::query()->findOrFail($change->contract_id);
        /** @var BillingPrice $target */
        $target = BillingPrice::query()->findOrFail($change->to_price_id);
        $extraPrice = $change->to_extra_store_price_id === null ? null : BillingPrice::query()->find($change->to_extra_store_price_id);
        $immediate = $change->kind === BillingPlanChange::KIND_IMMEDIATE;

        try {
            $this->client->patch('/subscriptions/'.rawurlencode((string) $contract->provider_subscription_ref), [
                'items' => $this->items($target, $extraPrice, $change->to_extra_store_quantity),
                'proration_billing_mode' => $immediate ? 'prorated_immediately' : 'do_not_bill',
                'on_payment_failure' => 'prevent_change',
            ]);
        } catch (PaddleRequestFailed $exception) {
            $unknown = $exception->outcomeUnknown();
            $change->forceFill([
                'status' => $unknown ? BillingPlanChange::STATUS_UNKNOWN : BillingPlanChange::STATUS_FAILED,
                'error_code' => $exception->paddleCode ?? $exception->reasonCode,
            ])->save();

            throw new BillingCheckoutRejected(match (true) {
                $unknown => 'provider_outcome_unknown',
                preg_match('/payment|declin|card/', (string) $exception->paddleCode) === 1 => 'payment_failed',
                default => 'plan_change_rejected',
            });
        }

        DB::transaction(function () use ($change, $immediate): void {
            if (! $immediate) {
                BillingPlanChange::query()->where('contract_id', $change->contract_id)->where('status', BillingPlanChange::STATUS_SCHEDULED)->update(['status' => BillingPlanChange::STATUS_CANCELLED, 'updated_at' => Carbon::now()]);
            }

            $change->forceFill($immediate
                ? ['status' => BillingPlanChange::STATUS_APPLIED, 'applied_at' => Carbon::now(), 'effective_at' => Carbon::now()]
                : ['status' => BillingPlanChange::STATUS_SCHEDULED])->save();
        });

        $this->audit($change->tenant_id, $userId, 'billing.plan_change_'.($immediate ? 'applied' : 'scheduled'), $change->id, [
            'from_price_id' => $change->from_price_id,
            'to_price_id' => $change->to_price_id,
            'to_extra_store_quantity' => $change->to_extra_store_quantity,
            'due_now_minor' => $change->due_now_minor,
        ]);
        $this->syncQuietly((string) $contract->provider_subscription_ref);
        $this->settleDiscounts($change);

        if ($immediate) {
            $this->capacity->settle($change->tenant_id, $change->retained_store_ids);
        }

        return $change->refresh();
    }

    public function cancelScheduledChange(string $tenantId, string $userId): BillingPlanChange
    {
        $contract = $this->activeContract($tenantId);
        $scheduled = $this->scheduledChange($contract->id) ?? throw new BillingCheckoutRejected('no_scheduled_change');
        /** @var BillingPrice $from */
        $from = BillingPrice::query()->findOrFail($scheduled->from_price_id);
        $extraPrice = $scheduled->from_extra_store_quantity > 0 ? $this->extraPriceOf($from) : null;

        if ($scheduled->from_extra_store_quantity > 0 && $extraPrice === null) {
            throw new BillingCheckoutRejected('subscription_unknown');
        }

        $this->patchSubscription($contract, [
            'items' => $this->items($from, $extraPrice, $scheduled->from_extra_store_quantity),
            'proration_billing_mode' => 'do_not_bill',
        ]);

        $scheduled->forceFill(['status' => BillingPlanChange::STATUS_CANCELLED, 'discounts_settled_at' => null])->save();
        $this->audit($tenantId, $userId, 'billing.plan_change_cancelled', $scheduled->id, []);
        $this->syncQuietly((string) $contract->provider_subscription_ref);
        $this->settleDiscounts($scheduled);

        return $scheduled->refresh();
    }

    public function cancel(string $tenantId, string $userId, ?string $reason): void
    {
        $contract = $this->activeContract($tenantId);

        if ($contract->cancel_at_period_end) {
            return;
        }

        try {
            $this->client->post('/subscriptions/'.rawurlencode((string) $contract->provider_subscription_ref).'/cancel', ['effective_from' => 'next_billing_period']);
        } catch (PaddleRequestFailed $exception) {
            throw new BillingCheckoutRejected($exception->outcomeUnknown() ? 'provider_outcome_unknown' : 'provider_unavailable');
        }

        $this->audit($tenantId, $userId, 'billing.subscription_cancel_scheduled', $contract->id, ['reason' => $reason, 'effective_at' => $contract->period_end?->toJSON()]);
        $this->syncQuietly((string) $contract->provider_subscription_ref);
    }

    public function resume(string $tenantId, string $userId, ?int $expectedVersion): void
    {
        $contract = $this->activeContract($tenantId);

        if (! $contract->cancel_at_period_end) {
            throw new BillingCheckoutRejected('cancel_not_scheduled');
        }

        if ($expectedVersion !== null && Subscription::query()->where('tenant_id', $tenantId)->value('version') !== $expectedVersion) {
            throw new BillingCheckoutRejected('version_conflict');
        }

        $this->patchSubscription($contract, ['scheduled_change' => null]);
        $this->audit($tenantId, $userId, 'billing.subscription_resumed', $contract->id, []);
        $this->syncQuietly((string) $contract->provider_subscription_ref);
    }

    /**
     * Scheduled changes whose period has ended take effect in our projection; changes with an unknown Paddle outcome are
     * settled by reading the subscription; next-period discounts left unsettled are planned again.
     *
     * @return array{applied: int, resolved: int, discounts: int}
     */
    public function settle(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $applied = 0;
        $resolved = 0;
        $discounts = 0;

        BillingPlanChange::query()->where('status', BillingPlanChange::STATUS_PREVIEWED)->where('preview_expires_at', '<', $now)->update(['status' => BillingPlanChange::STATUS_EXPIRED, 'updated_at' => $now]);

        foreach (BillingPlanChange::query()->where('status', BillingPlanChange::STATUS_SCHEDULED)->where('effective_at', '<=', $now)->get() as $change) {
            $change->forceFill(['status' => BillingPlanChange::STATUS_APPLIED, 'applied_at' => $now])->save();
            $this->projector->project($change->tenant_id, PaddleConfig::mode(), $now);
            $this->capacity->settle($change->tenant_id, $change->retained_store_ids);
            $applied++;
        }

        foreach (BillingPlanChange::query()->where('status', BillingPlanChange::STATUS_UNKNOWN)->where('updated_at', '<', $now->copy()->subMinute())->get() as $change) {
            /** @var BillingSubscriptionContract $contract */
            $contract = BillingSubscriptionContract::query()->findOrFail($change->contract_id);

            if (! $this->syncQuietly((string) $contract->provider_subscription_ref)) {
                continue;
            }

            $contract->refresh();
            $landed = $contract->price_id === $change->to_price_id && (int) $contract->extra_store_quantity === $change->to_extra_store_quantity;
            $change->forceFill(['status' => match (true) {
                ! $landed => BillingPlanChange::STATUS_FAILED,
                $change->kind === BillingPlanChange::KIND_IMMEDIATE => BillingPlanChange::STATUS_APPLIED,
                default => BillingPlanChange::STATUS_SCHEDULED,
            }, 'applied_at' => $landed && $change->kind === BillingPlanChange::KIND_IMMEDIATE ? $now : null])->save();
            $resolved++;

            if ($landed && $change->kind === BillingPlanChange::KIND_IMMEDIATE) {
                $this->capacity->settle($change->tenant_id, $change->retained_store_ids);
            }
        }

        foreach (BillingPlanChange::query()->whereIn('status', [BillingPlanChange::STATUS_APPLIED, BillingPlanChange::STATUS_SCHEDULED, BillingPlanChange::STATUS_CANCELLED])->whereNull('discounts_settled_at')->where('updated_at', '>', $now->copy()->subDays(40))->get() as $change) {
            $discounts += $this->settleDiscounts($change) ? 1 : 0;
        }

        return ['applied' => $applied, 'resolved' => $resolved, 'discounts' => $discounts];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dto(BillingPlanChange $change): array
    {
        return [
            'id' => $change->id,
            'preview_token' => $change->status === BillingPlanChange::STATUS_PREVIEWED ? $change->id : null,
            'kind' => $change->kind,
            'status' => $change->status,
            'from' => self::planDto($change->from_price_id, $change->from_extra_store_quantity),
            'to' => self::planDto($change->to_price_id, $change->to_extra_store_quantity),
            'currency' => $change->currency,
            'currency_exponent' => $change->currency_exponent,
            'due_now_minor' => $change->due_now_minor,
            'next_amount_before_minor' => $change->next_amount_before_minor,
            'next_amount_minor' => $change->next_amount_minor,
            'effective_at' => $change->effective_at?->toJSON(),
            'expires_at' => $change->preview_expires_at->toJSON(),
            'retained_store_ids' => $change->retained_store_ids,
            'error_code' => $change->error_code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function planDto(string $priceId, int $extraStores): array
    {
        /** @var BillingPrice|null $price */
        $price = BillingPrice::query()->with('planVersion.plan')->find($priceId);

        return [
            'plan_code' => $price?->planVersion?->plan?->code,
            'plan_version_id' => $price?->plan_version_id,
            'price_id' => $priceId,
            'amount_minor' => $price === null ? null : (string) $price->amount_minor,
            'extra_store_quantity' => $extraStores,
        ];
    }

    private function settleDiscounts(BillingPlanChange $change): bool
    {
        /** @var BillingSubscriptionContract $contract */
        $contract = BillingSubscriptionContract::query()->findOrFail($change->contract_id);
        $expected = $change->status === BillingPlanChange::STATUS_CANCELLED
            ? [$change->from_price_id, $change->from_extra_store_quantity]
            : [$change->to_price_id, $change->to_extra_store_quantity];

        if ([$contract->price_id, (int) $contract->extra_store_quantity] !== $expected) {
            return false;
        }

        if ($this->ledger->replanNextPeriod($contract) === DiscountLedger::STATUS_FAILED) {
            return false;
        }

        $change->forceFill(['discounts_settled_at' => Carbon::now()])->save();

        return true;
    }

    private function activeContract(string $tenantId): BillingSubscriptionContract
    {
        $contract = $this->liveContract($tenantId);

        if ($contract === null || $contract->provider_subscription_ref === null) {
            throw new BillingCheckoutRejected('no_active_subscription');
        }

        if ($contract->normalized_state === BillingSubscriptionContract::STATE_PAST_DUE) {
            throw new BillingCheckoutRejected('subscription_past_due');
        }

        if ($contract->price_id === null || $contract->problem_code !== null) {
            throw new BillingCheckoutRejected('subscription_unknown');
        }

        return $contract;
    }

    /**
     * @param  list<string>|null  $requested
     * @return list<string>|null
     */
    private function retainedStores(string $tenantId, ?int $limit, ?array $requested): ?array
    {
        $stores = Store::query()->where('tenant_id', $tenantId)->whereNotIn('status', ['deleted', 'deleting']);
        $count = (clone $stores)->count();

        if ($limit === null || $count <= $limit) {
            return null;
        }

        $requested = array_values(array_unique($requested ?? []));

        if ($requested === []) {
            throw new BillingCheckoutRejected('retained_stores_required', ['limit' => $limit, 'stores' => $count]);
        }

        $valid = array_filter($requested, fn (string $id): bool => Str::isUuid($id));

        if (count($valid) !== count($requested) || count($requested) > $limit || (clone $stores)->whereKey($requested)->count() !== count($requested)) {
            throw new BillingCheckoutRejected('retained_stores_invalid', ['limit' => $limit, 'stores' => $count]);
        }

        return $requested;
    }

    private function recurringAmount(BillingPrice $base, int $extra): ?int
    {
        if ($extra === 0) {
            return (int) $base->amount_minor;
        }

        $price = $this->extraPriceOf($base);

        return $price === null ? null : (int) $base->amount_minor + (int) $price->amount_minor * $extra;
    }

    private function extraPriceOf(BillingPrice $base): ?BillingPrice
    {
        return BillingPrice::query()
            ->where('plan_version_id', $base->plan_version_id)
            ->where('price_kind', BillingPrice::KIND_EXTRA_STORE)
            ->where('provider', $base->provider)
            ->where('provider_account_ref', $base->provider_account_ref)
            ->where('mode', $base->mode)
            ->where('currency', $base->currency)
            ->where('billing_interval', $base->billing_interval)
            ->where('billing_interval_count', $base->billing_interval_count)
            ->whereNotNull('provider_price_ref')
            ->first();
    }

    /**
     * @return list<array{price_id: string, quantity: int}>
     */
    private function items(BillingPrice $base, ?BillingPrice $extra, int $extraStores): array
    {
        $items = [['price_id' => (string) $base->provider_price_ref, 'quantity' => 1]];

        if ($extra !== null && $extraStores > 0) {
            $items[] = ['price_id' => (string) $extra->provider_price_ref, 'quantity' => $extraStores];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function patchSubscription(BillingSubscriptionContract $contract, array $body): void
    {
        try {
            $this->client->patch('/subscriptions/'.rawurlencode((string) $contract->provider_subscription_ref), $body);
        } catch (PaddleRequestFailed $exception) {
            throw new BillingCheckoutRejected($exception->outcomeUnknown() ? 'provider_outcome_unknown' : 'plan_change_rejected');
        }
    }

    private function syncQuietly(string $subscriptionRef): bool
    {
        try {
            $this->sync->sync($subscriptionRef);

            return true;
        } catch (PaddleRequestFailed) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(string $tenantId, string $userId, string $action, string $entityId, array $changes): void
    {
        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $userId,
            'actor_type' => AuditLog::ACTOR_USER,
            'action' => $action,
            'entity_type' => 'billing_subscription',
            'entity_id' => $entityId,
            'changes' => $changes,
            'request_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);
    }
}
