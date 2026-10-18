<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Dto\Integrations\IntegrationDto;
use App\Models\Integration;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Billing\StoreCapacity;
use App\Support\Providers\WooCommerce\WooCommerceApiConnector;
use App\Support\Providers\WooCommerce\WooCommerceApiSync;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WooCommerceApiIntegrationController extends Controller
{
    public function __construct(
        private readonly WooCommerceApiConnector $connector,
        private readonly WooCommerceApiSync $sync,
        private readonly IntegrationDto $integrationDto,
    ) {}

    public function connect(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('connect woocommerce api'), 404);
        $validated = $request->validate([
            'consumer_key' => ['required', 'string', 'max:255'],
            'consumer_secret' => ['required', 'string', 'max:255'],
        ]);

        try {
            $integration = $this->connector->connect($store, trim($validated['consumer_key']), trim($validated['consumer_secret']), $request->user()?->id);
        } catch (AccountRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
        }

        return response()->json($this->integrationDto->toArray($integration->load('credentials')), 201);
    }

    public function sync(Request $request, Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($integration->tenant_id === $tenantContext->requireTenantId('sync woocommerce api') && $integration->provider === WooCommerceApiConnector::PROVIDER, 404);
        $validated = $request->validate(['kind' => ['sometimes', Rule::in([WooCommerceApiSync::MODE_DELTA, WooCommerceApiSync::MODE_RECENT, WooCommerceApiSync::MODE_AUDIT])]]);

        if (! $this->sync->syncable($integration)) {
            return response()->json(['code' => 'integration_not_active', 'message' => 'Only an active integration can be synchronised.'], 409);
        }

        if (StoreCapacity::paused($integration)) {
            return response()->json(['code' => StoreCapacity::ERROR_PAUSED, 'message' => 'The store is paused by the plan.'], 409);
        }

        $result = $this->sync->run($integration, $validated['kind'] ?? WooCommerceApiSync::MODE_DELTA);

        return response()->json([...$result, 'integration' => $this->integrationDto->toArray($integration->refresh()->load('credentials'))], $result['status'] === 'failed' ? 502 : 200);
    }
}
