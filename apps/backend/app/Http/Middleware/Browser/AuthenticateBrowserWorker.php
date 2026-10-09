<?php

namespace App\Http\Middleware\Browser;

use App\Models\BrowserWorker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBrowserWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $worker = is_string($token) && strlen($token) >= 32
            ? BrowserWorker::query()->where('token_hash', hash('sha256', $token))->where('status', BrowserWorker::STATUS_ACTIVE)->first()
            : null;

        if ($worker === null) {
            return response()->json([
                'code' => 'worker_unauthorized',
                'message' => 'A valid worker credential is required.',
                'request_id' => (string) Str::uuid(),
            ], 401);
        }

        $request->attributes->set('browser_worker', $worker);

        return $next($request);
    }
}
