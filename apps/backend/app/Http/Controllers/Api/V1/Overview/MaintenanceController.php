<?php

namespace App\Http\Controllers\Api\V1\Overview;

use App\Http\Controllers\Controller;
use App\Support\Platform\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function show(Request $request, PlatformSettings $settings): JsonResponse
    {
        $locale = (string) ($request->user()?->locale ?? 'en');

        return response()->json(['notice' => $settings->forCustomer(in_array($locale, PlatformSettings::LOCALES, true) ? $locale : 'en')]);
    }
}
