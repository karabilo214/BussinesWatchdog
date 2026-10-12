<?php

namespace App\Http\Middleware\Platform;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UsePlatformSession
{
    private const KEYS = ['session.cookie', 'session.lifetime', 'session.expire_on_close', 'session.same_site'];

    public function handle(Request $request, Closure $next): Response
    {
        $previous = [];

        foreach (self::KEYS as $key) {
            $previous[$key] = config($key);
        }

        config([
            'session.cookie' => config('watchdog.platform.session_cookie'),
            'session.lifetime' => (int) config('watchdog.platform.idle_minutes'),
            'session.expire_on_close' => true,
            'session.same_site' => 'strict',
        ]);

        try {
            return $next($request);
        } finally {
            config($previous);
        }
    }
}
