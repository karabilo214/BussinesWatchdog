<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\Payments\AllocationRejected;
use App\Http\Controllers\Controller;
use App\Http\Dto\Payments\PaymentAllocationDto;
use App\Http\Requests\Payments\CreatePaymentAllocationRequest;
use App\Http\Requests\Payments\RevokeAllocationRequest;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PaymentAllocationController extends Controller
{
    public function __construct(
        private readonly PaymentAllocationDto $allocationDto,
        private readonly PaymentAllocationService $allocationService,
    ) {}

    public function store(CreatePaymentAllocationRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('create payment allocation');
        $validated = $request->validated();

        $order = Order::query()->where('tenant_id', $tenantId)->find($validated['order_id']);
        $payment = Payment::query()->where('tenant_id', $tenantId)->find($validated['payment_id']);
        $capture = FinancialTransaction::query()->where('tenant_id', $tenantId)->find($validated['capture_transaction_id']);

        abort_if($order === null || $payment === null || $capture === null, 404);

        if ($capture->currency !== $validated['currency']) {
            return response()->json([
                'code' => PaymentAllocationService::ERROR_CURRENCY_MISMATCH,
                'message' => 'Submitted currency does not match the capture transaction currency.',
            ], 422);
        }

        try {
            $allocation = $this->allocationService->allocateCapture(
                $payment,
                $capture,
                $order,
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
            'action' => AuditLog::ACTION_PAYMENT_ALLOCATION_CREATED,
            'entity_type' => AuditLog::ENTITY_PAYMENT_ALLOCATION,
            'entity_id' => $allocation->id,
            'changes' => [
                'reason' => $validated['reason'],
                'order_id' => $allocation->order_id,
                'capture_transaction_id' => $allocation->capture_transaction_id,
                'amount_minor' => $allocation->amount_minor,
            ],
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        return response()->json($this->allocationDto->toArray($allocation), 201);
    }

    public function revoke(RevokeAllocationRequest $request, PaymentAllocation $paymentAllocation, TenantContext $tenantContext): Response
    {
        $tenantId = $tenantContext->requireTenantId('revoke payment allocation');

        abort_unless($paymentAllocation->tenant_id === $tenantId, 404);

        try {
            $this->allocationService->revokeCaptureAllocation(
                $paymentAllocation,
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
