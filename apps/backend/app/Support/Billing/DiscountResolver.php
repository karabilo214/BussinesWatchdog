<?php

namespace App\Support\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingDiscount;
use App\Models\BillingPrice;
use App\Support\Billing\Paddle\PaddleConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DiscountResolver
{
    public const POSITIONS = [
        BillingDiscount::AUDIENCE_AUTOMATIC => 1,
        BillingDiscount::AUDIENCE_TENANT => 2,
        BillingDiscount::AUDIENCE_CODE => 3,
    ];

    /**
     * @return list<BillingDiscount>
     */
    public function stack(string $tenantId, BillingPrice $base, ?string $code): array
    {
        $stack = $this->automatic($tenantId, $base);
        $code = $code === null ? '' : self::normalizeCode($code);

        if ($code !== '') {
            $stack[] = $this->byCode($base, $code);
        }

        return $stack;
    }

    public function byCode(BillingPrice $base, string $code): BillingDiscount
    {
        /** @var BillingDiscount|null $discount */
        $discount = $this->current()->where('audience', BillingDiscount::AUDIENCE_CODE)->where('code', self::normalizeCode($code))->first();

        if ($discount === null) {
            throw new BillingCheckoutRejected('discount_code_invalid');
        }

        $reason = $this->unavailableReason($discount, $base);

        if ($reason !== null) {
            throw new BillingCheckoutRejected($reason);
        }

        return $discount;
    }

    /**
     * @return list<BillingDiscount>
     */
    public function automatic(string $tenantId, BillingPrice $base): array
    {
        $offer = $this->best($this->current()->where('audience', BillingDiscount::AUDIENCE_AUTOMATIC), $base);
        $personal = $this->best($this->current()->where('audience', BillingDiscount::AUDIENCE_TENANT)->whereHas('tenants', fn (Builder $query) => $query->whereKey($tenantId)), $base);

        return array_values(array_filter([$offer, $personal]));
    }

    /**
     * @param  list<BillingDiscount>  $discounts
     * @return array{amount_before: int, saving: int, amount_after: int, lines: list<array{discount: BillingDiscount, position: int, amount_before: int, saving: int, amount_after: int}>}
     */
    public static function calculate(array $discounts, int $amountBefore): array
    {
        usort($discounts, fn (BillingDiscount $a, BillingDiscount $b): int => self::POSITIONS[$a->audience] <=> self::POSITIONS[$b->audience]);
        $remaining = $amountBefore;
        $lines = [];

        foreach ($discounts as $discount) {
            $saving = self::savingOn($discount, $remaining);
            $lines[] = ['discount' => $discount, 'position' => self::POSITIONS[$discount->audience], 'amount_before' => $remaining, 'saving' => $saving, 'amount_after' => $remaining - $saving];
            $remaining -= $saving;
        }

        return ['amount_before' => $amountBefore, 'saving' => $amountBefore - $remaining, 'amount_after' => $remaining, 'lines' => $lines];
    }

    public static function savingOn(BillingDiscount $discount, int $amount): int
    {
        if ($discount->kind === BillingDiscount::KIND_FLAT) {
            return min((int) $discount->amount_minor, $amount);
        }

        return min($amount, intdiv($amount * (int) $discount->percent_bps + 5000, 10000));
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
        return DB::table('billing_discount_grants')
            ->join('billing_checkout_sessions', 'billing_checkout_sessions.id', '=', 'billing_discount_grants.checkout_session_id')
            ->where('billing_discount_grants.discount_id', $discountId)
            ->where('billing_checkout_sessions.status', BillingCheckoutSession::STATUS_CONFIRMED)
            ->count();
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function periods(BillingDiscount $discount): ?int
    {
        return match ($discount->duration) {
            BillingDiscount::DURATION_ONCE => 1,
            BillingDiscount::DURATION_PERIODS => $discount->duration_periods,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function dto(BillingDiscount $discount): array
    {
        return [
            'id' => $discount->id,
            'position' => self::POSITIONS[$discount->audience],
            'audience' => $discount->audience,
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

    private function best(Builder $query, BillingPrice $base): ?BillingDiscount
    {
        return $query->get()
            ->filter(fn (BillingDiscount $discount): bool => $this->unavailableReason($discount, $base) === null)
            ->sortBy([fn (BillingDiscount $a, BillingDiscount $b): int => self::savingOn($b, (int) $base->amount_minor) <=> self::savingOn($a, (int) $base->amount_minor), fn (BillingDiscount $a, BillingDiscount $b): int => strcmp((string) $a->id, (string) $b->id)])
            ->first();
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
}
