<?php

namespace App\Support\Platform;

use App\Models\BillingPrice;
use App\Models\BillingRefund;
use App\Models\BillingSubscriptionContract;
use App\Models\BillingTransaction;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BusinessOverview
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $mode, Carbon $from, Carbon $to, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $length = $from->diffInSeconds($to);
        $previousFrom = $from->copy()->subSeconds($length);
        $paying = $this->payingContracts($mode, $now);

        return [
            'mode' => $mode,
            'from' => $from->toJSON(),
            'to' => $to->toJSON(),
            'previous' => ['from' => $previousFrom->toJSON(), 'to' => $from->toJSON()],
            'as_of' => $now->toJSON(),
            'last_synced_at' => $this->lastSynced($mode),
            'customers' => [
                'paying' => $paying->pluck('tenant_id')->unique()->count(),
                'trial' => Subscription::query()->where('provider', 'internal')->where('status', Subscription::STATUS_TRIAL)->where('trial_ends_at', '>', $now)->distinct()->count('tenant_id'),
                'past_due' => BillingSubscriptionContract::query()->where('mode', $mode)->whereIn('normalized_state', [BillingSubscriptionContract::STATE_PAST_DUE, BillingSubscriptionContract::STATE_UNPAID])->distinct()->count('tenant_id'),
                'cancel_scheduled' => $paying->where('cancel_at_period_end', true)->pluck('tenant_id')->unique()->count(),
            ],
            'mrr' => $this->mrr($paying),
            'money' => $this->money($mode, $from, $to),
            'money_previous' => $this->money($mode, $previousFrom, $from),
            'churn' => $this->churn($mode, $from, $to),
            'churn_previous' => $this->churn($mode, $previousFrom, $from),
        ];
    }

    /**
     * @return Collection<int, BillingSubscriptionContract>
     */
    private function payingContracts(string $mode, Carbon $now): Collection
    {
        return BillingSubscriptionContract::query()
            ->where('mode', $mode)
            ->where('normalized_state', BillingSubscriptionContract::STATE_ACTIVE)
            ->where(fn ($paid) => $paid->whereNull('paid_until')->orWhere('paid_until', '>', $now))
            ->get();
    }

    /**
     * @param  Collection<int, BillingSubscriptionContract>  $contracts
     * @return list<array<string, mixed>>
     */
    private function mrr(Collection $contracts): array
    {
        $base = BillingPrice::query()->whereIn('id', $contracts->pluck('price_id')->filter()->unique())->get()->keyBy('id');
        $addons = BillingPrice::query()
            ->whereIn('plan_version_id', $base->pluck('plan_version_id')->unique())
            ->whereIn('price_kind', [BillingPrice::KIND_EXTRA_STORE, BillingPrice::KIND_FASTER_CHECKS])
            ->get();
        $discounted = DB::table('billing_discount_grants')
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->where(fn ($active) => $active->whereNull('periods_total')->orWhereColumn('periods_used', '<', 'periods_total'))
            ->distinct()->pluck('contract_id')->all();
        $totals = [];
        $unpriced = 0;

        foreach ($contracts as $contract) {
            /** @var BillingPrice|null $price */
            $price = $base->get($contract->price_id);

            if ($price === null) {
                $unpriced++;

                continue;
            }

            $same = fn (string $kind): ?BillingPrice => $addons->first(fn (BillingPrice $addon): bool => $addon->plan_version_id === $price->plan_version_id && $addon->price_kind === $kind
                && $addon->mode === $price->mode && $addon->currency === $price->currency && $addon->billing_interval === $price->billing_interval && (int) $addon->billing_interval_count === (int) $price->billing_interval_count);
            $amount = (int) $price->amount_minor;
            $extra = $same(BillingPrice::KIND_EXTRA_STORE);
            $faster = $same(BillingPrice::KIND_FASTER_CHECKS);
            $amount += $extra === null ? 0 : (int) $extra->amount_minor * max(0, (int) $contract->extra_store_quantity);
            $amount += $faster !== null && $contract->faster_checks ? (int) $faster->amount_minor : 0;
            $months = max(1, (int) $price->billing_interval_count) * ($price->billing_interval === 'year' ? 12 : 1);
            $key = $price->currency;
            $totals[$key] ??= ['currency' => $price->currency, 'currency_exponent' => (int) $price->currency_exponent, 'numerator' => 0, 'denominator' => 1, 'contracts' => 0, 'discounted' => 0];
            $current = $totals[$key];
            $denominator = intdiv($current['denominator'] * $months, $this->gcd($current['denominator'], $months));
            $numerator = $current['numerator'] * intdiv($denominator, $current['denominator']) + $amount * intdiv($denominator, $months);
            $divisor = $this->gcd($numerator, $denominator);
            $totals[$key] = array_merge($current, [
                'numerator' => intdiv($numerator, $divisor),
                'denominator' => intdiv($denominator, $divisor),
                'contracts' => $current['contracts'] + 1,
                'discounted' => $current['discounted'] + (in_array($contract->id, $discounted, true) ? 1 : 0),
            ]);
        }

        ksort($totals);

        return array_values(array_map(fn (array $total): array => [
            'currency' => $total['currency'],
            'currency_exponent' => $total['currency_exponent'],
            'numerator_minor' => (string) $total['numerator'],
            'denominator' => (string) $total['denominator'],
            'contracts' => $total['contracts'],
            'discounted_contracts' => $total['discounted'],
            'unpriced_contracts' => $unpriced,
        ], $totals));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function money(string $mode, Carbon $from, Carbon $to): array
    {
        $collected = BillingTransaction::query()->where('mode', $mode)->whereIn('status', PaymentLedger::PAID)->where('billed_at', '>=', $from)->where('billed_at', '<', $to)
            ->groupBy('currency', 'currency_exponent')->get(['currency', 'currency_exponent', DB::raw('count(*) as operations'), DB::raw('coalesce(sum(total_minor), 0) as total'), DB::raw('coalesce(sum(tax_minor), 0) as tax')]);
        $refunded = BillingRefund::query()->where('mode', $mode)->where('status', BillingRefund::STATUS_REFUNDED)->where('source_updated_at', '>=', $from)->where('source_updated_at', '<', $to)
            ->groupBy('currency', 'currency_exponent')->get(['currency', 'currency_exponent', DB::raw('count(*) as operations'), DB::raw('coalesce(sum(amount_minor), 0) as total')]);
        $rows = [];

        foreach ($collected as $row) {
            $rows[$row->currency] = ['currency' => $row->currency, 'currency_exponent' => (int) $row->currency_exponent, 'collected_minor' => (int) $row->total, 'collected_tax_minor' => (int) $row->tax, 'collections' => (int) $row->operations, 'refunded_minor' => 0, 'refunds' => 0];
        }

        foreach ($refunded as $row) {
            $rows[$row->currency] ??= ['currency' => $row->currency, 'currency_exponent' => (int) $row->currency_exponent, 'collected_minor' => 0, 'collected_tax_minor' => 0, 'collections' => 0, 'refunded_minor' => 0, 'refunds' => 0];
            $rows[$row->currency]['refunded_minor'] = (int) $row->total;
            $rows[$row->currency]['refunds'] = (int) $row->operations;
        }

        ksort($rows);

        return array_values(array_map(fn (array $row): array => [
            'currency' => $row['currency'],
            'currency_exponent' => $row['currency_exponent'],
            'collected_minor' => (string) $row['collected_minor'],
            'collected_tax_minor' => (string) $row['collected_tax_minor'],
            'collections' => $row['collections'],
            'refunded_minor' => (string) $row['refunded_minor'],
            'refunds' => $row['refunds'],
            'net_minor' => (string) ($row['collected_minor'] - $row['refunded_minor']),
        ], $rows));
    }

    /**
     * @return array{ended: int, at_start: int}
     */
    private function churn(string $mode, Carbon $from, Carbon $to): array
    {
        $ended = BillingSubscriptionContract::query()->where('mode', $mode)->where('normalized_state', BillingSubscriptionContract::STATE_CANCELLED)
            ->where('period_end', '>=', $from)->where('period_end', '<', $to)->count();
        $atStart = BillingSubscriptionContract::query()->where('mode', $mode)->where('normalized_state', '!=', BillingSubscriptionContract::STATE_TRIAL)
            ->where('created_at', '<', $from)
            ->where(fn ($running) => $running->where('normalized_state', BillingSubscriptionContract::STATE_ACTIVE)->orWhere('period_end', '>=', $from))
            ->count();

        return ['ended' => $ended, 'at_start' => $atStart];
    }

    private function lastSynced(string $mode): ?string
    {
        $values = array_filter([
            BillingSubscriptionContract::query()->where('mode', $mode)->max('source_updated_at'),
            BillingTransaction::query()->where('mode', $mode)->max('source_updated_at'),
        ]);

        return $values === [] ? null : Carbon::parse(max($values))->toJSON();
    }

    private function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, $a);
    }
}
