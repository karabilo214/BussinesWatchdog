<?php

namespace App\Http\Controllers\Api\V1\Pairing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PairingCode;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Integrations\OrderSources;
use App\Support\Plugin\PluginConnectRequest;
use App\Support\Plugin\PluginFunnel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The account side of the plugin's one-click connection (ADR 0084). */
class PluginConnectController extends Controller
{
    public const CODE_TTL_MINUTES = 10;

    public function show(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('show plugin connection');
        $connect = PluginConnectRequest::fromSession($request);

        if ($connect === null) {
            return $this->error('plugin_connect_missing', 'There is no connection request from the plugin in this session.', 404);
        }

        $stores = Store::query()->where('tenant_id', $tenantId)->where('base_url', $connect->site)->orderBy('created_at')->orderBy('id')->get();

        return response()->json([
            'site' => $connect->site,
            'site_host' => $connect->siteHost(),
            'organization' => Tenant::query()->whereKey($tenantId)->value('name'),
            'expires_at' => $connect->expiresAt->toJSON(),
            'stores' => $stores->map(fn (Store $store): array => [
                'id' => $store->id,
                'name' => $store->name,
                'base_url' => $store->base_url,
                'platform' => $store->platform,
                'order_source' => OrderSources::active($store),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, PluginFunnel $funnel): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('approve plugin connection');
        $validated = $request->validate(['store_id' => ['required', 'uuid']]);
        $connect = PluginConnectRequest::fromSession($request);

        if ($connect === null) {
            return $this->error('plugin_connect_missing', 'There is no connection request from the plugin in this session.', 404);
        }

        $store = Store::query()->where('tenant_id', $tenantId)->whereKey($validated['store_id'])->first();

        if ($store === null) {
            return $this->error('store_not_found', 'Store was not found.', 404);
        }

        if ($store->base_url !== $connect->site) {
            return $this->error('plugin_connect_site_mismatch', 'The store address does not match the WordPress site.', 422);
        }

        if ($store->platform !== 'woocommerce') {
            return $this->error('plugin_connect_wrong_platform', 'Only a WooCommerce store can be connected with the plugin.', 422);
        }

        if (in_array(OrderSources::active($store), OrderSources::POLLED, true)) {
            return $this->error('order_source_already_connected', 'The store already sends orders through its REST API connection.', 409);
        }

        $code = 'bwpc_'.Str::lower(Str::random(40));

        DB::transaction(function () use ($request, $store, $connect, $code): void {
            $pairingCode = PairingCode::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'code_hash' => hash('sha256', $code),
                'code_challenge' => $connect->challenge,
                'funnel_journey' => $connect->journey,
                'created_by' => $request->user()->id,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'created_at' => now(),
            ]);

            AuditLog::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'actor_user_id' => $request->user()->id,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => AuditLog::ACTION_INTEGRATION_CONNECT_APPROVED,
                'entity_type' => AuditLog::ENTITY_PAIRING_CODE,
                'entity_id' => $pairingCode->id,
                'changes' => ['provider' => 'woocommerce', 'site_host' => $connect->siteHost()],
                'request_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);
        });

        $funnel->code($store->tenant_id, $store->id, $connect->journey);
        PluginConnectRequest::forget($request);

        return response()->json(['redirect_url' => $connect->redirectWith($code)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        PluginConnectRequest::forget($request);

        return response()->json(null, 204);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
