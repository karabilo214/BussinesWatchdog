<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingRefund;
use App\Models\PlatformStaff;
use App\Support\Account\MfaService;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleRefunds;
use App\Support\Platform\PlatformAudit;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RefundController extends Controller
{
    public function __construct(
        private readonly PaddleRefunds $refunds,
        private readonly MfaService $mfa,
        private readonly PlatformAudit $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::in([BillingRefund::STATUS_REQUESTED, BillingRefund::STATUS_REJECTED, BillingRefund::STATUS_SUBMITTING, BillingRefund::STATUS_PENDING, BillingRefund::STATUS_REFUNDED, BillingRefund::STATUS_DECLINED, BillingRefund::STATUS_FAILED, BillingRefund::STATUS_UNKNOWN])],
            'transaction_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $rows = DB::table('billing_refunds')
            ->join('tenants', 'tenants.id', '=', 'billing_refunds.tenant_id')
            ->join('billing_transactions', 'billing_transactions.id', '=', 'billing_refunds.transaction_id')
            ->leftJoin('platform_staff as requester', 'requester.id', '=', 'billing_refunds.requested_by')
            ->leftJoin('users as requester_user', 'requester_user.id', '=', 'requester.user_id')
            ->leftJoin('platform_staff as decider', 'decider.id', '=', 'billing_refunds.decided_by')
            ->leftJoin('users as decider_user', 'decider_user.id', '=', 'decider.user_id')
            ->where('billing_refunds.mode', PaddleConfig::mode())
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('billing_refunds.status', $status))
            ->when($validated['transaction_id'] ?? null, fn ($query, $transaction) => $query->where('billing_refunds.transaction_id', $transaction))
            ->orderByDesc('billing_refunds.created_at')
            ->limit(100)
            ->get(['billing_refunds.*', 'tenants.name as tenant_name', 'billing_transactions.provider_transaction_ref', 'billing_transactions.invoice_number', 'requester_user.name as requester_name', 'decider_user.name as decider_name']);

        return response()->json(['data' => $rows->map(fn (object $row): array => [
            'id' => $row->id,
            'tenant' => ['id' => $row->tenant_id, 'name' => $row->tenant_name],
            'transaction_id' => $row->transaction_id,
            'provider_transaction_ref' => $row->provider_transaction_ref,
            'invoice_number' => $row->invoice_number,
            'kind' => $row->kind,
            'amount_minor' => (string) $row->amount_minor,
            'tax_minor' => $row->tax_minor === null ? null : (string) $row->tax_minor,
            'currency' => $row->currency,
            'currency_exponent' => (int) $row->currency_exponent,
            'reason' => $row->reason,
            'cancel_subscription' => (bool) $row->cancel_subscription,
            'status' => $row->status,
            'provider_status' => $row->provider_status,
            'error_code' => $row->error_code,
            'requested_by' => $row->requester_name,
            'decided_by' => $row->decider_name,
            'decision_reason' => $row->decision_reason,
            'created_at' => Carbon::parse($row->created_at)->toJSON(),
            'decided_at' => $row->decided_at === null ? null : Carbon::parse($row->decided_at)->toJSON(),
        ])->values()->all()]);
    }

    public function store(Request $request, string $payment): JsonResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'string', 'max:15'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'cancel_subscription' => ['sometimes', 'boolean'],
        ]);

        return $this->guard(fn (PlatformStaff $staff): JsonResponse => response()->json(['id' => $this->refunds->request($staff->id, $payment, $validated['amount_minor'], $validated['reason'], (bool) ($validated['cancel_subscription'] ?? false))->id], 201), $request);
    }

    public function approve(Request $request, string $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], 'confirmation_code' => ['required', 'string', 'max:32']]);

        return $this->guard(function (PlatformStaff $staff) use ($refund, $validated): JsonResponse {
            if (! $this->mfa->verify($staff->user, $validated['confirmation_code'])) {
                $this->audit->record($staff->id, 'billing.refund_approved', 'billing_refund', $refund, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up']);

                return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
            }

            $result = $this->refunds->approve($staff->id, $refund, $validated['reason']);

            return response()->json(['id' => $result->id, 'status' => $result->status, 'provider_adjustment_ref' => $result->provider_adjustment_ref]);
        }, $request);
    }

    public function reject(Request $request, string $refund): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->guard(fn (PlatformStaff $staff): JsonResponse => response()->json(['id' => $refund, 'status' => $this->refunds->reject($staff->id, $refund, $validated['reason'])->status]), $request);
    }

    /**
     * @param  Closure(PlatformStaff): JsonResponse  $action
     */
    private function guard(Closure $action, Request $request): JsonResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        try {
            return $action($staff);
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The refund was rejected.', 'details' => $exception->details], $exception->status());
        }
    }
}
