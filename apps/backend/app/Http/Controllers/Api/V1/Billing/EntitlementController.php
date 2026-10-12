<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\UsageMeter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class EntitlementController extends Controller
{
    public function show(TenantContext $tenantContext, EntitlementCalculator $calculator, UsageMeter $usage): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read entitlements');
        $metered = [];

        foreach ([UsageMeter::MANUAL_RUNS, UsageMeter::STORE_EVENTS] as $metric) {
            $current = $usage->current($tenantId, $metric);
            $metered[$metric] = [
                'used' => $current['used'],
                'period_start' => $current['period_start']->toJSON(),
                'period_end' => $current['period_end']->toJSON(),
            ];
        }

        return response()->json(array_merge($calculator->forTenant($tenantId)->toArray(), ['usage' => $metered]));
    }
}
