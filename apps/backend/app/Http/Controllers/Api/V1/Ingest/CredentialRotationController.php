<?php

namespace App\Http\Controllers\Api\V1\Ingest;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Support\Integrations\IntegrationCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CredentialRotationController extends Controller
{
    public function __construct(
        private readonly IntegrationCredentialService $credentials,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('integration');
        /** @var IntegrationCredential $signer */
        $signer = $request->attributes->get('integration_credential');

        if ($integration->status === Integration::STATUS_REVOKED) {
            return response()->json([
                'code' => 'credential_revoked',
                'message' => 'Integration is revoked.',
            ], 401);
        }

        $issued = $this->credentials->rotatePluginCredential($integration, $signer);

        return response()->json([
            'integration_id' => $integration->id,
            'key_id' => $issued['credential']->key_id,
            'secret' => $issued['secret'],
            'secret_encoding' => 'base64',
            'signature_version' => 1,
            'previous_key_draining_until' => $signer->fresh()?->expires_at?->toJSON(),
        ], 201);
    }
}
