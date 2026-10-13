<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PageBlock;
use Illuminate\Http\JsonResponse;

class PageBlockController extends Controller
{
    public function index(): JsonResponse
    {
        $blocks = PageBlock::query()->whereNull('archived_at')->orderBy('sort')->orderBy('name')->get();

        return response()->json(['data' => $blocks->map(fn (PageBlock $block): array => [
            'id' => $block->id,
            'category' => $block->category,
            'name' => $block->name,
            'bodies' => $block->bodies,
            'sort' => $block->sort,
            'updated_at' => ($block->updated_at ?? $block->created_at)?->toJSON(),
        ])->values()->all()]);
    }
}
