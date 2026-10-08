<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Http\Dto\Payments\FinancialTransactionDto;
use App\Http\Dto\Payments\PaymentAllocationDto;
use App\Http\Dto\Payments\PaymentDto;
use App\Http\Dto\Payments\RefundAllocationDto;
use App\Http\Dto\Reconciliation\ReconciliationFindingDto;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\RefundAllocation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentDto $paymentDto,
        private readonly FinancialTransactionDto $transactionDto,
        private readonly PaymentAllocationDto $paymentAllocationDto,
        private readonly RefundAllocationDto $refundAllocationDto,
        private readonly ReconciliationFindingDto $findingDto,
    ) {}

    public function show(Payment $payment, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read payment');

        abort_unless($payment->tenant_id === $tenantId, 404);

        $transactions = FinancialTransaction::query()
            ->where('payment_id', $payment->id)
            ->orderBy('occurred_at')
            ->get();
        $allocations = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->orderBy('created_at')
            ->get();
        $refundAllocations = RefundAllocation::query()
            ->whereHas('refundTransaction', fn ($query) => $query->where('payment_id', $payment->id))
            ->orderBy('created_at')
            ->get();

        $latestFindings = ReconciliationFinding::query()
            ->where('payment_id', $payment->id)
            ->orderByDesc('id')
            ->get()
            ->unique('rule_code')
            ->values();

        return response()->json(array_merge($this->paymentDto->toArray($payment), [
            'findings' => $this->findingDto->collection($latestFindings),
            'transactions' => $transactions->map(fn (FinancialTransaction $t): array => $this->transactionDto->toArray($t))->all(),
            'allocations' => $allocations->map(fn (PaymentAllocation $a): array => $this->paymentAllocationDto->toArray($a))->all(),
            'refund_allocations' => $refundAllocations->map(fn (RefundAllocation $a): array => $this->refundAllocationDto->toArray($a))->all(),
        ]));
    }
}
