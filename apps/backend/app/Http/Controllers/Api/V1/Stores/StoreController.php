<?php

namespace App\Http\Controllers\Api\V1\Stores;

use App\Http\Controllers\Controller;
use App\Http\Dto\Stores\StoreDto;
use App\Http\Requests\Stores\CreateStoreRequest;
use App\Http\Requests\Stores\UpdateStoreRequest;
use App\Models\Store;
use App\Support\Account\StepUp;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Privacy\StoreDeletion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    public function __construct(
        private readonly StoreDto $storeDto,
    ) {}

    public function index(TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list stores');

        $stores = Store::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $this->storeDto->collection($stores),
            'next_cursor' => null,
        ]);
    }

    public function store(CreateStoreRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('create store');
        $validated = $request->validated();
        $limit = app(EntitlementCalculator::class)->forTenant($tenantId)->limit(FeatureCatalog::MAX_ACTIVE_STORES);

        if ($limit !== null && Store::query()->where('tenant_id', $tenantId)->whereNotIn('status', ['deleted', 'deleting'])->count() >= $limit) {
            return response()->json(['code' => 'plan_store_limit_reached', 'message' => 'The plan does not include more stores.', 'errors' => [['limit' => FeatureCatalog::MAX_ACTIVE_STORES, 'value' => $limit]]], 403);
        }

        $store = Store::query()->create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'base_url' => $validated['base_url'],
            'timezone' => $validated['timezone'],
            'locale' => $validated['locale'],
            'default_currency' => $validated['default_currency'],
            'platform' => $validated['platform'] ?? 'woocommerce',
        ]);

        return response()->json($this->storeDto->toArray($store->refresh()), 201);
    }

    public function show(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read store');

        abort_unless($store->tenant_id === $tenantId, 404);

        return response()->json($this->storeDto->toArray($store))->setEtag((string) $store->config_version);
    }

    public function update(UpdateStoreRequest $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('update store');

        abort_unless($store->tenant_id === $tenantId, 404);

        $expectedVersion = $this->expectedVersion($request);
        $validated = $request->validated();

        $updated = DB::transaction(function () use ($store, $validated, $expectedVersion): Store|string|null {
            /** @var Store $locked */
            $locked = Store::query()
                ->whereKey($store->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->config_version !== $expectedVersion) {
                return null;
            }

            $baseUrlChanged = array_key_exists('base_url', $validated)
                && $validated['base_url'] !== $locked->base_url;

            $locked->fill($validated);

            if ($baseUrlChanged) {
                $locked->verified_at = null;
                $locked->browser_enabled = false;
            }

            if ($locked->verified_at === null && ($locked->browser_enabled || ($validated['status'] ?? null) === 'active')) {
                return 'store_not_verified';
            }

            $locked->config_version++;
            $locked->save();

            return $locked->refresh();
        });

        if ($updated === null) {
            return response()->json([
                'code' => 'version_conflict',
                'message' => 'Resource version conflict.',
            ], 409);
        }

        if ($updated === 'store_not_verified') {
            return response()->json([
                'code' => 'store_not_verified',
                'message' => 'Browser checks and activation require a verified store domain.',
            ], 422);
        }

        return response()->json($this->storeDto->toArray($updated))->setEtag((string) $updated->config_version);
    }

    public function destroy(Request $request, Store $store, TenantContext $tenantContext, StepUp $stepUp, StoreDeletion $deletion): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('delete store');
        abort_unless($store->tenant_id === $tenantId, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'current_password' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        $stepUp->require($request->user(), $validated['current_password'], $validated['code'] ?? null);

        DB::transaction(function () use ($store, $tenantId, $validated, $deletion): void {
            $locked = Store::query()->where('tenant_id', $tenantId)->whereKey($store->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($locked->name, trim($validated['name']))) {
                throw ValidationException::withMessages(['name' => 'store_name_mismatch']);
            }

            $deletion->delete($locked);
        });

        return response()->json(['status' => 'deleted']);
    }

    private function expectedVersion(Request $request): int
    {
        $header = $request->header('If-Match');

        abort_if($header === null, 428, 'If-Match header is required.');

        if (! preg_match('/^"?([1-9][0-9]*)"?$/', $header, $matches)) {
            abort(400, 'Invalid If-Match header.');
        }

        return (int) $matches[1];
    }
}
