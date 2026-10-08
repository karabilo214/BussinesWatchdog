<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function activate(Request $request, Tenant $tenant): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $isMember = $user->memberships()
            ->where('tenant_id', $tenant->id)
            ->exists();

        abort_unless($isMember, 404);

        $request->session()->put('active_tenant_id', $tenant->id);

        return response()->json([
            'active_tenant_id' => $tenant->id,
        ]);
    }

    public function context(Request $request, TenantContext $tenantContext): JsonResponse
    {
        return response()->json([
            'active_tenant_id' => $request->session()->get('active_tenant_id'),
            'tenant_context_id' => $tenantContext->tenantId(),
            'tenant_context_source' => $tenantContext->source(),
        ]);
    }
}
