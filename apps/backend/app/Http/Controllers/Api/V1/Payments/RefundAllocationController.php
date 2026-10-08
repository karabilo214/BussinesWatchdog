<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\Payments\AllocationRejected;
use App\Http\Controllers\Controller;
use App\Http\Dto\Payments\RefundAllocationDto;
use App\Http\Requests\Payments\CreateRefundAllocationRequest;
use App\Http\Requests\Payments\RevokeAllocationRequest;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class RefundAllocationController extends Controller
{
    public function __construct(
        private readonly RefundAllocationDto $allocationDto,
        private readonly PaymentAllocationService $allocationService,
    ) {}

    public function store(CreateRefundAllocationRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('create refund allocation');
        $validated = $request->validated();

        $refund = Refund::query()->where('tenant_id', $tenantId)->find($validated['refund_id']);
        $refundTransaction = FinancialTransaction::query()->where('tenant_id', $tenantId)->find($validated['refund_transaction_id']);
        $paymentAllocation = PaymentAllocation::query()->where('tenant_id', $tenantId)->find($validated['payment_allocation_id']);

        abort_if($refund === null || $refundTransaction === null || $paymentAllocation === null, 404);

        if ($refundTransaction->currency !== $validated['currency']) {
            return response()->json([
                'code' => PaymentAllocationService::ERROR_CURRENCY_MISMATCH,
                'message' => 'Submitted currency does not match the refund transaction currency.',
            ], 422);
        }

        try {
            $allocation = $this->allocationService->allocateRefund(
                $refund,
                $refundTransaction,
                $paymentAllocation,
                (int) $validated['amount_minor'],
                PaymentAllocation::STRATEGY_MANUAL,
                [],
                $request->user()?->id,
            );
        } catch (AllocationRejected $exception) {
            return $this->rejectedResponse($exception);
        }

        AuditLog::query()->create([
            'tenant_id' => $allocation->tenant_id,
            'store_id' => $allocation->store_id,
            'actor_user_id' => $request->user()?->id,
            'actor_type' => AuditLog::ACTOR_USER,
            'action' => AuditLog::ACTION_REFUND_ALLOCATION_CREATED,
            'entity_type' => AuditLog::ENTITY_REFUND_ALLOCATION,
            'entity_id' => $allocation->id,
            'changes' => [
                'reason' => $validated['reason'],
                'refund_id' => $allocation->refund_id,
                'payment_allocation_id' => $allocation->payment_allocation_id,
                'amount_minor' => $allocation->amount_minor,
            ],
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        return response()->json($this->allocationDto->toArray($allocation), 201);
    }

    public function revoke(RevokeAllocationRequest $request, RefundAllocation $refundAllocation, TenantContext $tenantContext): Response
    {
        $tenantId = $tenantContext->requireTenantId('revoke refund allocation');

        abort_unless($refundAllocation->tenant_id === $tenantId, 404);

        try {
            $this->allocationService->revokeRefundAllocation(
                $refundAllocation,
                $request->validated()['reason'],
                $request->user()?->id,
            );
        } catch (AllocationRejected $exception) {
            return $this->rejectedResponse($exception);
        }

        return response()->noContent();
    }

    private function rejectedResponse(AllocationRejected $exception): JsonResponse
    {
        return response()->json([
            'code' => $exception->reasonCode,
            'message' => $exception->getMessage(),
        ], $exception->httpStatus());
    }
}
