<?php

namespace App\Http\Controllers\Api\V1\Stores;

use App\Http\Controllers\Controller;
use App\Http\Dto\Stores\StoreDto;
use App\Models\AuditLog;
use App\Models\Store;
use App\Support\Checkout\CheckoutLayout;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saves the store's checkout pages and elements (ADR 0075); an empty field falls back to the Shopware default.
 */
class StoreCheckoutLayoutController extends Controller
{
    public function update(Request $request, Store $store, TenantContext $tenantContext, StoreDto $storeDto): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('update checkout layout'), 404);

        if (! CheckoutLayout::supports($store)) {
            return response()->json(['code' => 'checkout_layout_unsupported', 'message' => 'Checkout pages and elements are set only for Shopware stores.'], 422);
        }

        $layout = CheckoutLayout::validate($request->all());

        $updated = DB::transaction(function () use ($store, $layout, $request): Store {
            /** @var Store $locked */
            $locked = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $settings = $locked->settings ?? [];
            $previous = CheckoutLayout::custom($locked);

            if ($layout === []) {
                unset($settings['checkout_layout']);
            } else {
                $settings['checkout_layout'] = $layout;
            }

            $locked->settings = $settings;
            $locked->save();

            if ($previous !== $layout) {
                AuditLog::query()->create([
                    'tenant_id' => $locked->tenant_id,
                    'store_id' => $locked->id,
                    'actor_user_id' => $request->user()?->id,
                    'actor_type' => AuditLog::ACTOR_USER,
                    'action' => AuditLog::ACTION_STORE_CHECKOUT_LAYOUT_SAVED,
                    'entity_type' => AuditLog::ENTITY_STORE,
                    'entity_id' => $locked->id,
                    'changes' => ['fields' => array_keys($layout)],
                    'request_id' => (string) Str::uuid(),
                    'created_at' => Carbon::now(),
                ]);
            }

            return $locked->refresh();
        });

        return response()->json($storeDto->toArray($updated));
    }
}
