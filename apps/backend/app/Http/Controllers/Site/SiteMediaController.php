<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\SiteMediaLibrary;
use Symfony\Component\HttpFoundation\Response;

class SiteMediaController extends Controller
{
    public function show(SiteMediaLibrary $library, string $media, string $extension): Response
    {
        $served = $library->served($media, $extension);

        abort_if($served === null, 404);

        return response($served['body'], 200, [
            'Content-Type' => $served['content_type'],
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
