<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantAttention;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttentionController extends Controller
{
    public function show(Request $request, TenantContext $context, TenantAttention $attention): JsonResponse
    {
        return response()->json($attention->forMember($request->user(), $context->requireTenantId('read menu counters')))
            ->header('Cache-Control', 'private, no-store');
    }
}
