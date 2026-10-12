<?php

namespace App\Support\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingDiscount;
use App\Models\BillingPrice;
use App\Support\Billing\Paddle\PaddleConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DiscountResolver
{
    public function forCheckout(string $tenantId, BillingPrice $base, ?string $code): ?BillingDiscount
    {
        $code = $code === null ? '' : self::normalizeCode($code);

        if ($code !== '') {
            /** @var BillingDiscount|null $discount */
            $discount = $this->current()->where('audience', BillingDiscount::AUDIENCE_CODE)->where('code', $code)->first();

            if ($discount === null) {
                throw new BillingCheckoutRejected('discount_code_invalid');
            }

            $reason = $this->unavailableReason($discount, $base);

            if ($reason !== null) {
                throw new BillingCheckoutRejected($reason);
            }

            return $discount;
        }

        return $this->bestAutomatic($tenantId, $base);
    }

    public function bestAutomatic(string $tenantId, BillingPrice $base): ?BillingDiscount
    {
        $assigned = $this->current()
            ->where('audience', BillingDiscount::AUDIENCE_TENANT)
            ->whereHas('tenants', fn (Builder $query) => $query->whereKey($tenantId))
            ->get()
            ->filter(fn (BillingDiscount $discount): bool => $this->unavailableReason($discount, $base) === null);

        $candidates = $assigned->isNotEmpty()
            ? $assigned
            : $this->current()->where('audience', BillingDiscount::AUDIENCE_AUTOMATIC)->get()->filter(fn (BillingDiscount $discount): bool => $this->unavailableReason($discount, $base) === null);

        return $candidates
            ->sortBy([fn (BillingDiscount $a, BillingDiscount $b): int => self::saving($b, $base) <=> self::saving($a, $base), fn (BillingDiscount $a, BillingDiscount $b): int => strcmp((string) $a->id, (string) $b->id)])
            ->first();
    }

    public function unavailableReason(BillingDiscount $discount, BillingPrice $base, ?Carbon $now = null): ?string
    {
        $now ??= Carbon::now();

        if ($discount->state !== BillingDiscount::STATE_ACTIVE || $discount->provider_discount_ref === null || ($discount->starts_at !== null && $discount->starts_at->greaterThan($now))) {
            return 'discount_code_invalid';
        }

        if ($discount->expires_at !== null && $discount->expires_at->lessThanOrEqualTo($now)) {
            return 'discount_expired';
        }

        $planId = $base->planVersion?->plan_id;

        if ($discount->plan_ids !== [] && ! in_array($planId, $discount->plan_ids, true)) {
            return 'discount_not_applicable';
        }

        if ($discount->kind === BillingDiscount::KIND_FLAT && $discount->currency !== $base->currency) {
            return 'discount_not_applicable';
        }

        if ($discount->usage_limit !== null && self::used($discount->id) >= $discount->usage_limit) {
            return 'discount_used_up';
        }

        return null;
    }

    public static function used(string $discountId): int
    {
        return BillingCheckoutSession::query()->where('discount_id', $discountId)->where('status', BillingCheckoutSession::STATUS_CONFIRMED)->count();
    }

    public static function saving(BillingDiscount $discount, BillingPrice $base): int
    {
        if ($discount->kind === BillingDiscount::KIND_FLAT) {
            return min((int) $discount->amount_minor, (int) $base->amount_minor);
        }

        return intdiv((int) $base->amount_minor * (int) $discount->percent_bps + 5000, 10000);
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * @return Builder<BillingDiscount>
     */
    private function current(): Builder
    {
        return BillingDiscount::query()
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->where('state', BillingDiscount::STATE_ACTIVE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function dto(BillingDiscount $discount): array
    {
        return [
            'id' => $discount->id,
            'label' => $discount->label,
            'kind' => $discount->kind,
            'percent' => $discount->percent_bps === null ? null : self::percent($discount->percent_bps),
            'amount_minor' => $discount->amount_minor === null ? null : (string) $discount->amount_minor,
            'currency' => $discount->currency,
            'currency_exponent' => $discount->currency_exponent,
            'duration' => $discount->duration,
            'duration_periods' => $discount->duration_periods,
            'expires_at' => $discount->expires_at?->toJSON(),
        ];
    }

    public static function percent(int $bps): string
    {
        $fraction = rtrim(str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT), '0');

        return intdiv($bps, 100).($fraction === '' ? '' : '.'.$fraction);
    }
}
