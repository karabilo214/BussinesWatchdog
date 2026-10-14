<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Http\Controllers\Controller;
use App\Support\Checkout\CheckoutObservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public endpoints of the checkout observation script and render beacon (ADR 0057), called from shoppers' browsers on
 * the store's own pages. Every answer is the same whether the store is observed or not, except `observe` in /config;
 * nothing about the visitor is stored.
 */
class CheckoutObservationController extends Controller
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private readonly CheckoutObservation $observation,
    ) {}

    public function config(Request $request, string $integration): JsonResponse
    {
        $decision = $this->observation->decision($integration);
        $allowed = $decision['integration'] !== null && $this->observation->originAllowed($decision['integration'], $request->headers->get('Origin'), $request->headers->get('Referer'));

        return response()->json(['observe' => $decision['observe'] && $allowed])->header('Cache-Control', 'no-store');
    }

    public function render(Request $request, string $integration): Response
    {
        $decision = $this->observation->decision($integration);

        if ($decision['integration'] !== null && $this->observation->originAllowed($decision['integration'], $request->headers->get('Origin'), $request->headers->get('Referer'))) {
            $this->observation->render($decision['integration'], (string) $request->query('page'), $request->query('js') !== '0');
        }

        return response((string) base64_decode(self::GIF), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }

    public function stages(Request $request, string $integration): Response
    {
        $decision = $this->observation->decision($integration);
        $body = json_decode(substr((string) $request->getContent(), 0, 2000), true);

        if ($decision['integration'] !== null && is_array($body)
            && $this->observation->originAllowed($decision['integration'], $request->headers->get('Origin'), $request->headers->get('Referer'))) {
            $this->observation->stages($decision['integration'], (string) ($body['page'] ?? ''), is_array($body['stages'] ?? null) ? array_values($body['stages']) : [], is_int($body['js_errors'] ?? null) ? max(0, $body['js_errors']) : 0);
        }

        return response()->noContent();
    }
}
