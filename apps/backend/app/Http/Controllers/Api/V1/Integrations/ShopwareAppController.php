<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Providers\Shopware\ShopwareApp;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ShopwareAppController extends Controller
{
    public function __construct(
        private readonly ShopwareApp $app,
    ) {}

    public function package(Request $request, Store $store, TenantContext $tenantContext): Response
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('download shopware app'), 404);

        $validated = $request->validate(['state_change_risk_acknowledged' => ['required', 'boolean']]);

        try {
            $package = $this->app->package($store, (bool) $validated['state_change_risk_acknowledged'], $request->user()?->id);
        } catch (AccountRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
        }

        return response($package['archive'], 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$package['filename'].'"',
            'Cache-Control' => 'no-store',
            'X-Integration-Id' => $package['integration']->id,
        ]);
    }

    public function register(Request $request, string $integration): JsonResponse
    {
        try {
            $result = $this->app->register($this->find($integration), (string) $request->server('QUERY_STRING'), (string) $request->header('shopware-app-signature'), $request->query());
        } catch (AccountRejected $exception) {
            return response()->json(['error' => $exception->reasonCode], $exception->status);
        }

        return response()->json($result);
    }

    public function confirm(Request $request, string $integration): JsonResponse
    {
        try {
            $this->app->confirm($this->find($integration), $request->getContent(), (string) $request->header('shopware-shop-signature'));
        } catch (AccountRejected $exception) {
            return response()->json(['error' => $exception->reasonCode], $exception->status);
        }

        return response()->json(['confirmed' => true]);
    }

    public function webhook(Request $request, string $integration): JsonResponse
    {
        try {
            $result = $this->app->webhook($this->find($integration), $request->getContent(), (string) $request->header('shopware-shop-signature'));
        } catch (AccountRejected $exception) {
            return response()->json(['error' => $exception->reasonCode], $exception->status);
        }

        return response()->json(['received' => true, ...$result]);
    }

    private function find(string $id): Integration
    {
        $integration = Str::isUuid($id) ? Integration::query()->whereKey($id)->where('provider', 'shopware')->first() : null;

        if ($integration === null) {
            throw new AccountRejected('integration_not_found', 404);
        }

        return $integration;
    }
}
