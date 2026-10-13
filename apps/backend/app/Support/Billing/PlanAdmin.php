<?php

namespace App\Support\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\Subscription;
use App\Support\Billing\Paddle\PaddleCatalogSync;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Platform\PlatformAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use NumberFormatter;
use Throwable;

class PlanAdmin
{
    public const LOCALES = ['ru', 'en', 'de'];

    public const INTERVALS = ['day', 'week', 'month', 'year'];

    public const MAX_INTERVAL_COUNT = 365;

    public const MAX_TRIAL_DAYS = 365;

    public const MAX_GRACE_DAYS = 365;

    public const MIN_FASTER_CHECK_MINUTES = 5;

    public const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF', 'PLN', 'CZK', 'SEK', 'NOK', 'DKK', 'HUF', 'UAH', 'CAD', 'AUD', 'NZD', 'JPY', 'CNY', 'HKD', 'SGD', 'INR', 'KRW', 'TWD', 'THB', 'VND', 'ILS', 'TRY', 'ZAR', 'BRL', 'MXN', 'ARS', 'COP'];

    private const LIMIT_MINIMUMS = [
        FeatureCatalog::MAX_ACTIVE_STORES => 1,
        FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 5,
        FeatureCatalog::RETENTION_DAYS => 1,
        FeatureCatalog::MAX_ORDERS_PER_MONTH => 1,
    ];

    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly PaddleCatalogSync $catalogSync,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function plans(): array
    {
        $subscribers = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_TRIAL, Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->whereNotNull('plan_version_id')
            ->selectRaw('plan_version_id, count(*) as total')
            ->groupBy('plan_version_id')
            ->pluck('total', 'plan_version_id');

        return BillingPlan::query()
            ->with(['versions' => fn ($query) => $query->orderByDesc('version')])
            ->orderBy('code')
            ->get()
            ->map(fn (BillingPlan $plan): array => [
                'id' => $plan->id,
                'code' => $plan->code,
                'state' => $plan->state,
                'versions' => $plan->versions->map(fn (BillingPlanVersion $version): array => $this->versionDto($version, (int) ($subscribers[$version->id] ?? 0)))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $name
     */
    public function createPlan(string $staffId, string $code, array $name): BillingPlanVersion
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $code) !== 1) {
            throw new PlanAdminRejected('plan_code_invalid');
        }

        return DB::transaction(function () use ($staffId, $code, $name): BillingPlanVersion {
            if (BillingPlan::query()->where('code', $code)->exists()) {
                throw new PlanAdminRejected('plan_code_taken', 409);
            }

            $plan = BillingPlan::query()->create(['code' => $code, 'state' => BillingPlan::STATE_DRAFT]);
            $version = BillingPlanVersion::query()->create([
                'plan_id' => $plan->id,
                'version' => 1,
                'state' => BillingPlan::STATE_DRAFT,
                'localized_content' => ['name' => $this->texts($name, 80), 'description' => []],
                'entitlements' => FeatureCatalog::normalize(['features' => [], 'limits' => []]),
                'trial_days' => 14,
                'created_by' => $staffId,
            ]);
            $this->audit->record($staffId, 'plan.created', 'billing_plan', $plan->id, PlatformAudit::RESULT_SUCCEEDED, ['code' => $code]);

            return $version;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  name, description, trial_days, entitlements
     */
    public function updateDraft(string $staffId, string $versionId, array $changes): BillingPlanVersion
    {
        return DB::transaction(function () use ($staffId, $versionId, $changes): BillingPlanVersion {
            $version = $this->lockedDraft($versionId);
            $content = $version->localized_content;

            if (array_key_exists('name', $changes)) {
                $content['name'] = $this->texts((array) $changes['name'], 80);
            }

            if (array_key_exists('description', $changes)) {
                $content['description'] = $this->texts((array) $changes['description'], 500);
            }

            unset($content['placeholder']);
            $attributes = ['localized_content' => $content];

            if (array_key_exists('trial_days', $changes)) {
                $trial = $changes['trial_days'];

                if (! is_int($trial) || $trial < 0 || $trial > self::MAX_TRIAL_DAYS) {
                    throw new PlanAdminRejected('trial_days_invalid');
                }

                $attributes['trial_days'] = $trial;
            }

            if (array_key_exists('entitlements', $changes)) {
                $attributes['entitlements'] = $this->entitlements((array) $changes['entitlements']);
            }

            if (array_key_exists('order_volume_grace_days', $changes)) {
                $grace = $changes['order_volume_grace_days'];

                if (! is_int($grace) || $grace < 0 || $grace > self::MAX_GRACE_DAYS) {
                    throw new PlanAdminRejected('grace_days_invalid');
                }

                $attributes['order_volume_grace_days'] = $grace;
            }

            if (array_key_exists('contact_sales', $changes)) {
                $attributes['contact_sales'] = (bool) $changes['contact_sales'];
            }

            if (array_key_exists('faster_check_interval_minutes', $changes)) {
                $minutes = $changes['faster_check_interval_minutes'];

                if ($minutes !== null && (! is_int($minutes) || $minutes < self::MIN_FASTER_CHECK_MINUTES || $minutes > 1440)) {
                    throw new PlanAdminRejected('faster_check_interval_invalid');
                }

                $attributes['faster_check_interval_minutes'] = $minutes;
            }

            $version->forceFill($attributes)->save();
            $this->audit->record($staffId, 'plan_version.updated', 'billing_plan_version', $version->id, PlatformAudit::RESULT_SUCCEEDED, ['fields' => array_keys($changes)]);

            return $version->refresh();
        });
    }

    public function addPrice(string $staffId, string $versionId, string $kind, string $currency, string $amountMinor, string $interval, int $intervalCount): BillingPrice
    {
        $currency = strtoupper($currency);
        $exponent = $this->exponent($currency);

        if (! in_array($kind, [BillingPrice::KIND_BASE, BillingPrice::KIND_EXTRA_STORE, BillingPrice::KIND_FASTER_CHECKS], true)) {
            throw new PlanAdminRejected('price_kind_invalid');
        }

        if (preg_match('/^(0|[1-9][0-9]{0,11})$/', $amountMinor) !== 1) {
            throw new PlanAdminRejected('price_amount_invalid');
        }

        if (! in_array($interval, self::INTERVALS, true) || $intervalCount < 1 || $intervalCount > self::MAX_INTERVAL_COUNT) {
            throw new PlanAdminRejected('price_interval_invalid');
        }

        return DB::transaction(function () use ($staffId, $versionId, $kind, $currency, $exponent, $amountMinor, $interval, $intervalCount): BillingPrice {
            $version = $this->lockedDraft($versionId);
            $duplicate = BillingPrice::query()
                ->where('plan_version_id', $version->id)
                ->where('price_kind', $kind)
                ->where('currency', $currency)
                ->where('billing_interval', $interval)
                ->where('billing_interval_count', $intervalCount)
                ->where('mode', PaddleConfig::mode())
                ->exists();

            if ($duplicate) {
                throw new PlanAdminRejected('price_exists', 409);
            }

            $price = BillingPrice::query()->create([
                'plan_version_id' => $version->id,
                'provider' => PaddleConfig::PROVIDER,
                'provider_account_ref' => PaddleConfig::accountRef(),
                'price_kind' => $kind,
                'mode' => PaddleConfig::mode(),
                'currency' => $currency,
                'currency_exponent' => $exponent,
                'amount_minor' => $amountMinor,
                'billing_interval' => $interval,
                'billing_interval_count' => $intervalCount,
                'tax_behavior' => 'provider_calculated',
                'state' => BillingPlan::STATE_DRAFT,
            ]);
            $this->audit->record($staffId, 'plan_price.added', 'billing_price', $price->id, PlatformAudit::RESULT_SUCCEEDED, [
                'plan_version_id' => $version->id, 'kind' => $kind, 'currency' => $currency, 'amount_minor' => $amountMinor, 'interval' => $interval, 'interval_count' => $intervalCount,
            ]);

            return $price;
        });
    }

    public function removePrice(string $staffId, string $priceId): void
    {
        DB::transaction(function () use ($staffId, $priceId): void {
            /** @var BillingPrice|null $price */
            $price = BillingPrice::query()->whereKey($priceId)->lockForUpdate()->first();

            if ($price === null) {
                throw new PlanAdminRejected('not_found', 404);
            }

            $this->lockedDraft($price->plan_version_id);

            if ($price->provider_price_ref !== null) {
                throw new PlanAdminRejected('price_already_at_provider', 409);
            }

            $price->delete();
            $this->audit->record($staffId, 'plan_price.removed', 'billing_price', $priceId, PlatformAudit::RESULT_SUCCEEDED, ['plan_version_id' => $price->plan_version_id]);
        });
    }

    public function newVersion(string $staffId, string $planId): BillingPlanVersion
    {
        return DB::transaction(function () use ($staffId, $planId): BillingPlanVersion {
            /** @var BillingPlan|null $plan */
            $plan = BillingPlan::query()->whereKey($planId)->lockForUpdate()->first();

            if ($plan === null) {
                throw new PlanAdminRejected('not_found', 404);
            }

            /** @var BillingPlanVersion $latest */
            $latest = BillingPlanVersion::query()->where('plan_id', $plan->id)->orderByDesc('version')->firstOrFail();

            if ($latest->state === BillingPlan::STATE_DRAFT) {
                throw new PlanAdminRejected('draft_exists', 409);
            }

            $content = $latest->localized_content;
            unset($content['placeholder']);
            $version = BillingPlanVersion::query()->create([
                'plan_id' => $plan->id,
                'version' => $latest->version + 1,
                'state' => BillingPlan::STATE_DRAFT,
                'localized_content' => $content,
                'entitlements' => $latest->entitlements,
                'trial_days' => $latest->trial_days,
                'order_volume_grace_days' => $latest->order_volume_grace_days,
                'contact_sales' => $latest->contact_sales,
                'faster_check_interval_minutes' => $latest->faster_check_interval_minutes,
                'created_by' => $staffId,
            ]);

            foreach (BillingPrice::query()->where('plan_version_id', $latest->id)->where('mode', PaddleConfig::mode())->get() as $price) {
                BillingPrice::query()->create([
                    'plan_version_id' => $version->id,
                    'provider' => $price->provider,
                    'provider_account_ref' => $price->provider_account_ref,
                    'price_kind' => $price->price_kind,
                    'mode' => $price->mode,
                    'currency' => $price->currency,
                    'currency_exponent' => $price->currency_exponent,
                    'amount_minor' => $price->amount_minor,
                    'billing_interval' => $price->billing_interval,
                    'billing_interval_count' => $price->billing_interval_count,
                    'tax_behavior' => $price->tax_behavior,
                    'state' => BillingPlan::STATE_DRAFT,
                ]);
            }

            $this->audit->record($staffId, 'plan_version.created', 'billing_plan_version', $version->id, PlatformAudit::RESULT_SUCCEEDED, ['plan_id' => $plan->id, 'from_version' => $latest->version]);

            return $version;
        });
    }

    public function publish(string $staffId, string $versionId, string $reason): BillingPlanVersion
    {
        $version = BillingPlanVersion::query()->with('plan')->find($versionId) ?? throw new PlanAdminRejected('not_found', 404);

        if ($version->state !== BillingPlan::STATE_DRAFT) {
            throw new PlanAdminRejected('version_not_draft', 409);
        }

        $this->assertPublishable($version);

        if (! PaddleConfig::configured()) {
            throw new PlanAdminRejected('billing_not_configured', 503);
        }

        try {
            $this->catalogSync->sync($version->id);
        } catch (Throwable $exception) {
            $this->audit->record($staffId, 'plan_version.published', 'billing_plan_version', $version->id, PlatformAudit::RESULT_FAILED, ['stage' => 'provider'], $reason);

            throw new PlanAdminRejected('provider_unavailable', 502);
        }

        return DB::transaction(function () use ($staffId, $versionId, $reason): BillingPlanVersion {
            $version = $this->lockedDraft($versionId);
            $now = Carbon::now();
            $unsynced = BillingPrice::query()->where('plan_version_id', $version->id)->where('mode', PaddleConfig::mode())->whereNull('provider_price_ref')->exists();

            if ($unsynced) {
                throw new PlanAdminRejected('provider_unavailable', 502);
            }

            $previous = BillingPlanVersion::query()->where('plan_id', $version->plan_id)->where('state', BillingPlan::STATE_PUBLISHED)->pluck('id');
            BillingPlanVersion::query()->whereKey($previous)->update(['state' => BillingPlan::STATE_RETIRED]);
            BillingPrice::query()->whereIn('plan_version_id', $previous)->where('state', BillingPlan::STATE_PUBLISHED)->update(['state' => BillingPlan::STATE_RETIRED]);

            $version->forceFill(['state' => BillingPlan::STATE_PUBLISHED, 'published_at' => $now])->save();
            BillingPrice::query()->where('plan_version_id', $version->id)->where('state', BillingPlan::STATE_DRAFT)->update(['state' => BillingPlan::STATE_PUBLISHED]);
            BillingPlan::query()->whereKey($version->plan_id)->update(['state' => BillingPlan::STATE_PUBLISHED]);
            $this->audit->record($staffId, 'plan_version.published', 'billing_plan_version', $version->id, PlatformAudit::RESULT_SUCCEEDED, ['retired_versions' => $previous->all()], $reason);

            return $version->refresh();
        });
    }

    public function retire(string $staffId, string $versionId, string $reason): BillingPlanVersion
    {
        return DB::transaction(function () use ($staffId, $versionId, $reason): BillingPlanVersion {
            /** @var BillingPlanVersion|null $version */
            $version = BillingPlanVersion::query()->whereKey($versionId)->lockForUpdate()->first();

            if ($version === null) {
                throw new PlanAdminRejected('not_found', 404);
            }

            if ($version->state !== BillingPlan::STATE_PUBLISHED) {
                throw new PlanAdminRejected('version_not_published', 409);
            }

            $version->forceFill(['state' => BillingPlan::STATE_RETIRED])->save();
            BillingPrice::query()->where('plan_version_id', $version->id)->where('state', BillingPlan::STATE_PUBLISHED)->update(['state' => BillingPlan::STATE_RETIRED]);

            if (! BillingPlanVersion::query()->where('plan_id', $version->plan_id)->where('state', BillingPlan::STATE_PUBLISHED)->exists()) {
                BillingPlan::query()->whereKey($version->plan_id)->update(['state' => BillingPlan::STATE_RETIRED]);
            }

            $this->audit->record($staffId, 'plan_version.retired', 'billing_plan_version', $version->id, PlatformAudit::RESULT_SUCCEEDED, [], $reason);

            return $version->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function versionDto(BillingPlanVersion $version, int $subscribers): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'state' => $version->state,
            'name' => $version->localized_content['name'] ?? [],
            'description' => $version->localized_content['description'] ?? [],
            'placeholder' => (bool) ($version->localized_content['placeholder'] ?? false),
            'trial_days' => $version->trial_days,
            'order_volume_grace_days' => $version->order_volume_grace_days,
            'contact_sales' => (bool) $version->contact_sales,
            'faster_check_interval_minutes' => $version->faster_check_interval_minutes,
            'entitlements' => FeatureCatalog::normalize($version->entitlements),
            'published_at' => $version->published_at?->toJSON(),
            'subscribers' => $subscribers,
            'prices' => BillingPrice::query()
                ->where('plan_version_id', $version->id)
                ->orderBy('price_kind')
                ->orderBy('currency')
                ->orderBy('created_at')
                ->get()
                ->map(fn (BillingPrice $price): array => [
                    'id' => $price->id,
                    'kind' => $price->price_kind,
                    'mode' => $price->mode,
                    'currency' => $price->currency,
                    'currency_exponent' => $price->currency_exponent,
                    'amount_minor' => (string) $price->amount_minor,
                    'interval' => $price->billing_interval,
                    'interval_count' => $price->billing_interval_count,
                    'state' => $price->state,
                    'at_provider' => $price->provider_price_ref !== null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function lockedDraft(string $versionId): BillingPlanVersion
    {
        /** @var BillingPlanVersion|null $version */
        $version = BillingPlanVersion::query()->whereKey($versionId)->lockForUpdate()->first();

        if ($version === null) {
            throw new PlanAdminRejected('not_found', 404);
        }

        if ($version->state !== BillingPlan::STATE_DRAFT) {
            throw new PlanAdminRejected('version_not_draft', 409);
        }

        return $version;
    }

    private function assertPublishable(BillingPlanVersion $version): void
    {
        foreach (self::LOCALES as $locale) {
            if (trim((string) ($version->localized_content['name'][$locale] ?? '')) === '') {
                throw new PlanAdminRejected('plan_name_missing');
            }
        }

        $this->entitlements($version->entitlements);
        $hasBase = BillingPrice::query()
            ->where('plan_version_id', $version->id)
            ->where('mode', PaddleConfig::mode())
            ->where('price_kind', BillingPrice::KIND_BASE)
            ->exists();

        if (! $hasBase && ! $version->contact_sales) {
            throw new PlanAdminRejected('base_price_missing');
        }

        $hasFaster = BillingPrice::query()->where('plan_version_id', $version->id)->where('mode', PaddleConfig::mode())->where('price_kind', BillingPrice::KIND_FASTER_CHECKS)->exists();
        $planInterval = FeatureCatalog::normalize($version->entitlements)['limits'][FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES] ?? null;

        if ($hasFaster && ($version->faster_check_interval_minutes === null || $planInterval === null || $version->faster_check_interval_minutes >= $planInterval)) {
            throw new PlanAdminRejected('faster_check_interval_invalid');
        }
    }

    /**
     * @param  array<string, mixed>  $entitlements
     * @return array{features: list<string>, limits: array<string, ?int>}
     */
    private function entitlements(array $entitlements): array
    {
        try {
            $normalized = FeatureCatalog::normalize($entitlements);
        } catch (InvalidArgumentException $exception) {
            throw new PlanAdminRejected($exception->getMessage());
        }

        foreach (self::LIMIT_MINIMUMS as $limit => $minimum) {
            if (($normalized['limits'][$limit] ?? null) !== null && $normalized['limits'][$limit] < $minimum) {
                throw new PlanAdminRejected('entitlement_limit_invalid');
            }
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $texts
     * @return array<string, string>
     */
    private function texts(array $texts, int $maxLength): array
    {
        $clean = [];

        foreach (self::LOCALES as $locale) {
            $value = trim(strip_tags((string) ($texts[$locale] ?? '')));

            if (mb_strlen($value) > $maxLength) {
                throw new PlanAdminRejected('plan_text_too_long');
            }

            if ($value !== '') {
                $clean[$locale] = $value;
            }
        }

        return $clean;
    }

    private function exponent(string $currency): int
    {
        if (! in_array($currency, self::CURRENCIES, true)) {
            throw new PlanAdminRejected('price_currency_invalid');
        }

        $formatter = new NumberFormatter('en@currency='.$currency, NumberFormatter::CURRENCY);
        $exponent = $formatter->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS);

        return is_int($exponent) ? $exponent : 2;
    }
}
