<?php

namespace App\Support\Platform;

use App\Models\BillingTransaction;
use App\Support\Billing\Paddle\PaddleConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payments of our own subscriptions for the owner panel (ADR 0043): every provider transaction of the current mode,
 * and the month's collected money per currency — what customers paid, the tax in it, the provider's fee and what is
 * left for us — never added up across currencies.
 */
class PaymentLedger
{
    public const PAID = ['paid', 'completed'];

    /**
     * @param  array{status?: ?string, tenant_id?: ?string, cursor?: ?string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function list(array $filters): array
    {
        $rows = BillingTransaction::query()
            ->join('tenants', 'tenants.id', '=', 'billing_transactions.tenant_id')
            ->where('billing_transactions.mode', PaddleConfig::mode())
            ->where('billing_transactions.status', '!=', 'draft')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('billing_transactions.status', $status))
            ->when($filters['tenant_id'] ?? null, fn ($query, $tenant) => $query->where('billing_transactions.tenant_id', $tenant))
            ->when($filters['cursor'] ?? null, fn ($query, $cursor) => $query->where('billing_transactions.id', '<', $cursor))
            ->orderByDesc('billing_transactions.id')
            ->limit(51)
            ->get(['billing_transactions.*', 'tenants.name as tenant_name']);
        $page = $rows->take(50);

        return [
            'data' => $page->map(fn (BillingTransaction $transaction): array => self::dto($transaction) + ['tenant' => ['id' => $transaction->tenant_id, 'name' => $transaction->getAttribute('tenant_name')]])->values()->all(),
            'next_cursor' => $rows->count() > 50 ? $page->last()?->id : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function month(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $start = $now->copy()->utc()->startOfMonth();

        return DB::table('billing_transactions')
            ->where('mode', PaddleConfig::mode())
            ->whereIn('status', self::PAID)
            ->where('billed_at', '>=', $start)
            ->where('billed_at', '<', $start->copy()->addMonth())
            ->groupBy('currency', 'currency_exponent')
            ->orderBy('currency')
            ->selectRaw('currency, currency_exponent, count(*) as payments, sum(total_minor) as total, sum(tax_minor) as tax, sum(fee_minor) as fee, sum(earnings_minor) as earnings, sum(case when fee_minor is null then 1 else 0 end) as fee_unknown')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => $row->currency,
                'currency_exponent' => (int) $row->currency_exponent,
                'payments' => (int) $row->payments,
                'total_minor' => (string) $row->total,
                'tax_minor' => $row->tax === null ? null : (string) $row->tax,
                'fee_minor' => (int) $row->fee_unknown > 0 ? null : (string) $row->fee,
                'earnings_minor' => (int) $row->fee_unknown > 0 ? null : (string) $row->earnings,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function dto(BillingTransaction $transaction): array
    {
        $string = fn (?int $value): ?string => $value === null ? null : (string) $value;

        return [
            'id' => $transaction->id,
            'provider_transaction_ref' => $transaction->provider_transaction_ref,
            'status' => $transaction->status,
            'origin' => $transaction->origin,
            'invoice_number' => $transaction->invoice_number,
            'currency' => $transaction->currency,
            'currency_exponent' => $transaction->currency_exponent,
            'subtotal_minor' => $string($transaction->subtotal_minor),
            'discount_minor' => $string($transaction->discount_minor),
            'tax_minor' => $string($transaction->tax_minor),
            'total_minor' => $string($transaction->total_minor),
            'fee_minor' => $string($transaction->fee_minor),
            'earnings_minor' => $string($transaction->earnings_minor),
            'period_start' => $transaction->period_start?->toJSON(),
            'period_end' => $transaction->period_end?->toJSON(),
            'billed_at' => ($transaction->billed_at ?? $transaction->provider_created_at)?->toJSON(),
        ];
    }
}
