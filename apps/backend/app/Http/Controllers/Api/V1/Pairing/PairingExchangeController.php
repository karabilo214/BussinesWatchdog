<?php

namespace App\Http\Controllers\Api\V1\Pairing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pairing\ExchangePairingCodeRequest;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\PairingCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PairingExchangeController extends Controller
{
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
                return ['error' => ['code' => 'pairing_consumed', 'message' => 'Pairing code was already consumed.', 'status' => 409]];
            }

            if ($pairingCode->expires_at->isPast()) {
                return ['error' => ['code' => 'pairing_expired', 'message' => 'Pairing code has expired.', 'status' => 410]];
            }

            if ($pairingCode->attempt_count >= PairingCode::MAX_ATTEMPTS) {
                return ['error' => ['code' => 'pairing_attempts_exceeded', 'message' => 'Pairing code attempt limit exceeded.', 'status' => 429]];
            }

            $pairingCode->increment('attempt_count');
            $pairingCode->refresh();
            $store = $pairingCode->store()->lockForUpdate()->firstOrFail();

            if ($validated['base_url'] !== $store->base_url) {
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

            $secret = random_bytes(32);
            $secretBase64 = base64_encode($secret);
            $credential = IntegrationCredential::query()->create([
                'tenant_id' => $pairingCode->tenant_id,
                'store_id' => $pairingCode->store_id,
                'integration_id' => $integration->id,
                'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
                'key_id' => 'bwk_'.Str::lower(Str::random(32)),
                'ciphertext' => Crypt::encryptString($secretBase64),
                'key_version' => 1,
                'fingerprint' => hash('sha256', $secret),
                'status' => IntegrationCredential::STATUS_ACTIVE,
                'created_at' => now(),
            ]);

            $pairingCode->forceFill(['consumed_at' => now()])->save();

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
}
