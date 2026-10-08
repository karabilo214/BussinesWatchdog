<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Exceptions\Incidents\IncidentActionRejected;
use App\Http\Controllers\Controller;
use App\Http\Dto\Incidents\SuppressionDto;
use App\Models\Suppression;
use App\Support\Incidents\IncidentLifecycleService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class SuppressionController extends Controller
{
    public function __construct(
        private readonly SuppressionDto $suppressionDto,
        private readonly IncidentLifecycleService $lifecycle,
    ) {}

    public function revoke(Suppression $suppression, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('revoke suppression');

        abort_unless($suppression->tenant_id === $tenantId, 404);

        try {
            $revoked = $this->lifecycle->revokeSuppression($suppression);
        } catch (IncidentActionRejected $exception) {
            return response()->json([
                'code' => $exception->reasonCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        }

        return response()->json($this->suppressionDto->toArray($revoked));
    }
}
