<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\Platform\ServiceHealth;
use Illuminate\Http\JsonResponse;

class ServiceHealthController extends Controller
{
    public function show(ServiceHealth $health): JsonResponse
    {
        return response()->json($health->build())->header('Cache-Control', 'no-store');
    }
}
