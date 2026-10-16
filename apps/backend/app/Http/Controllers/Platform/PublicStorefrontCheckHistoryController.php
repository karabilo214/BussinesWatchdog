<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PublicStorefrontCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicStorefrontCheckHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['cursor' => ['sometimes', 'nullable', 'string', 'max:512']]);

        $page = PublicStorefrontCheck::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (PublicStorefrontCheck $check): array => [
                'id' => $check->id,
                'url' => $check->normalized_origin,
                'created_at' => $check->created_at?->toJSON(),
                'started_at' => $check->started_at?->toJSON(),
                'finished_at' => $check->finished_at?->toJSON(),
                'ip' => $check->request_ip,
                'user_agent' => $check->request_user_agent,
                'worker_browser' => $check->browser_version,
                'status' => $check->status,
                'verdict' => $check->verdict,
                'error_code' => $check->error_code,
            ])->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
