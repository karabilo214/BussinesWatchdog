<?php

namespace App\Http\Controllers\Api\V1\StoreVerifications;

use App\Http\Controllers\Controller;
use App\Http\Dto\StoreVerifications\StoreVerificationDto;
use App\Http\Requests\StoreVerifications\CreateStoreVerificationRequest;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class StoreVerificationController extends Controller
{
    public function __construct(
        private readonly StoreVerificationDto $storeVerificationDto,
    ) {
    }

    public function store(CreateStoreVerificationRequest $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('create store verification');

        abort_unless($store->tenant_id === $tenantId, 404);

        $validated = $request->validated();
        $method = $validated['method'];
        $challenge = 'bw-'.Str::lower(Str::random(32));
        $verification = StoreVerification::query()->create([
            'tenant_id' => $tenantId,
            'store_id' => $store->id,
            'method' => $method,
            'challenge_hash' => hash('sha256', $challenge),
            'verified_origin' => $store->base_url,
            'status' => StoreVerification::STATUS_PENDING,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
        ]);

        return response()->json([
            ...$this->storeVerificationDto->toArray($verification),
            'challenge' => $challenge,
            'instructions' => $this->instructions($method, $challenge, $store),
        ], 202);
    }

    public function show(Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read store verification');

        abort_unless($store->tenant_id === $tenantId, 404);

        $verification = StoreVerification::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $store->id)
            ->latest('created_at')
            ->latest('id')
            ->first();

        abort_if($verification === null, 404);

        if ($verification->status === StoreVerification::STATUS_PENDING && $verification->expires_at->isPast()) {
            $verification->forceFill(['status' => StoreVerification::STATUS_EXPIRED])->save();
        }

        return response()->json($this->storeVerificationDto->toArray($verification->refresh()));
    }

    /**
     * @return array<string, string>
     */
    private function instructions(string $method, string $challenge, Store $store): array
    {
        if ($method === StoreVerification::METHOD_DNS) {
            return [
                'type' => 'dns_txt',
                'host' => parse_url($store->base_url, PHP_URL_HOST) ?: '',
                'txt_value' => $challenge,
            ];
        }

        return [
            'type' => 'plugin_challenge',
            'path' => '/.well-known/business-watchdog-verification.txt',
            'body' => $challenge,
        ];
    }
}
