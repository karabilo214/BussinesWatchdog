<?php

namespace App\Support\Network;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Requests to a store's own public HTTPS origin for store connectors: the host is resolved once, every address must
 * be public, the connection is pinned to it and redirects are refused. A development endpoint (local/testing only)
 * reaches a test store through a local port with the store's host name.
 */
class StoreOriginHttp
{
    public const ERROR_NOT_ALLOWED = 'store_url_not_allowed';

    public const ERROR_UNREACHABLE = 'unreachable';

    public const ERROR_REDIRECTED = 'redirected';

    public function __construct(
        private readonly DnsClient $dns,
        private readonly PublicDestinationPolicy $policy,
    ) {}

    /**
     * @param  callable(PendingRequest, string): Response  $call
     *
     * @throws StoreOriginFailure
     */
    public function send(string $baseUrl, string $path, PendingRequest $request, callable $call, string $devEndpoint = ''): Response
    {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        if ($devEndpoint !== '' && app()->environment(['local', 'testing'])) {
            $url = rtrim($devEndpoint, '/').$path;
            $request = $request->withHeaders(['Host' => $host, 'X-Forwarded-Proto' => 'https'])->withOptions(['allow_redirects' => false]);
        } else {
            if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || ! $this->policy->isAllowedUrl($baseUrl)) {
                throw new StoreOriginFailure(self::ERROR_NOT_ALLOWED);
            }

            $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false ? [trim($host, '[]')] : $this->dns->resolveIps($host);

            if ($ips === []) {
                throw new StoreOriginFailure(self::ERROR_UNREACHABLE);
            }

            foreach ($ips as $ip) {
                if (! $this->policy->isPublicIp($ip)) {
                    throw new StoreOriginFailure(self::ERROR_NOT_ALLOWED);
                }
            }

            $pinned = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
            $url = rtrim($baseUrl, '/').$path;
            $request = $request->withOptions([
                'allow_redirects' => false,
                'protocols' => ['https'],
                'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$pinned]],
            ]);
        }

        try {
            $response = $call($request, $url);
        } catch (ConnectionException) {
            throw new StoreOriginFailure(self::ERROR_UNREACHABLE);
        }

        if ($response->redirect()) {
            throw new StoreOriginFailure(self::ERROR_REDIRECTED, $response->status());
        }

        return $response;
    }

    public static function base(int $timeoutSeconds): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['User-Agent' => 'BusinessWatchdog-Connector/1'])
            ->timeout($timeoutSeconds)
            ->connectTimeout(10);
    }
}
