<?php

namespace App\Http\Controllers\Api\V1\Stores;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stores\CreateStoreRequest;
use App\Models\Store;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function index(TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list stores');

        $stores = Store::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Store $store): array => $this->storeDto($store))
            ->all();

        return response()->json([
            'data' => $stores,
            'next_cursor' => null,
        ]);
    }

    public function store(CreateStoreRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('create store');
        $validated = $request->validated();

        $store = Store::query()->create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'base_url' => $validated['base_url'],
            'timezone' => $validated['timezone'],
            'locale' => $validated['locale'],
            'default_currency' => $validated['default_currency'],
        ]);

        return response()->json($this->storeDto($store->refresh()), 201);
    }

    public function show(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read store');

        abort_unless($store->tenant_id === $tenantId, 404);

        return response()->json($this->storeDto($store));
    }

    /**
     * @return array<string, mixed>
     */
    private function storeDto(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'base_url' => $store->base_url,
            'platform' => $store->platform,
            'timezone' => $store->timezone,
            'locale' => $store->locale,
            'default_currency' => $store->default_currency,
            'status' => $store->status,
            'verified_at' => $store->verified_at?->toJSON(),
            'browser_enabled' => $store->browser_enabled,
            'telemetry_enabled' => $store->telemetry_enabled,
            'config_version' => $store->config_version,
            'coverage' => null,
            'last_successful_check_at' => null,
            'active_incident_count' => 0,
        ];
    }
}
