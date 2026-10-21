<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ShopwareAppInstallation;
use App\Support\Account\AccountRejected;
use App\Support\Providers\Shopware\ShopwareApp;
use App\Support\Providers\Shopware\ShopwareAppInstallations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The universal Shopware app (ADR 0076): download, Shopware's registration and webhooks, and the app's module where
 * the merchant enters the store's pairing code.
 */
class ShopwareAppInstallationController extends Controller
{
    public function __construct(private readonly ShopwareAppInstallations $installations) {}

    public function download(ShopwareApp $app): Response|JsonResponse
    {
        try {
            $package = $app->universalPackage();
        } catch (AccountRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
        }

        return response($package['archive'], 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$package['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-App-Version' => ShopwareApp::APP_VERSION,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        try {
            $result = $this->installations->register((string) $request->server('QUERY_STRING'), (string) $request->header('shopware-app-signature'), $request->query());
        } catch (AccountRejected $exception) {
            return response()->json(['error' => $exception->reasonCode], $exception->status);
        }

        return response()->json($result);
    }

    public function confirm(Request $request): JsonResponse
    {
        try {
            $this->installations->confirm($request->getContent(), (string) $request->header('shopware-shop-signature'));
        } catch (AccountRejected $exception) {
            return response()->json(['error' => $exception->reasonCode], $exception->status);
        }

        return response()->json(['confirmed' => true]);
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            $result = $this->installations->webhook($request->getContent(), (string) $request->header('shopware-shop-signature'));
        } catch (AccountRejected $exception) {
            return response()->json(['error' => $exception->reasonCode], $exception->status);
        }

        return response()->json(['received' => true, ...$result]);
    }

    public function module(Request $request): Response
    {
        return $this->page($request, null, null);
    }

    public function connect(Request $request): Response
    {
        $code = $request->input('code');
        $result = null;
        $error = null;

        try {
            $installation = $this->installations->moduleInstallation((string) $request->input('signed_query'), $this->parse((string) $request->input('signed_query')));
            $result = is_string($code) && trim($code) !== '' && strlen($code) <= 100 ? $this->installations->bind($installation, $code) : throw new AccountRejected('pairing_not_found', 404);
        } catch (AccountRejected $exception) {
            $error = $exception->reasonCode;
        }

        return $this->page($request, $error, $result === null ? null : 'connected', (string) $request->input('signed_query'));
    }

    private function page(Request $request, ?string $error, ?string $state, ?string $signedQuery = null): Response
    {
        $signedQuery ??= (string) $request->server('QUERY_STRING');
        $query = $this->parse($signedQuery);
        $locale = str_starts_with((string) ($query['sw-user-language'] ?? ''), 'de') ? 'de' : 'en';
        $installation = null;

        try {
            $installation = $this->installations->moduleInstallation($signedQuery, $query);
        } catch (AccountRejected $exception) {
            $error ??= $exception->reasonCode;
        }

        $origin = $installation === null ? "'none'" : $this->origin($installation->shop_url);
        $store = $installation?->status === ShopwareAppInstallation::STATUS_BOUND ? $installation->integration?->store : null;

        return response()->view('shopware-app.connect', [
            'locale' => $locale,
            'signedQuery' => $installation === null ? null : $signedQuery,
            'bound' => $store !== null,
            'storeName' => $store?->name,
            'error' => $error,
            'state' => $state,
        ], $installation === null ? 401 : 200)->withHeaders([
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; frame-ancestors {$origin}",
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $query): array
    {
        parse_str($query, $parsed);

        return array_filter($parsed, 'is_string');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')).$port;
    }
}
