<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\Plugin\PluginFunnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PluginFunnelController extends Controller
{
    public function show(Request $request, PluginFunnel $funnel): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $to = isset($validated['to']) ? Carbon::createFromFormat('Y-m-d', $validated['to'], 'UTC')->startOfDay()->addDay() : Carbon::now('UTC')->startOfDay()->addDay();
        $from = isset($validated['from']) ? Carbon::createFromFormat('Y-m-d', $validated['from'], 'UTC')->startOfDay() : $to->copy()->subDays(30);

        if ($from->greaterThanOrEqualTo($to) || $from->diffInDays($to) > 400) {
            return response()->json(['code' => 'period_invalid', 'message' => 'The period must be 1 to 400 days.'], 422);
        }

        return response()->json($funnel->report($from, $to));
    }
}
