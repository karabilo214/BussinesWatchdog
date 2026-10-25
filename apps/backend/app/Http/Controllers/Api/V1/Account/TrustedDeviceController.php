<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Account\TrustedDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrustedDeviceController extends Controller
{
    public function index(Request $request, TrustedDevices $devices): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $devices->listing($request, $user)]);
    }

    public function destroy(Request $request, TrustedDevices $devices, string $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $devices->revoke($request, $user, $device)) {
            return response()->json(['code' => 'trusted_device_not_found'], 404);
        }

        return response()->json(null, 204);
    }
}
