<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Dto\Integrations\IntegrationDto;
use App\Models\Integration;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Providers\PayPal\PayPalConnector;
use App\Support\Providers\PayPal\PayPalSync;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayPalIntegrationController extends Controller
{
    public function __construct(
        private readonly PayPalConnector $connector,
        private readonly PayPalSync $sync,
        private readonly IntegrationDto $integrationDto,
    ) {}

    public function connect(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('connect paypal'), 404);
        $validated = $request->validate([
            'environment' => ['required', Rule::in(['sandbox', 'live'])],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
            'webhook_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'write_access_acknowledged' => ['required', 'boolean'],
        ]);

        try {
            $integration = $this->connector->connect(
                $store,
                $validated['environment'],
                trim($validated['client_id']),
                trim($validated['client_secret']),
                isset($validated['webhook_id']) ? trim($validated['webhook_id']) : null,
                (bool) $validated['write_access_acknowledged'],
                $request->user()?->id,
            );
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->withWebhook($integration), 201);
    }

    public function webhookId(Request $request, Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        $this->assertPayPal($integration, $tenantContext);
        $validated = $request->validate(['webhook_id' => ['required', 'string', 'max:64']]);

        try {
            $updated = $this->connector->setWebhookId($integration, trim($validated['webhook_id']), $request->user()?->id);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->withWebhook($updated));
    }

    public function sync(Request $request, Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        $this->assertPayPal($integration, $tenantContext);
        $validated = $request->validate(['kind' => ['sometimes', Rule::in([PayPalSync::MODE_DELTA, PayPalSync::MODE_AUDIT])]]);

        if ($integration->status !== Integration::STATUS_ACTIVE) {
            return response()->json(['code' => 'integration_not_active', 'message' => 'Only an active integration can be synchronised.'], 409);
        }

        $result = $this->sync->run($integration, $validated['kind'] ?? PayPalSync::MODE_DELTA);

        return response()->json([...$result, 'integration' => $this->withWebhook($integration->refresh())], $result['status'] === 'ok' ? 200 : 502);
    }

    /**
     * @return array<string, mixed>
     */
    private function withWebhook(Integration $integration): array
    {
        return [
            ...$this->integrationDto->toArray($integration->load('credentials')),
            'webhook_url' => url('/api/v1/webhooks/paypal/'.$integration->id),
        ];
    }

    private function assertPayPal(Integration $integration, TenantContext $tenantContext): void
    {
        abort_unless($integration->tenant_id === $tenantContext->requireTenantId('manage paypal') && $integration->provider === 'paypal', 404);
    }

    private function rejected(AccountRejected $exception): JsonResponse
    {
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
    }
}
