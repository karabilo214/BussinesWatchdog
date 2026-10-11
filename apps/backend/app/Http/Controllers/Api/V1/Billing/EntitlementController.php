<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class EntitlementController extends Controller
{
    public function show(TenantContext $tenantContext, EntitlementCalculator $calculator): JsonResponse
    {
        return response()->json($calculator->forTenant($tenantContext->requireTenantId('read entitlements'))->toArray());
    }
}
