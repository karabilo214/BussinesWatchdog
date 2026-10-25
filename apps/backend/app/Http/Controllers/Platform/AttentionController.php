<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Support\Platform\PlatformAttention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttentionController extends Controller
{
    public function show(Request $request, PlatformAttention $attention): JsonResponse
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        return response()->json($attention->forStaff($staff))->header('Cache-Control', 'no-store');
    }
}
