<?php

namespace App\Http\Controllers\Api\V1\Pairing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pairing\ExchangePairingCodeRequest;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\PairingCode;
use App\Support\Integrations\IntegrationCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PairingExchangeController extends Controller
{
    public function __construct(
        private readonly IntegrationCredentialService $credentials,
    ) {}

    public function store(ExchangePairingCodeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $codeHash = hash('sha256', $validated['pairing_code']);

        $result = DB::transaction(function () use ($codeHash, $validated): array {
            /** @var PairingCode|null $pairingCode */
            $pairingCode = PairingCode::query()
                ->where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            if ($pairingCode === null) {
                return ['error' => ['code' => 'pairing_not_found', 'message' => 'Pairing code was not found.', 'status' => 404]];
            }

            if ($pairingCode->consumed_at !== null) {
                $this->auditFailure($pairingCode, 'pairing_consumed');

                return ['error' => ['code' => 'pairing_consumed', 'message' => 'Pairing code was already consumed.', 'status' => 409]];
            }

            if ($pairingCode->expires_at->isPast()) {
                $this->auditFailure($pairingCode, 'pairing_expired');

                return ['error' => ['code' => 'pairing_expired', 'message' => 'Pairing code has expired.', 'status' => 410]];
            }

            if ($pairingCode->attempt_count >= PairingCode::MAX_ATTEMPTS) {
                $this->auditFailure($pairingCode, 'pairing_attempts_exceeded');

                return ['error' => ['code' => 'pairing_attempts_exceeded', 'message' => 'Pairing code attempt limit exceeded.', 'status' => 429]];
            }

            $pairingCode->increment('attempt_count');
            $pairingCode->refresh();
            $store = $pairingCode->store()->lockForUpdate()->firstOrFail();

            if ($validated['base_url'] !== $store->base_url) {
                $this->auditFailure($pairingCode, 'pairing_base_url_mismatch');

                return ['error' => ['code' => 'pairing_base_url_mismatch', 'message' => 'Pairing base URL does not match the store.', 'status' => 422]];
            }

            $integration = Integration::query()->create([
                'tenant_id' => $pairingCode->tenant_id,
                'store_id' => $pairingCode->store_id,
                'provider' => $validated['connector_code'],
                'install_id' => $validated['install_id'],
                'mode' => 'live',
                'source_authority' => Integration::SOURCE_STORE_REPORTED,
                'status' => Integration::STATUS_ACTIVE,
                'capabilities' => [],
                'connector_version' => $validated['plugin_version'],
                'health' => [],
            ]);

            ['credential' => $credential, 'secret' => $secretBase64] = $this->credentials->issuePluginCredential($integration);

            $pairingCode->forceFill(['consumed_at' => now()])->save();

            AuditLog::query()->create([
                'tenant_id' => $integration->tenant_id,
                'store_id' => $integration->store_id,
                'actor_user_id' => null,
                'actor_type' => AuditLog::ACTOR_CONNECTOR,
                'action' => AuditLog::ACTION_INTEGRATION_PAIRED,
                'entity_type' => AuditLog::ENTITY_INTEGRATION,
                'entity_id' => $integration->id,
                'changes' => [
                    'provider' => $integration->provider,
                    'status' => $integration->status,
                    'credential_kind' => $credential->kind,
                    'key_version' => $credential->key_version,
                ],
                'request_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);

            return [
                'payload' => [
                    'integration_id' => $integration->id,
                    'key_id' => $credential->key_id,
                    'secret' => $secretBase64,
                    'secret_encoding' => 'base64',
                    'signature_version' => 1,
                ],
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'code' => $result['error']['code'],
                'message' => $result['error']['message'],
            ], $result['error']['status']);
        }

        return response()->json($result['payload'], 201);
    }

    private function auditFailure(PairingCode $pairingCode, string $reasonCode): void
    {
        AuditLog::query()->create([
            'tenant_id' => $pairingCode->tenant_id,
            'store_id' => $pairingCode->store_id,
            'actor_user_id' => null,
            'actor_type' => AuditLog::ACTOR_CONNECTOR,
            'action' => AuditLog::ACTION_INTEGRATION_PAIRING_FAILED,
            'entity_type' => AuditLog::ENTITY_PAIRING_CODE,
            'entity_id' => $pairingCode->id,
            'changes' => [
                'reason_code' => $reasonCode,
                'attempt_count' => $pairingCode->attempt_count,
            ],
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }
}
