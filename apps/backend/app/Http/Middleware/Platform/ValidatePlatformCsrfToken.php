<?php

namespace App\Http\Middleware\Platform;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Symfony\Component\HttpFoundation\Cookie;

class ValidatePlatformCsrfToken extends ValidateCsrfToken
{
    protected function getTokenFromRequest($request)
    {
        $header = $request->header('X-XSRF-TOKEN');

        if (! is_string($header) || $header === '') {
            return null;
        }

        try {
            return CookieValuePrefix::remove($this->encrypter->decrypt($header, false));
        } catch (DecryptException) {
            return '';
        }
    }

    protected function newCookie($request, $config)
    {
        return new Cookie(
            (string) config('watchdog.platform.csrf_cookie'),
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }

    protected function hasValidOrigin($request)
    {
        return false;
    }
}
