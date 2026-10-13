<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\Platform\BusinessOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OverviewController extends Controller
{
    public function show(Request $request, BusinessOverview $overview): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['sometimes', 'string', 'in:live,test'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $to = isset($validated['to']) ? Carbon::createFromFormat('Y-m-d', $validated['to'], 'UTC')->startOfDay()->addDay() : Carbon::now('UTC')->startOfDay()->addDay();
        $from = isset($validated['from']) ? Carbon::createFromFormat('Y-m-d', $validated['from'], 'UTC')->startOfDay() : $to->copy()->subDays(30);

        if ($from->greaterThanOrEqualTo($to) || $from->diffInDays($to) > 366) {
            return response()->json(['code' => 'period_invalid', 'message' => 'The period must be 1 to 366 days.'], 422);
        }

        return response()->json($overview->build($validated['mode'] ?? 'live', $from, $to));
    }
}
