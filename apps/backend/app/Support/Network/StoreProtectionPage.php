<?php

namespace App\Support\Network;

use Illuminate\Http\Client\Response;

/** Detect common access pages instead of reporting them as malformed store API JSON. */
final class StoreProtectionPage
{
    public static function reason(Response $response): ?string
    {
        if ($response->status() === 401 && str_contains(strtolower((string) $response->header('WWW-Authenticate')), 'basic')) {
            return 'basic_auth';
        }

        $type = strtolower((string) $response->header('Content-Type'));
        $body = substr(strtolower($response->body()), 0, 32768);

        if (! str_contains($type, 'html') && ! preg_match('/^\s*<!doctype html|^\s*<html/i', $body)) {
            return null;
        }

        if (str_contains($body, 'cf-chl-') || str_contains($body, 'cloudflare') || str_contains($body, 'checking your browser') || str_contains($body, 'captcha')) {
            return 'challenge';
        }

        if (str_contains($body, 'maintenance') || str_contains($body, 'wartungsarbeiten') || str_contains($body, 'технические работы')) {
            return 'maintenance';
        }

        return null;
    }
}
