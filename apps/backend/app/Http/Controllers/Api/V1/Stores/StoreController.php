<?php

namespace App\Http\Controllers\Api\V1\Stores;

use App\Http\Controllers\Controller;
use App\Http\Dto\Stores\StoreDto;
use App\Http\Requests\Stores\CreateStoreRequest;
use App\Http\Requests\Stores\UpdateStoreRequest;
use App\Models\Store;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    public function __construct(
        private readonly StoreDto $storeDto,
    ) {
    }

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

        $store = Store::query()->create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'base_url' => $validated['base_url'],
            'timezone' => $validated['timezone'],
            'locale' => $validated['locale'],
            'default_currency' => $validated['default_currency'],
        ]);

        return response()->json($this->storeDto->toArray($store->refresh()), 201);
    }

    public function show(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read store');

        abort_unless($store->tenant_id === $tenantId, 404);

        return response()->json($this->storeDto->toArray($store));
    }

    public function update(UpdateStoreRequest $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('update store');

        abort_unless($store->tenant_id === $tenantId, 404);

        $expectedVersion = $this->expectedVersion($request);
        $validated = $request->validated();

        $updated = DB::transaction(function () use ($store, $validated, $expectedVersion): ?Store {
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

        return response()->json($this->storeDto->toArray($updated));
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
