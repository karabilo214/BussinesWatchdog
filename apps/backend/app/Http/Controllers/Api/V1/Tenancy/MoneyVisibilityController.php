<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\MoneyAmountVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Whether the signed-in member sees amounts; it is set per member in the team permissions (ADR 0079). */
class MoneyVisibilityController extends Controller
{
    public function show(Request $request, TenantContext $context, MoneyAmountVisibility $visibility): JsonResponse
    {
        $tenantId = $context->requireTenantId('read money visibility');

        return response()->json([
            'can_view_money_amounts' => $visibility->allows($request, $tenantId),
        ])->header('Cache-Control', 'private, no-store');
    }
}
