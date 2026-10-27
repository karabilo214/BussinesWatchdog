<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Support\Providers\ProviderWebhookCheck;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * «Check set-up» of a Stripe or PayPal webhook: whether the provider has a webhook for this connection's address
 * listening to the events it needs.
 */
class ProviderWebhookCheckController extends Controller
{
    public function __construct(private readonly ProviderWebhookCheck $checks) {}

    public function check(Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($integration->tenant_id === $tenantContext->requireTenantId('check provider webhook') && in_array($integration->provider, ['stripe', 'paypal'], true), 404);

        if (! in_array($integration->status, [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED], true)) {
            return response()->json(['code' => 'integration_not_active', 'message' => 'The connection is not active.'], 409);
        }

        return response()->json($this->checks->check($integration));
    }
}
