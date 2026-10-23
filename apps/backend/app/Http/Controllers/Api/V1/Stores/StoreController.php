<?php

namespace App\Http\Controllers\Api\V1\Stores;

use App\Http\Controllers\Controller;
use App\Http\Dto\Stores\StoreDto;
use App\Http\Requests\Stores\CreateStoreRequest;
use App\Http\Requests\Stores\UpdateStoreRequest;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Store;
use App\Rules\PublicHttpsUrl;
use App\Support\Account\StepUp;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Privacy\StoreDeletion;
use App\Support\Stores\StorePlatformDetector;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function detectPlatform(Request $request, StorePlatformDetector $detector): JsonResponse
    {
        $validated = $request->validate(['base_url' => ['required', 'url', 'max:2048', 'starts_with:https://', new PublicHttpsUrl]]);

        return response()->json(['detected_platform' => $detector->detect(rtrim($validated['base_url'], '/'))]);
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
            'platform' => $validated['platform'],
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

        $updated = DB::transaction(function () use ($store, $validated, $expectedVersion, $request): Store|string|null {
            /** @var Store $locked */
            $locked = Store::query()
                ->whereKey($store->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->config_version !== $expectedVersion) {
                return null;
            }

            $platformChanged = array_key_exists('platform', $validated) && $validated['platform'] !== $locked->platform;

            if ($platformChanged) {
                if (array_diff(array_keys($validated), ['platform', 'platform_change_confirmed']) !== []) {
                    return 'platform_change_only';
                }

                if (empty($validated['platform_change_confirmed'])) {
                    return 'platform_change_confirmation_required';
                }

                if (Integration::query()->where('tenant_id', $locked->tenant_id)->where('store_id', $locked->id)
                    ->whereIn('status', [Integration::STATUS_PENDING, Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])->exists()) {
                    return 'disconnect_integrations_first';
                }

                $oldPlatform = $locked->platform;
                app(StoreDeletion::class)->purgeData($locked);
                $locked->verified_at = null;
                $locked->browser_enabled = false;
                $locked->telemetry_enabled = false;
                $locked->status = 'onboarding';
                $locked->settings = [];
                AuditLog::query()->create([
                    'tenant_id' => $locked->tenant_id,
                    'store_id' => $locked->id,
                    'actor_user_id' => $request->user()?->id,
                    'actor_type' => AuditLog::ACTOR_USER,
                    'action' => AuditLog::ACTION_STORE_PLATFORM_CHANGED,
                    'entity_type' => AuditLog::ENTITY_STORE,
                    'entity_id' => $locked->id,
                    'changes' => ['from' => $oldPlatform, 'to' => $validated['platform'], 'previous_store_data_deleted' => true],
                    'request_id' => (string) Str::uuid(),
                    'created_at' => now(),
                ]);
            }

            $baseUrlChanged = array_key_exists('base_url', $validated)
                && $validated['base_url'] !== $locked->base_url;

            $locked->fill(collect($validated)->except('platform_change_confirmed')->all());

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

        if (in_array($updated, ['platform_change_confirmation_required', 'disconnect_integrations_first', 'platform_change_only'], true)) {
            return response()->json(['code' => $updated, 'message' => $updated], 422);
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
