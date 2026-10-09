<?php

namespace App\Http\Controllers\Api\V1\Pairing;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\PairingCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PairingCodeController extends Controller
{
    public function store(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('create pairing code');

        abort_unless($store->tenant_id === $tenantId, 404);

        $code = 'bwpc_'.Str::lower(Str::random(40));
        $pairingCode = PairingCode::query()->create([
            'tenant_id' => $tenantId,
            'store_id' => $store->id,
            'code_hash' => hash('sha256', $code),
            'created_by' => $request->user()->id,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
        ]);

        return response()->json([
            'id' => $pairingCode->id,
            'store_id' => $store->id,
            'pairing_code' => $code,
            'expires_at' => $pairingCode->expires_at->toJSON(),
            'saas_endpoint' => url('/api/v1/pairing/exchange'),
            'service_url' => url('/'),
        ], 201);
    }
}
