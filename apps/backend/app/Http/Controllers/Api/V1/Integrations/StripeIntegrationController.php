<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Dto\Integrations\IntegrationDto;
use App\Models\Integration;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Providers\Stripe\StripeConnector;
use App\Support\Providers\Stripe\StripeSync;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StripeIntegrationController extends Controller
{
    public function __construct(
        private readonly StripeConnector $connector,
        private readonly StripeSync $sync,
        private readonly IntegrationDto $integrationDto,
    ) {}

    public function connect(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('connect stripe'), 404);
        $validated = $request->validate([
            'restricted_api_key' => ['required', 'string', 'max:255'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expected_account_id' => ['sometimes', 'nullable', 'string', 'regex:/^acct_[A-Za-z0-9]+$/'],
        ]);

        try {
            $integration = $this->connector->connect(
                $store,
                trim($validated['restricted_api_key']),
                isset($validated['webhook_secret']) ? trim($validated['webhook_secret']) : null,
                $validated['expected_account_id'] ?? null,
                $request->user()?->id,
            );
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->withWebhook($integration), 201);
    }

    public function webhookSecret(Request $request, Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        $this->assertStripe($integration, $tenantContext);
        $validated = $request->validate(['webhook_secret' => ['required', 'string', 'max:255']]);

        try {
            $updated = $this->connector->setWebhookSecret($integration, trim($validated['webhook_secret']), $request->user()?->id);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->withWebhook($updated));
    }

    public function sync(Request $request, Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        $this->assertStripe($integration, $tenantContext);
        $validated = $request->validate(['kind' => ['sometimes', Rule::in([StripeSync::MODE_DELTA, StripeSync::MODE_AUDIT])]]);

        if ($integration->status !== Integration::STATUS_ACTIVE) {
            return response()->json(['code' => 'integration_not_active', 'message' => 'Only an active integration can be synchronised.'], 409);
        }

        $result = $this->sync->run($integration, $validated['kind'] ?? StripeSync::MODE_DELTA);

        return response()->json([...$result, 'integration' => $this->withWebhook($integration->refresh())], $result['status'] === 'ok' ? 200 : 502);
    }

    /**
     * @return array<string, mixed>
     */
    private function withWebhook(Integration $integration): array
    {
        return [
            ...$this->integrationDto->toArray($integration->load('credentials')),
            'webhook_url' => url('/api/v1/webhooks/stripe/'.$integration->id),
        ];
    }

    private function assertStripe(Integration $integration, TenantContext $tenantContext): void
    {
        abort_unless($integration->tenant_id === $tenantContext->requireTenantId('manage stripe') && $integration->provider === 'stripe', 404);
    }

    private function rejected(AccountRejected $exception): JsonResponse
    {
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
    }
}
