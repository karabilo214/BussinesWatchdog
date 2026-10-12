<?php

namespace App\Support\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingDiscount;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Support\Billing\Paddle\PaddleClient;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleRequestFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiscountLedger
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public function __construct(private readonly PaddleClient $client) {}

    /**
     * @param  list<BillingDiscount>  $discounts
     */
    public function recordCheckout(BillingCheckoutSession $session, array $discounts, int $amountBefore, string $currency, int $exponent): ?string
    {
        if ($discounts === []) {
            return null;
        }

        $calculation = DiscountResolver::calculate($discounts, $amountBefore);

        foreach ($calculation['lines'] as $line) {
            $periods = DiscountResolver::periods($line['discount']);
            DB::table('billing_discount_grants')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $session->tenant_id,
                'checkout_session_id' => $session->id,
                'discount_id' => $line['discount']->id,
                'position' => $line['position'],
                'periods_total' => $periods,
                'periods_used' => 1,
                'created_at' => Carbon::now(),
            ]);
        }

        return $this->writeApplication($session->tenant_id, $session->id, null, 1, null, $calculation, $currency, $exponent, self::STATUS_PLANNED);
    }

    public function checkoutDiscountRef(string $applicationId): ?string
    {
        $application = DB::table('billing_discount_applications')->where('id', $applicationId)->first();

        if ($application === null || (int) $application->saving_minor === 0) {
            return null;
        }

        if ($application->provider_discount_ref !== null) {
            return $application->provider_discount_ref;
        }

        $result = $this->client->post('/discounts', [
            'amount' => (string) $application->saving_minor,
            'type' => 'flat',
            'currency_code' => $application->currency,
            'description' => 'BW checkout '.$application->checkout_session_id.' period 1',
            'enabled_for_checkout' => false,
            'recur' => false,
            'usage_limit' => 1,
            'custom_data' => ['discount_application_id' => $application->id],
        ]);
        $ref = (string) $result['data']['id'];
        DB::table('billing_discount_applications')->where('id', $applicationId)->update(['provider_discount_ref' => $ref]);

        return $ref;
    }

    public function linkContract(BillingCheckoutSession $session, BillingSubscriptionContract $contract): void
    {
        DB::table('billing_discount_grants')->where('checkout_session_id', $session->id)->whereNull('contract_id')->update(['contract_id' => $contract->id]);
        DB::table('billing_discount_applications')
            ->where('checkout_session_id', $session->id)
            ->where('period_number', 1)
            ->where('status', self::STATUS_PLANNED)
            ->update(['contract_id' => $contract->id, 'status' => self::STATUS_APPLIED, 'period_start' => $contract->period_start, 'applied_at' => Carbon::now()]);
    }

    /**
     * @return array{planned: int, failed: int}
     */
    public function planRenewals(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $planned = 0;
        $failed = 0;
        $contracts = BillingSubscriptionContract::query()
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->whereIn('normalized_state', [BillingSubscriptionContract::STATE_ACTIVE, BillingSubscriptionContract::STATE_PAST_DUE])
            ->where('cancel_at_period_end', false)
            ->whereNotNull('period_end')
            ->where('period_end', '>', $now)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('billing_discount_grants')->whereColumn('billing_discount_grants.contract_id', 'billing_subscription_contracts.id')
                ->where(fn ($open) => $open->whereNull('periods_total')->orWhereColumn('periods_used', '<', 'periods_total')))
            ->get();

        foreach ($contracts as $contract) {
            $result = $this->planNextPeriod($contract);
            $planned += $result === self::STATUS_APPLIED ? 1 : 0;
            $failed += $result === self::STATUS_FAILED ? 1 : 0;
        }

        return ['planned' => $planned, 'failed' => $failed];
    }

    public function planNextPeriod(BillingSubscriptionContract $contract): ?string
    {
        $periodStart = $contract->period_end;
        $existing = DB::table('billing_discount_applications')->where('contract_id', $contract->id)->where('period_start', $periodStart)->first();

        if ($existing !== null && $existing->status !== self::STATUS_FAILED) {
            return null;
        }

        $grants = DB::table('billing_discount_grants')
            ->where('contract_id', $contract->id)
            ->where(fn ($open) => $open->whereNull('periods_total')->orWhereColumn('periods_used', '<', 'periods_total'))
            ->orderBy('position')
            ->get();
        $first = DB::table('billing_discount_grants')->where('contract_id', $contract->id)->value('checkout_session_id');
        $amount = $this->periodAmount($contract);

        if ($grants->isEmpty() || $first === null || $amount === null) {
            return null;
        }

        $discounts = BillingDiscount::query()->whereKey($grants->pluck('discount_id'))->get()->all();
        $calculation = DiscountResolver::calculate($discounts, $amount['amount']);
        $periodNumber = (int) DB::table('billing_discount_applications')->where('checkout_session_id', $first)->max('period_number') + ($existing === null ? 1 : 0);

        if ($existing !== null) {
            $periodNumber = (int) $existing->period_number;
            DB::table('billing_discount_application_lines')->where('application_id', $existing->id)->delete();
            DB::table('billing_discount_applications')->where('id', $existing->id)->delete();
        }

        $applicationId = $this->writeApplication($contract->tenant_id, $first, $contract->id, $periodNumber, $periodStart, $calculation, $amount['currency'], $amount['exponent'], self::STATUS_PLANNED);

        try {
            $ref = null;

            if ($calculation['saving'] > 0) {
                $created = $this->client->post('/discounts', [
                    'amount' => (string) $calculation['saving'],
                    'type' => 'flat',
                    'currency_code' => $amount['currency'],
                    'description' => 'BW subscription '.$contract->provider_subscription_ref.' period '.$periodNumber,
                    'enabled_for_checkout' => false,
                    'recur' => true,
                    'maximum_recurring_intervals' => 1,
                    'custom_data' => ['discount_application_id' => $applicationId],
                ]);
                $ref = (string) $created['data']['id'];
                $this->client->patch('/subscriptions/'.$contract->provider_subscription_ref, [
                    'discount' => ['id' => $ref, 'effective_from' => 'next_billing_period'],
                    'proration_billing_mode' => 'do_not_bill',
                ]);
            }
        } catch (PaddleRequestFailed $exception) {
            DB::table('billing_discount_applications')->where('id', $applicationId)->update(['status' => self::STATUS_FAILED, 'error_code' => $exception->reasonCode, 'provider_discount_ref' => $ref ?? null]);

            return self::STATUS_FAILED;
        }

        DB::transaction(function () use ($applicationId, $ref, $grants): void {
            DB::table('billing_discount_applications')->where('id', $applicationId)->update(['status' => self::STATUS_APPLIED, 'provider_discount_ref' => $ref, 'applied_at' => Carbon::now(), 'error_code' => null]);
            DB::table('billing_discount_grants')->whereIn('id', $grants->pluck('id'))->increment('periods_used');
        });

        return self::STATUS_APPLIED;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function checkoutBreakdown(string $checkoutSessionId): ?array
    {
        $application = DB::table('billing_discount_applications')->where('checkout_session_id', $checkoutSessionId)->where('period_number', 1)->first();

        return $application === null ? null : $this->breakdown($application);
    }

    /**
     * @return array<string, mixed>
     */
    public function breakdown(object $application): array
    {
        $lines = DB::table('billing_discount_application_lines')->where('application_id', $application->id)->orderBy('position')->get();
        $discounts = BillingDiscount::query()->whereKey($lines->pluck('discount_id'))->get()->keyBy('id');

        return [
            'id' => $application->id,
            'period_number' => (int) $application->period_number,
            'period_start' => $application->period_start === null ? null : Carbon::parse($application->period_start)->toJSON(),
            'currency' => $application->currency,
            'currency_exponent' => (int) $application->currency_exponent,
            'amount_before_minor' => (string) $application->amount_before_minor,
            'saving_minor' => (string) $application->saving_minor,
            'amount_after_minor' => (string) $application->amount_after_minor,
            'status' => $application->status,
            'error_code' => $application->error_code,
            'lines' => $lines->map(fn (object $line): array => [
                'discount' => isset($discounts[$line->discount_id]) ? DiscountResolver::dto($discounts[$line->discount_id]) + ['name' => $discounts[$line->discount_id]->name, 'code' => $discounts[$line->discount_id]->code] : null,
                'position' => (int) $line->position,
                'amount_before_minor' => (string) $line->amount_before_minor,
                'saving_minor' => (string) $line->saving_minor,
                'amount_after_minor' => (string) $line->amount_after_minor,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{amount: int, currency: string, exponent: int}|null
     */
    private function periodAmount(BillingSubscriptionContract $contract): ?array
    {
        /** @var BillingPrice|null $base */
        $base = $contract->price_id === null ? null : BillingPrice::query()->find($contract->price_id);

        if ($base === null) {
            return null;
        }

        $amount = (int) $base->amount_minor;

        if ($contract->extra_store_quantity > 0) {
            $extra = BillingPrice::query()
                ->where('plan_version_id', $base->plan_version_id)
                ->where('price_kind', BillingPrice::KIND_EXTRA_STORE)
                ->where('mode', $base->mode)
                ->where('currency', $base->currency)
                ->where('billing_interval', $base->billing_interval)
                ->where('billing_interval_count', $base->billing_interval_count)
                ->first();

            if ($extra === null) {
                return null;
            }

            $amount += (int) $extra->amount_minor * $contract->extra_store_quantity;
        }

        return ['amount' => $amount, 'currency' => $base->currency, 'exponent' => $base->currency_exponent];
    }

    /**
     * @param  array{amount_before: int, saving: int, amount_after: int, lines: list<array{discount: BillingDiscount, position: int, amount_before: int, saving: int, amount_after: int}>}  $calculation
     */
    private function writeApplication(string $tenantId, string $sessionId, ?string $contractId, int $periodNumber, ?Carbon $periodStart, array $calculation, string $currency, int $exponent, string $status): string
    {
        $id = (string) Str::uuid7();
        DB::table('billing_discount_applications')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'checkout_session_id' => $sessionId,
            'contract_id' => $contractId,
            'period_number' => $periodNumber,
            'period_start' => $periodStart,
            'currency' => $currency,
            'currency_exponent' => $exponent,
            'amount_before_minor' => $calculation['amount_before'],
            'saving_minor' => $calculation['saving'],
            'amount_after_minor' => $calculation['amount_after'],
            'status' => $status,
            'created_at' => Carbon::now(),
        ]);

        foreach ($calculation['lines'] as $line) {
            DB::table('billing_discount_application_lines')->insert([
                'id' => (string) Str::uuid7(),
                'application_id' => $id,
                'discount_id' => $line['discount']->id,
                'position' => $line['position'],
                'amount_before_minor' => $line['amount_before'],
                'saving_minor' => $line['saving'],
                'amount_after_minor' => $line['amount_after'],
            ]);
        }

        return $id;
    }
}
