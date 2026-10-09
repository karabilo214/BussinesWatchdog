<?php

namespace App\Http\Controllers\Api\V1\StoreVerifications;

use App\Http\Controllers\Controller;
use App\Http\Dto\StoreVerifications\StoreVerificationDto;
use App\Http\Requests\StoreVerifications\CreateStoreVerificationRequest;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Support\Stores\StoreVerificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class StoreVerificationController extends Controller
{
    public function __construct(
        private readonly StoreVerificationDto $storeVerificationDto,
        private readonly StoreVerificationService $verifications,
    ) {}

    public function store(CreateStoreVerificationRequest $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('create store verification');

        abort_unless($store->tenant_id === $tenantId, 404);

        $verification = $this->verifications->start($store, $request->validated()['method']);
        $instructions = $this->verifications->instructions($verification, $store);

        return response()->json([
            ...$this->storeVerificationDto->toArray($verification),
            'challenge' => $this->verifications->challenge($verification->id),
            'instructions' => $instructions,
        ], 202);
    }

    public function show(Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read store verification');

        abort_unless($store->tenant_id === $tenantId, 404);

        $verification = $this->latest($tenantId, $store);

        abort_if($verification === null, 404);

        if ($verification->status === StoreVerification::STATUS_PENDING && $verification->expires_at->isPast()) {
            $verification->forceFill(['status' => StoreVerification::STATUS_EXPIRED])->save();
        }

        return response()->json($this->withPendingInstructions($verification->refresh(), $store));
    }

    public function check(Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('check store verification');

        abort_unless($store->tenant_id === $tenantId, 404);

        $verification = $this->latest($tenantId, $store);

        abort_if($verification === null, 404);

        return response()->json($this->withPendingInstructions($this->verifications->check($verification)->refresh(), $store));
    }

    /**
     * @return array<string, mixed>
     */
    private function withPendingInstructions(StoreVerification $verification, Store $store): array
    {
        $payload = $this->storeVerificationDto->toArray($verification);

        if ($verification->status === StoreVerification::STATUS_PENDING) {
            $payload['instructions'] = $this->verifications->instructions($verification, $store);
        }

        return $payload;
    }

    private function latest(string $tenantId, Store $store): ?StoreVerification
    {
        return StoreVerification::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $store->id)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }
}
