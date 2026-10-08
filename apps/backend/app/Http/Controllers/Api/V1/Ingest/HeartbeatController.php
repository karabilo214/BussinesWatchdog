<?php

namespace App\Http\Controllers\Api\V1\Ingest;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('integration');
        $integration->forceFill(['last_heartbeat_at' => now()])->save();

        return response()->json([
            'status' => 'ok',
            'integration_id' => $integration->id,
        ]);
    }
}
