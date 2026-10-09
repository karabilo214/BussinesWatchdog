<?php

namespace App\Support\Browser;

use App\Models\CheckRun;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Support\Integrations\IntegrationCredentialService;
use Illuminate\Support\Carbon;

class SyntheticMarker
{
    public const HEADER = 'X-BW-Synthetic';

    private const PURPOSE = 'bw-synthetic-v1';

    public function __construct(
        private readonly IntegrationCredentialService $credentials,
    ) {}

    /**
     * Short-lived token the worker sends to the store origin only. It is signed with a key
     * derived from the store connector's own secret, so only that store's plugin can verify
     * it, and it binds the connector, the run and an expiry. Never persisted or logged.
     */
    public function issue(CheckRun $run, Carbon $expiresAt): ?string
    {
        /** @var Integration|null $connector */
        $connector = Integration::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('store_id', $run->store_id)
            ->where('source_authority', Integration::SOURCE_STORE_REPORTED)
            ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])
            ->latest('created_at')
            ->first();

        /** @var IntegrationCredential|null $credential */
        $credential = $connector === null ? null : IntegrationCredential::query()
            ->where('integration_id', $connector->id)
            ->where('kind', IntegrationCredential::KIND_PLUGIN_HMAC)
            ->where('status', IntegrationCredential::STATUS_ACTIVE)
            ->latest('created_at')
            ->first();

        if ($credential === null) {
            return null;
        }

        $secret = base64_decode($this->credentials->secretFor($credential), true);

        if ($secret === false || strlen($secret) !== 32) {
            return null;
        }

        $payload = rtrim(strtr(base64_encode(json_encode([
            'i' => $connector->id,
            'r' => $run->id,
            'e' => $expiresAt->getTimestamp(),
            'k' => $credential->key_id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return 'v1.'.$payload.'.'.hash_hmac('sha256', 'v1.'.$payload, hash_hmac('sha256', self::PURPOSE, $secret, true));
    }
}
