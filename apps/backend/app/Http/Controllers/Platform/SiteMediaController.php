<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Support\Site\SiteMediaLibrary;
use App\Support\Site\SiteRejected;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

class SiteMediaController extends Controller
{
    public function __construct(private readonly SiteMediaLibrary $media) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->media->items(), 'limits' => $this->media->limits()]);
    }

    public function store(Request $request): JsonResponse|Response
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return response()->json(['code' => 'media_missing', 'message' => 'Choose a file to upload.'], 422);
        }

        return $this->guard(fn (PlatformStaff $staff): JsonResponse => response()->json($this->media->upload($staff->id, $file), 201), $request);
    }

    public function delete(Request $request, string $media): JsonResponse|Response
    {
        return $this->guard(function (PlatformStaff $staff) use ($media): Response {
            $this->media->delete($staff->id, $media);

            return response()->noContent();
        }, $request);
    }

    /**
     * @param  Closure(PlatformStaff): (JsonResponse|Response)  $action
     */
    private function guard(Closure $action, Request $request): JsonResponse|Response
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        try {
            return $action($staff);
        } catch (SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The file was rejected.'], $exception->status);
        }
    }
}
