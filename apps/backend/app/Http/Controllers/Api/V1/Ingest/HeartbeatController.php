<?php

namespace App\Http\Controllers\Api\V1\Ingest;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Integrations\IntegrationCredentialService;
use App\Support\Stores\StoreVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function store(Request $request, StoreVerificationService $verifications, ConnectorFreshness $freshness): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('integration');
        $report = $request->json()->all();
        $freshness->recordHeartbeat($integration, is_array($report) ? $report : []);

        return response()->json([
            'status' => 'ok',
            'integration_id' => $integration->id,
            'credential_rotation_requested' => isset($integration->health[IntegrationCredentialService::HEALTH_ROTATION_REQUESTED_AT]),
            'store_verification' => $this->pendingPluginChallenge($integration, $verifications),
        ]);
    }

    /**
     * @return array{id: string, challenge: string, url: string}|null
     */
    private function pendingPluginChallenge(Integration $integration, StoreVerificationService $verifications): ?array
    {
        /** @var StoreVerification|null $verification */
        $verification = StoreVerification::query()
            ->where('tenant_id', $integration->tenant_id)
            ->where('store_id', $integration->store_id)
            ->where('method', StoreVerification::METHOD_PLUGIN_CHALLENGE)
            ->where('status', StoreVerification::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if ($verification === null) {
            return null;
        }

        /** @var Store $store */
        $store = Store::query()->where('tenant_id', $integration->tenant_id)->whereKey($integration->store_id)->firstOrFail();

        return [
            'id' => $verification->id,
            'challenge' => $verifications->challenge($verification->id),
            'url' => $verifications->challengeUrl($verification, $store),
        ];
    }
}
