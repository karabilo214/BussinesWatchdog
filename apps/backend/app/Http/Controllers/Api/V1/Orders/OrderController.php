<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Http\Dto\Orders\OrderDto;
use App\Http\Dto\Orders\OrderRevisionDto;
use App\Http\Dto\Orders\RefundDto;
use App\Http\Dto\Payments\FinancialTransactionDto;
use App\Http\Dto\Payments\PaymentAllocationDto;
use App\Http\Dto\Payments\RefundAllocationDto;
use App\Http\Dto\Reconciliation\ReconciliationFindingDto;
use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderDto $orderDto,
        private readonly RefundDto $refundDto,
        private readonly OrderRevisionDto $revisionDto,
        private readonly FinancialTransactionDto $transactionDto,
        private readonly PaymentAllocationDto $paymentAllocationDto,
        private readonly RefundAllocationDto $refundAllocationDto,
        private readonly ReconciliationFindingDto $findingDto,
    ) {}

    public function show(Order $order, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read order');

        abort_unless($order->tenant_id === $tenantId, 404);

        $allocations = PaymentAllocation::query()
            ->where('order_id', $order->id)
            ->orderBy('created_at')
            ->get();
        $captures = FinancialTransaction::query()
            ->whereIn('id', $allocations->pluck('capture_transaction_id')->unique())
            ->get();

        $refunds = Refund::query()
            ->where('order_id', $order->id)
            ->orderBy('occurred_at')
            ->get();
        $refundAllocations = RefundAllocation::query()
            ->whereHas('refund', fn ($query) => $query->where('order_id', $order->id))
            ->orderBy('created_at')
            ->get();
        $refundTransactions = FinancialTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($refundAllocations, $allocations): void {
                $query->whereIn('id', $refundAllocations->pluck('refund_transaction_id')->unique())
                    ->orWhere(fn ($related) => $related
                        ->whereIn('payment_id', $allocations->whereNull('revoked_at')->pluck('payment_id')->unique())
                        ->where('kind', 'refund')
                        ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER));
            })
            ->orderBy('occurred_at')
            ->get();

        $revisions = OrderRevision::query()
            ->where('order_id', $order->id)
            ->orderBy('source_revision')
            ->get();

        $latestRunId = ReconciliationFinding::query()
            ->where('tenant_id', $tenantId)
            ->where('order_id', $order->id)
            ->orderByDesc('evaluated_at')
            ->orderByDesc('run_id')
            ->value('run_id');
        $latestFindings = ReconciliationFinding::query()
            ->where('tenant_id', $tenantId)
            ->where('order_id', $order->id)
            ->where('run_id', $latestRunId)
            ->orderBy('rule_code')
            ->get();

        return response()->json(array_merge($this->orderDto->toArray($order), [
            'findings' => $this->findingDto->collection($latestFindings),
            'captures' => $captures->map(fn (FinancialTransaction $t): array => $this->transactionDto->toArray($t))->all(),
            'refunds' => $refunds->map(fn (Refund $r): array => $this->refundDto->toArray($r))->all(),
            'refund_transactions' => $refundTransactions->map(fn (FinancialTransaction $t): array => $this->transactionDto->toArray($t))->all(),
            'allocations' => $allocations->map(fn (PaymentAllocation $a): array => $this->paymentAllocationDto->toArray($a))->all(),
            'refund_allocations' => $refundAllocations->map(fn (RefundAllocation $a): array => $this->refundAllocationDto->toArray($a))->all(),
            'revisions' => $revisions->map(fn (OrderRevision $r): array => $this->revisionDto->toArray($r))->all(),
        ]));
    }
}
