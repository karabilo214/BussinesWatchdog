<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Platform\PaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request, PaymentLedger $ledger): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::in(BillingTransaction::STATUSES)],
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'cursor' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json($ledger->list($validated) + ['month' => $ledger->month(), 'mode' => PaddleConfig::mode()]);
    }
}
