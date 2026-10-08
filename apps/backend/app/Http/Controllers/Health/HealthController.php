<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Support\Health\ReadinessChecks;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'backend',
        ]);
    }

    public function ready(ReadinessChecks $readinessChecks): JsonResponse
    {
        $checks = $readinessChecks->all();

        $ready = collect($checks)->every(fn (array $check): bool => $check['ok']);

        return response()->json([
            'status' => $ready ? 'ok' : 'unavailable',
            'service' => 'backend',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
