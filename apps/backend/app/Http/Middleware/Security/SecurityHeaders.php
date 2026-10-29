<?php

namespace App\Http\Middleware\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Request-local, including previews rendered by the platform API. Never reuse a nonce between responses.
        $nonce = base64_encode(random_bytes(32));
        $request->attributes->set('csp_nonce', $nonce);
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Preserve the stricter sandbox policies of attachments and media.
        if (! $response->headers->has('Content-Security-Policy')) {
            $pay = preg_match('~^/(en|de|ru)/pay$~D', $request->getPathInfo()) === 1;
            $policy = "default-src 'self'; script-src 'nonce-{$nonce}' 'strict-dynamic'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; form-action 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; frame-src 'self'";
            if ($pay) {
                $policy .= "; require-trusted-types-for 'script'; trusted-types default";
                $policy = str_replace("connect-src 'self'", "connect-src 'self' https://*.paddle.com https://*.paddle.io", $policy);
                $policy = str_replace("style-src 'self' 'unsafe-inline'", "style-src 'self' 'unsafe-inline' https://cdn.paddle.com/paddle/v2/assets/css/paddle.css https://sandbox-cdn.paddle.com/paddle/v2/assets/css/paddle.css", $policy);
                $policy = str_replace("frame-src 'self'", "frame-src 'self' https://*.paddle.com", $policy);
            } else {
                $policy .= "; require-trusted-types-for 'script'; trusted-types bw-store-result";
            }
            if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
                $response->headers->set('Cache-Control', 'private, no-store');
            }
            $response->headers->set(config('security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $policy);
        }

        return $response;
    }
}
