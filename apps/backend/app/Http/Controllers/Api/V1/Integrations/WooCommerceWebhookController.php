<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Support\Providers\WooCommerce\WooCommerceApiConnector;
use App\Support\Providers\WooCommerce\WooCommerceApiWebhooks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * WooCommerce's own order webhooks for a REST API connection (ADR 0077): the merchant sets them up in WooCommerce;
 * a signed delivery asks for a sync at once.
 */
class WooCommerceWebhookController extends Controller
{
    public function __construct(private readonly WooCommerceApiWebhooks $webhooks) {}

    public function setUp(Request $request, Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($integration->tenant_id === $tenantContext->requireTenantId('set up woocommerce webhook') && $integration->provider === WooCommerceApiConnector::PROVIDER, 404);

        if (! in_array($integration->status, [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED], true)) {
            return response()->json(['code' => 'integration_not_active', 'message' => 'The connection is not active.'], 409);
        }

        return response()->json($this->webhooks->setUp($integration, $request->user()?->id));
    }

    public function store(Request $request, string $integration): JsonResponse
    {
        $target = Str::isUuid($integration) ? Integration::query()->whereKey($integration)->where('provider', WooCommerceApiConnector::PROVIDER)->first() : null;

        if ($target === null) {
            return response()->json(['code' => 'integration_not_found'], 404);
        }

        $headers = array_map(fn (array $values): string => (string) ($values[0] ?? ''), array_change_key_case($request->headers->all(), CASE_LOWER));
        $result = $this->webhooks->receive($target, $request->getContent(), $headers);

        return response()->json($result['status'] === 200 ? ['received' => true, 'result' => $result['result']] : ['code' => $result['result']], $result['status']);
    }
}
