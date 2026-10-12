<?php

namespace App\Support\Billing;

use App\Models\BillingDiscount;
use App\Models\BillingPlan;
use App\Models\Tenant;
use App\Support\Billing\Paddle\PaddleClient;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleRequestFailed;
use App\Support\Platform\PlatformAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use NumberFormatter;
use Throwable;

class DiscountAdmin
{
    public const MAX_PERIODS = 1000;

    public function __construct(
        private readonly PaddleClient $client,
        private readonly PlatformAudit $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function discounts(): array
    {
        return BillingDiscount::query()
            ->with('tenants:id,name')
            ->where('mode', PaddleConfig::mode())
            ->orderByRaw("case when state = 'active' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (BillingDiscount $discount): array => $this->dto($discount))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(string $staffId, array $input, string $reason): BillingDiscount
    {
        if (! PaddleConfig::configured()) {
            throw new PlanAdminRejected('billing_not_configured', 503);
        }

        $attributes = $this->validated($input, null);

        if ($attributes['code'] !== null && BillingDiscount::query()->where('mode', PaddleConfig::mode())->where('code', $attributes['code'])->exists()) {
            throw new PlanAdminRejected('discount_code_taken', 409);
        }

        try {
            $result = $this->client->post('/discounts', $this->paddlePayload($attributes) + [
                'type' => $attributes['kind'],
                'amount' => $attributes['kind'] === BillingDiscount::KIND_PERCENTAGE ? DiscountResolver::percent($attributes['percent_bps']) : (string) $attributes['amount_minor'],
                'currency_code' => $attributes['currency'],
                'enabled_for_checkout' => false,
                'recur' => $attributes['duration'] !== BillingDiscount::DURATION_ONCE,
                'maximum_recurring_intervals' => $attributes['duration'] === BillingDiscount::DURATION_PERIODS ? $attributes['duration_periods'] : null,
            ]);
        } catch (PaddleRequestFailed) {
            $this->audit->record($staffId, 'discount.created', 'billing_discount', $attributes['name'], PlatformAudit::RESULT_FAILED, ['stage' => 'provider'], $reason);

            throw new PlanAdminRejected('provider_unavailable', 502);
        }

        return DB::transaction(function () use ($staffId, $attributes, $reason, $result): BillingDiscount {
            $tenantIds = $attributes['tenant_ids'];
            unset($attributes['tenant_ids']);
            $discount = BillingDiscount::query()->create($attributes + [
                'provider' => PaddleConfig::PROVIDER,
                'provider_account_ref' => PaddleConfig::accountRef(),
                'mode' => PaddleConfig::mode(),
                'provider_discount_ref' => (string) $result['data']['id'],
                'state' => BillingDiscount::STATE_ACTIVE,
                'created_by' => $staffId,
            ]);
            $discount->tenants()->sync($tenantIds);
            $this->audit->record($staffId, 'discount.created', 'billing_discount', $discount->id, PlatformAudit::RESULT_SUCCEEDED, $this->auditChanges($discount, $tenantIds), $reason);

            return $discount->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input  label, plan_ids, tenant_ids, starts_at, expires_at, usage_limit
     */
    public function update(string $staffId, string $discountId, array $input, string $reason): BillingDiscount
    {
        $discount = BillingDiscount::query()->find($discountId) ?? throw new PlanAdminRejected('not_found', 404);

        if ($discount->state !== BillingDiscount::STATE_ACTIVE) {
            throw new PlanAdminRejected('discount_archived', 409);
        }

        $attributes = $this->validated($input, $discount);
        $tenantIds = $attributes['tenant_ids'];
        $editable = array_intersect_key($attributes, array_flip(['label', 'plan_ids', 'starts_at', 'expires_at', 'usage_limit', 'name']));

        try {
            $this->client->patch('/discounts/'.$discount->provider_discount_ref, $this->paddlePayload($editable + ['code' => $discount->code]));
        } catch (PaddleRequestFailed) {
            throw new PlanAdminRejected('provider_unavailable', 502);
        }

        return DB::transaction(function () use ($staffId, $discount, $editable, $tenantIds, $reason): BillingDiscount {
            $discount->forceFill($editable)->save();
            $discount->tenants()->sync($discount->audience === BillingDiscount::AUDIENCE_TENANT ? $tenantIds : []);
            $this->audit->record($staffId, 'discount.updated', 'billing_discount', $discount->id, PlatformAudit::RESULT_SUCCEEDED, $this->auditChanges($discount, $tenantIds), $reason);

            return $discount->refresh();
        });
    }

    public function archive(string $staffId, string $discountId, string $reason): BillingDiscount
    {
        $discount = BillingDiscount::query()->find($discountId) ?? throw new PlanAdminRejected('not_found', 404);

        if ($discount->state === BillingDiscount::STATE_ARCHIVED) {
            return $discount;
        }

        try {
            $this->client->patch('/discounts/'.$discount->provider_discount_ref, ['status' => 'archived']);
        } catch (PaddleRequestFailed) {
            throw new PlanAdminRejected('provider_unavailable', 502);
        }

        $discount->forceFill(['state' => BillingDiscount::STATE_ARCHIVED, 'archived_at' => Carbon::now()])->save();
        $this->audit->record($staffId, 'discount.archived', 'billing_discount', $discount->id, PlatformAudit::RESULT_SUCCEEDED, [], $reason);

        return $discount;
    }

    /**
     * @return array<string, mixed>
     */
    public function dto(BillingDiscount $discount): array
    {
        return DiscountResolver::dto($discount) + [
            'name' => $discount->name,
            'audience' => $discount->audience,
            'code' => $discount->code,
            'usage_limit' => $discount->usage_limit,
            'used' => DiscountResolver::used($discount->id),
            'plan_ids' => $discount->plan_ids,
            'tenants' => $discount->tenants->map(fn (Tenant $tenant): array => ['id' => $tenant->id, 'name' => $tenant->name])->values()->all(),
            'starts_at' => $discount->starts_at?->toJSON(),
            'state' => $discount->state,
            'mode' => $discount->mode,
            'created_at' => $discount->created_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input, ?BillingDiscount $existing): array
    {
        $name = trim(strip_tags((string) ($input['name'] ?? $existing?->name ?? '')));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new PlanAdminRejected('discount_name_invalid');
        }

        $label = [];

        foreach (PlanAdmin::LOCALES as $locale) {
            $text = trim(strip_tags((string) (($input['label'] ?? $existing?->label ?? [])[$locale] ?? '')));

            if (mb_strlen($text) > 120) {
                throw new PlanAdminRejected('plan_text_too_long');
            }

            if ($text !== '') {
                $label[$locale] = $text;
            }
        }

        $planIds = array_values(array_unique(array_map('strval', (array) ($input['plan_ids'] ?? $existing?->plan_ids ?? []))));

        if ($planIds !== [] && BillingPlan::query()->whereKey($planIds)->count() !== count($planIds)) {
            throw new PlanAdminRejected('discount_plan_unknown');
        }

        $startsAt = array_key_exists('starts_at', $input) ? $this->date($input['starts_at']) : $existing?->starts_at;
        $expiresAt = array_key_exists('expires_at', $input) ? $this->date($input['expires_at']) : $existing?->expires_at;

        if ($startsAt !== null && $expiresAt !== null && $expiresAt->lessThanOrEqualTo($startsAt)) {
            throw new PlanAdminRejected('discount_window_invalid');
        }

        if ($existing === null && $expiresAt !== null && $expiresAt->isPast()) {
            throw new PlanAdminRejected('discount_window_invalid');
        }

        $usageLimit = array_key_exists('usage_limit', $input) ? $input['usage_limit'] : $existing?->usage_limit;

        if ($usageLimit !== null && (! is_int($usageLimit) || $usageLimit < 1)) {
            throw new PlanAdminRejected('discount_usage_limit_invalid');
        }

        $audience = $existing?->audience ?? (string) ($input['audience'] ?? '');
        $tenantIds = array_values(array_unique(array_map('strval', (array) ($input['tenant_ids'] ?? ($existing === null ? [] : $existing->tenants()->pluck('tenants.id')->all())))));

        if ($audience === BillingDiscount::AUDIENCE_TENANT && ($tenantIds === [] || Tenant::query()->whereKey($tenantIds)->count() !== count($tenantIds))) {
            throw new PlanAdminRejected('discount_tenants_invalid');
        }

        $attributes = [
            'name' => $name,
            'label' => $label,
            'plan_ids' => $planIds,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'usage_limit' => $usageLimit,
            'tenant_ids' => $audience === BillingDiscount::AUDIENCE_TENANT ? $tenantIds : [],
        ];

        if ($existing !== null) {
            return $attributes;
        }

        if (! in_array($audience, [BillingDiscount::AUDIENCE_CODE, BillingDiscount::AUDIENCE_TENANT, BillingDiscount::AUDIENCE_AUTOMATIC], true)) {
            throw new PlanAdminRejected('discount_audience_invalid');
        }

        $code = $audience === BillingDiscount::AUDIENCE_CODE ? DiscountResolver::normalizeCode((string) ($input['code'] ?? '')) : null;

        if ($code !== null && preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/', $code) !== 1) {
            throw new PlanAdminRejected('discount_code_invalid_format');
        }

        $kind = (string) ($input['kind'] ?? '');
        $percentBps = null;
        $amountMinor = null;
        $currency = null;
        $exponent = null;

        if ($kind === BillingDiscount::KIND_PERCENTAGE) {
            $percentBps = $input['percent_bps'] ?? null;

            if (! is_int($percentBps) || $percentBps < 1 || $percentBps > 10000) {
                throw new PlanAdminRejected('discount_amount_invalid');
            }
        } elseif ($kind === BillingDiscount::KIND_FLAT) {
            $currency = strtoupper((string) ($input['currency'] ?? ''));

            if (! in_array($currency, PlanAdmin::CURRENCIES, true)) {
                throw new PlanAdminRejected('price_currency_invalid');
            }

            $amountMinor = (string) ($input['amount_minor'] ?? '');

            if (preg_match('/^[1-9][0-9]{0,11}$/', $amountMinor) !== 1) {
                throw new PlanAdminRejected('discount_amount_invalid');
            }

            $exponent = (int) (new NumberFormatter('en@currency='.$currency, NumberFormatter::CURRENCY))->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS);
        } else {
            throw new PlanAdminRejected('discount_kind_invalid');
        }

        $duration = (string) ($input['duration'] ?? '');
        $periods = $input['duration_periods'] ?? null;

        if (! in_array($duration, [BillingDiscount::DURATION_ONCE, BillingDiscount::DURATION_PERIODS, BillingDiscount::DURATION_FOREVER], true)
            || ($duration === BillingDiscount::DURATION_PERIODS && (! is_int($periods) || $periods < 1 || $periods > self::MAX_PERIODS))) {
            throw new PlanAdminRejected('discount_duration_invalid');
        }

        return $attributes + [
            'audience' => $audience,
            'code' => $code,
            'kind' => $kind,
            'percent_bps' => $percentBps,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'currency_exponent' => $exponent,
            'duration' => $duration,
            'duration_periods' => $duration === BillingDiscount::DURATION_PERIODS ? $periods : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function paddlePayload(array $attributes): array
    {
        return [
            'description' => mb_substr('BW '.($attributes['code'] ?? '').' '.$attributes['name'], 0, 500),
            'usage_limit' => $attributes['usage_limit'],
            'expires_at' => $attributes['expires_at']?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc();
        } catch (Throwable) {
            throw new PlanAdminRejected('discount_window_invalid');
        }
    }

    /**
     * @param  list<string>  $tenantIds
     * @return array<string, mixed>
     */
    private function auditChanges(BillingDiscount $discount, array $tenantIds): array
    {
        return [
            'audience' => $discount->audience, 'kind' => $discount->kind, 'percent_bps' => $discount->percent_bps, 'amount_minor' => $discount->amount_minor, 'currency' => $discount->currency,
            'duration' => $discount->duration, 'duration_periods' => $discount->duration_periods, 'usage_limit' => $discount->usage_limit, 'plan_ids' => $discount->plan_ids,
            'tenant_ids' => $tenantIds, 'starts_at' => $discount->starts_at?->toJSON(), 'expires_at' => $discount->expires_at?->toJSON(),
        ];
    }
}
