<?php

namespace App\Support\Network;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SafeHttpFetcher
{
    public const ERROR_UNSAFE_DESTINATION = 'unsafe_destination';

    public const ERROR_DNS_FAILURE = 'dns_failure';

    public const ERROR_CONNECTION = 'connection_failed';

    public const ERROR_REDIRECT = 'redirect_not_allowed';

    public const ERROR_HTTP_STATUS = 'http_status_unexpected';

    public const ERROR_BODY_TOO_LARGE = 'response_too_large';

    public const TIMEOUT_SECONDS = 5;

    public const MAX_BODY_BYTES = 4096;

    public function __construct(
        private readonly DnsClient $dns,
        private readonly PublicDestinationPolicy $policy,
    ) {}

    public function getSmallText(string $url): SafeFetchResult
    {
        if (! $this->policy->isAllowedUrl($url)) {
            return SafeFetchResult::failed(self::ERROR_UNSAFE_DESTINATION);
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false ? [trim($host, '[]')] : $this->dns->resolveIps($host);

        if ($ips === []) {
            return SafeFetchResult::failed(self::ERROR_DNS_FAILURE);
        }

        foreach ($ips as $ip) {
            if (! $this->policy->isPublicIp($ip)) {
                return SafeFetchResult::failed(self::ERROR_UNSAFE_DESTINATION);
            }
        }

        $pinned = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'protocols' => ['https'],
                'curl' => [
                    CURLOPT_RESOLVE => [$host.':443:'.$pinned],
                ],
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Accept' => 'text/plain', 'User-Agent' => 'BusinessWatchdog-Verifier/1'])
                ->get($url);
        } catch (ConnectionException) {
            return SafeFetchResult::failed(self::ERROR_CONNECTION);
        }

        if ($response->redirect()) {
            return SafeFetchResult::failed(self::ERROR_REDIRECT);
        }

        if ($response->status() !== 200) {
            return SafeFetchResult::failed(self::ERROR_HTTP_STATUS);
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return SafeFetchResult::failed(self::ERROR_BODY_TOO_LARGE);
        }

        return SafeFetchResult::ok($body);
    }
}
