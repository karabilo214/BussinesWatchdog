<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Dto\Integrations\IntegrationDto;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationDto $integrationDto,
    ) {
    }

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list integrations');
        $storeId = $request->query('store_id');

        $query = Integration::query()
            ->with('credentials')
            ->where('tenant_id', $tenantId);

        if (is_string($storeId) && $storeId !== '') {
            $query->where('store_id', $storeId);
        }

        $integrations = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $this->integrationDto->collection($integrations),
            'next_cursor' => null,
        ]);
    }

    public function show(Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read integration');

        abort_unless($integration->tenant_id === $tenantId, 404);

        return response()->json($this->integrationDto->toArray($integration));
    }

    public function revoke(Request $request, Integration $integration, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('revoke integration');

        abort_unless($integration->tenant_id === $tenantId, 404);

        $revoked = DB::transaction(function () use ($request, $integration): Integration {
            /** @var Integration $locked */
            $locked = Integration::query()
                ->whereKey($integration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = $locked->status;

            if ($locked->status !== Integration::STATUS_REVOKED) {
                $locked->forceFill([
                    'status' => Integration::STATUS_REVOKED,
                    'health' => array_merge($locked->health ?? [], [
                        'revoked_at' => now()->toJSON(),
                    ]),
                    'updated_at' => now(),
                ])->save();

                IntegrationCredential::query()
                    ->where('integration_id', $locked->id)
                    ->where('status', '!=', IntegrationCredential::STATUS_REVOKED)
                    ->update([
                        'status' => IntegrationCredential::STATUS_REVOKED,
                        'rotated_at' => now(),
                    ]);
            }

            AuditLog::query()->create([
                'tenant_id' => $locked->tenant_id,
                'store_id' => $locked->store_id,
                'actor_user_id' => $request->user()?->id,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => AuditLog::ACTION_INTEGRATION_REVOKED,
                'entity_type' => AuditLog::ENTITY_INTEGRATION,
                'entity_id' => $locked->id,
                'changes' => [
                    'status' => [
                        'from' => $previousStatus,
                        'to' => Integration::STATUS_REVOKED,
                    ],
                    'credentials' => [
                        'status' => IntegrationCredential::STATUS_REVOKED,
                    ],
                ],
                'request_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);

            return $locked->refresh()->load('credentials');
        });

        return response()->json($this->integrationDto->toArray($revoked));
    }
}
