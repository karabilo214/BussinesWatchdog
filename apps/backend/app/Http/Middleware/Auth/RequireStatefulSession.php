<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStatefulSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return response()->json([
                'code' => 'stateful_session_required',
                'message' => 'This endpoint is only available to the same-origin web application.',
            ], 400);
        }

        return $next($request);
    }
}
