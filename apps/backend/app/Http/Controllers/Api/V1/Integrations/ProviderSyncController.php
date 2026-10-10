<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `POST /integrations/{id}/sync` for every independent provider; each adapter keeps its own sync rules. */
class ProviderSyncController extends Controller
{
    public function sync(Request $request, Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        return match ($integration->provider) {
            'paypal' => app(PayPalIntegrationController::class)->sync($request, $integration, $tenantContext),
            default => app(StripeIntegrationController::class)->sync($request, $integration, $tenantContext),
        };
    }
}
