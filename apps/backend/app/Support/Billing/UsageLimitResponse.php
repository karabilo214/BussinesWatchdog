<?php

namespace App\Support\Billing;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class UsageLimitResponse
{
    public function __construct(
        private readonly UsageMeter $usage,
        private readonly EntitlementCalculator $entitlements,
    ) {}

    public function response(string $tenantId, string $metric, string $message): JsonResponse
    {
        $current = $this->usage->current($tenantId, $metric);
        $retryAfter = max(1, (int) Carbon::now()->diffInSeconds($current['period_end'], true));

        return response()->json([
            'code' => UsageMeter::ERROR_LIMIT_REACHED,
            'message' => $message,
            'errors' => [[
                'limit' => $metric,
                'value' => $this->entitlements->forTenant($tenantId)->limit($metric),
                'used' => $current['used'],
                'resets_at' => $current['period_end']->toJSON(),
            ]],
            'retry_after_seconds' => $retryAfter,
        ], 429)->header('Retry-After', (string) $retryAfter);
    }
}
