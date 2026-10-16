<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Providers\Stripe\StripeAppOAuth;
use App\Support\Providers\Stripe\StripeRequestFailed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StripeAppOAuthController extends Controller
{
    public function __construct(private readonly StripeAppOAuth $oauth) {}

    public function availability(Store $store, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('view stripe app availability'), 404);

        return response()->json(['test' => $this->oauth->isConfigured('test'), 'live' => $this->oauth->isConfigured('live')]);
    }

    public function start(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('connect stripe app'), 404);
        $validated = $request->validate(['mode' => ['required', Rule::in(['test', 'live'])]]);

        try {
            return response()->json($this->oauth->start($store, $request->user()->id, $validated['mode']));
        } catch (AccountRejected $exception) {
            return response()->json(['code' => $exception->reasonCode], $exception->status);
        }
    }

    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'string', 'max:128'],
            'code' => ['sometimes', 'string', 'max:255'],
            'error' => ['sometimes', 'string', 'max:100'],
        ]);

        try {
            $result = $this->oauth->complete($validated['state'], $validated['code'] ?? null, $validated['error'] ?? null);
        } catch (AccountRejected|StripeRequestFailed $exception) {
            return response()->json(['code' => $exception instanceof AccountRejected ? $exception->reasonCode : $exception->reasonCode],
                $exception instanceof AccountRejected ? $exception->status : 502);
        }

        return redirect()->to(url('/app/stores/'.$result['store_id']).'?'.http_build_query([
            'stripe_app' => $result['status'],
            ...(isset($result['error_code']) ? ['stripe_error' => $result['error_code']] : []),
        ], '', '&', PHP_QUERY_RFC3986));
    }
}
