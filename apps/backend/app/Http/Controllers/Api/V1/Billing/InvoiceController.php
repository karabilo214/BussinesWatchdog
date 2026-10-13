<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleSubscriptionActions;
use App\Support\Billing\Paddle\PaddleTransactions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Invoices and payments of the organisation's own subscription and the change of its payment method (ADR 0043).
 */
class InvoiceController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list invoices');
        $validated = $request->validate(['cursor' => ['sometimes', 'uuid']]);
        $rows = BillingTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('mode', PaddleConfig::mode())
            ->whereNotIn('status', ['draft', 'ready'])
            ->when(isset($validated['cursor']), fn ($query) => $query->where('id', '<', $validated['cursor']))
            ->orderByDesc('id')
            ->limit(51)
            ->get();
        $page = $rows->take(50);

        return response()->json([
            'data' => $page->map(fn (BillingTransaction $transaction): array => self::dto($transaction))->values()->all(),
            'next_cursor' => $rows->count() > 50 ? $page->last()?->id : null,
        ]);
    }

    public function download(TenantContext $tenantContext, PaddleTransactions $transactions, string $invoice): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('download invoice');
        /** @var BillingTransaction|null $transaction */
        $transaction = Str::isUuid($invoice) ? BillingTransaction::query()->where('tenant_id', $tenantId)->whereKey($invoice)->first() : null;

        if ($transaction === null) {
            return response()->json(['code' => 'invoice_not_found', 'message' => 'The invoice was not found.'], 404);
        }

        try {
            return response()->json(['url' => $transactions->invoiceUrl($transaction)])->header('Cache-Control', 'no-store');
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The invoice is not available.'], $exception->reasonCode === 'invoice_not_available' ? 409 : $exception->status());
        }
    }

    public function paymentMethod(TenantContext $tenantContext, PaddleSubscriptionActions $actions, PaddleTransactions $transactions): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('change payment method');
        $contract = $actions->liveContract($tenantId);

        if ($contract === null || $contract->provider_subscription_ref === null) {
            return response()->json(['code' => 'no_active_subscription', 'message' => 'There is no paid subscription.'], 409);
        }

        try {
            $transactionId = $transactions->paymentMethodTransaction($contract);
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The payment method cannot be changed now.'], $exception->status());
        }

        return response()->json(['provider_checkout' => [
            'provider' => PaddleConfig::PROVIDER,
            'environment' => PaddleConfig::environment(),
            'client_token' => PaddleConfig::clientToken(),
            'transaction_id' => $transactionId,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function dto(BillingTransaction $transaction): array
    {
        $string = fn (?int $value): ?string => $value === null ? null : (string) $value;

        return [
            'id' => $transaction->id,
            'status' => $transaction->status,
            'origin' => $transaction->origin,
            'invoice_number' => $transaction->invoice_number,
            'currency' => $transaction->currency,
            'currency_exponent' => $transaction->currency_exponent,
            'subtotal_minor' => $string($transaction->subtotal_minor),
            'discount_minor' => $string($transaction->discount_minor),
            'tax_minor' => $string($transaction->tax_minor),
            'total_minor' => $string($transaction->total_minor),
            'period_start' => $transaction->period_start?->toJSON(),
            'period_end' => $transaction->period_end?->toJSON(),
            'billed_at' => ($transaction->billed_at ?? $transaction->provider_created_at)?->toJSON(),
            'invoice_available' => in_array($transaction->status, BillingTransaction::INVOICED, true),
        ];
    }
}
