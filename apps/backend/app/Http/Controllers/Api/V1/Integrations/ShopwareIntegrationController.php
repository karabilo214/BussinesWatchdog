<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Dto\Integrations\IntegrationDto;
use App\Models\Integration;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Providers\Shopware\ShopwareConnector;
use App\Support\Providers\Shopware\ShopwareSync;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopwareIntegrationController extends Controller
{
    public function __construct(
        private readonly ShopwareConnector $connector,
        private readonly ShopwareSync $sync,
        private readonly IntegrationDto $integrationDto,
    ) {}

    public function connect(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('connect shopware'), 404);
        $validated = $request->validate([
            'access_key' => ['required', 'string', 'max:255'],
            'secret_access_key' => ['required', 'string', 'max:255'],
            'state_change_risk_acknowledged' => ['required', 'boolean'],
        ]);

        try {
            $integration = $this->connector->connect($store, trim($validated['access_key']), trim($validated['secret_access_key']), (bool) $validated['state_change_risk_acknowledged'], $request->user()?->id);
        } catch (AccountRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
        }

        return response()->json($this->integrationDto->toArray($integration->load('credentials')), 201);
    }

    public function sync(Request $request, Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($integration->tenant_id === $tenantContext->requireTenantId('sync shopware') && $integration->provider === ShopwareConnector::PROVIDER, 404);
        $validated = $request->validate(['kind' => ['sometimes', Rule::in([ShopwareSync::MODE_DELTA, ShopwareSync::MODE_AUDIT])]]);

        if (! $this->sync->syncable($integration)) {
            return response()->json(['code' => 'integration_not_active', 'message' => 'Only an active integration can be synchronised.'], 409);
        }

        $result = $this->sync->run($integration, $validated['kind'] ?? ShopwareSync::MODE_DELTA);

        return response()->json([...$result, 'integration' => $this->integrationDto->toArray($integration->refresh()->load('credentials'))], $result['status'] === 'failed' ? 502 : 200);
    }
}
