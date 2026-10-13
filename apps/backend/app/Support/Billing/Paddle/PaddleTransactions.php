<?php

namespace App\Support\Billing\Paddle;

use App\Models\BillingSubscriptionContract;
use App\Models\BillingTransaction;
use App\Models\Tenant;
use App\Support\Billing\BillingCheckoutRejected;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NumberFormatter;

/**
 * Our record of the provider's transactions for our own subscription (invoices and payments, ADR 0043): projected
 * from verified transaction events and from reading a subscription's transactions; an older read never overwrites a
 * newer one. Amounts stay in minor units as the provider reports them; the invoice document stays at the provider
 * and is handed out as a short-lived link.
 */
class PaddleTransactions
{
    public function __construct(private readonly PaddleClient $client) {}

    /**
     * @param  array<string, mixed>  $payload  a sanitized transaction (PaddleEventRecorder::sanitize)
     */
    public function project(array $payload): ?BillingTransaction
    {
        $ref = $payload['id'] ?? null;
        $currency = $payload['currency_code'] ?? ($payload['totals']['currency_code'] ?? null);
        $status = $payload['status'] ?? null;

        if (! is_string($ref) || ! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! in_array($status, BillingTransaction::STATUSES, true)) {
            return null;
        }

        $subscriptionRef = is_string($payload['subscription_id'] ?? null) ? $payload['subscription_id'] : null;
        /** @var BillingSubscriptionContract|null $contract */
        $contract = $subscriptionRef === null ? null : BillingSubscriptionContract::query()
            ->where('provider', PaddleConfig::PROVIDER)->where('provider_account_ref', PaddleConfig::accountRef())->where('mode', PaddleConfig::mode())
            ->where('provider_subscription_ref', $subscriptionRef)->first();
        $tenantRef = $payload['custom_data']['tenant_id'] ?? null;
        $tenantId = $contract?->tenant_id ?? (is_string($tenantRef) && Str::isUuid($tenantRef) && Tenant::query()->whereKey($tenantRef)->exists() ? $tenantRef : null);

        if ($tenantId === null) {
            return null;
        }

        $updatedAt = Carbon::parse((string) ($payload['updated_at'] ?? 'now'));
        $totals = is_array($payload['totals'] ?? null) ? $payload['totals'] : [];
        $period = is_array($payload['billing_period'] ?? null) ? $payload['billing_period'] : [];
        $attributes = [
            'tenant_id' => $tenantId,
            'contract_id' => $contract?->id,
            'provider_subscription_ref' => $subscriptionRef,
            'status' => $status,
            'origin' => is_string($payload['origin'] ?? null) ? $payload['origin'] : null,
            'invoice_number' => is_string($payload['invoice_number'] ?? null) ? $payload['invoice_number'] : null,
            'currency' => $currency,
            'currency_exponent' => self::exponent($currency),
            'subtotal_minor' => self::minor($totals['subtotal'] ?? null),
            'discount_minor' => self::minor($totals['discount'] ?? null),
            'tax_minor' => self::minor($totals['tax'] ?? null),
            'total_minor' => self::minor($totals['grand_total'] ?? null) ?? self::minor($totals['total'] ?? null),
            'fee_minor' => self::minor($totals['fee'] ?? null),
            'earnings_minor' => self::minor($totals['earnings'] ?? null),
            'period_start' => isset($period['starts_at']) ? Carbon::parse($period['starts_at']) : null,
            'period_end' => isset($period['ends_at']) ? Carbon::parse($period['ends_at']) : null,
            'billed_at' => isset($payload['billed_at']) ? Carbon::parse($payload['billed_at']) : null,
            'provider_created_at' => isset($payload['created_at']) ? Carbon::parse($payload['created_at']) : null,
            'source_updated_at' => $updatedAt,
        ];

        return DB::transaction(function () use ($ref, $attributes, $updatedAt): BillingTransaction {
            /** @var BillingTransaction|null $row */
            $row = BillingTransaction::query()
                ->where('provider', PaddleConfig::PROVIDER)->where('provider_account_ref', PaddleConfig::accountRef())->where('mode', PaddleConfig::mode())
                ->where('provider_transaction_ref', $ref)->lockForUpdate()->first();

            if ($row !== null && $row->source_updated_at->greaterThan($updatedAt)) {
                return $row;
            }

            if ($row === null) {
                return BillingTransaction::query()->create($attributes + [
                    'provider' => PaddleConfig::PROVIDER,
                    'provider_account_ref' => PaddleConfig::accountRef(),
                    'mode' => PaddleConfig::mode(),
                    'provider_transaction_ref' => $ref,
                ]);
            }

            $row->fill(array_filter($attributes, fn (mixed $value, string $key): bool => $value !== null || ! in_array($key, ['invoice_number', 'billed_at', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'fee_minor', 'earnings_minor'], true), ARRAY_FILTER_USE_BOTH))->save();

            return $row;
        });
    }

    /**
     * Reads every transaction of a subscription again (for subscriptions bought before transactions were recorded).
     */
    public function syncSubscription(string $subscriptionRef): int
    {
        $count = 0;
        $after = null;

        do {
            $page = $this->client->get('/transactions', array_filter(['subscription_id' => $subscriptionRef, 'per_page' => 50, 'order_by' => 'id[ASC]', 'after' => $after]));

            $data = $page['data'] ?? null;

            foreach (is_array($data) && array_is_list($data) ? $data : [] as $transaction) {
                if (is_array($transaction) && $this->project(PaddleEventRecorder::sanitize('transaction', $transaction)) !== null) {
                    $count++;
                }
            }

            $next = $page['meta']['pagination']['next'] ?? null;
            $hasMore = (bool) ($page['meta']['pagination']['has_more'] ?? false);
            parse_str((string) parse_url(is_string($next) ? $next : '', PHP_URL_QUERY), $query);
            $after = is_string($query['after'] ?? null) ? $query['after'] : null;
        } while ($hasMore && $after !== null);

        return $count;
    }

    /**
     * A short-lived link to the invoice PDF of a billed transaction.
     */
    public function invoiceUrl(BillingTransaction $transaction): string
    {
        if (! in_array($transaction->status, BillingTransaction::INVOICED, true)) {
            throw new BillingCheckoutRejected('invoice_not_available');
        }

        try {
            $url = $this->client->get('/transactions/'.rawurlencode($transaction->provider_transaction_ref).'/invoice', ['disposition' => 'attachment'])['data']['url'] ?? null;
        } catch (PaddleRequestFailed) {
            throw new BillingCheckoutRejected('provider_unavailable');
        }

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new BillingCheckoutRejected('provider_unavailable');
        }

        return $url;
    }

    /**
     * The provider's transaction for changing the payment method of a subscription, opened with Paddle.js like a
     * checkout; nothing is charged.
     */
    public function paymentMethodTransaction(BillingSubscriptionContract $contract): string
    {
        try {
            $id = $this->client->get('/subscriptions/'.rawurlencode((string) $contract->provider_subscription_ref).'/update-payment-method-transaction')['data']['id'] ?? null;
        } catch (PaddleRequestFailed) {
            throw new BillingCheckoutRejected('provider_unavailable');
        }

        return is_string($id) && $id !== '' ? $id : throw new BillingCheckoutRejected('provider_unavailable');
    }

    private static function minor(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : null;
    }

    private static function exponent(string $currency): int
    {
        $digits = (new NumberFormatter('en@currency='.$currency, NumberFormatter::CURRENCY))->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS);

        return is_int($digits) ? $digits : 2;
    }
}
