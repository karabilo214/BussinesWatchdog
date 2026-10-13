<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Support\Integrations\PluginPackage;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PluginDownloadController extends Controller
{
    public function show(PluginPackage $package): JsonResponse
    {
        $latest = $package->latest();

        return response()->json($latest === null ? ['available' => false] : ['available' => true] + array_diff_key($latest, ['path' => true]));
    }

    public function download(PluginPackage $package): BinaryFileResponse|JsonResponse
    {
        $latest = $package->latest();

        if ($latest === null) {
            return response()->json(['code' => 'plugin_not_available', 'message' => 'The plugin package is not published yet.'], 404);
        }

        return response()->download($latest['path'], $latest['file'], ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-SHA256' => $latest['sha256']]);
    }
}
