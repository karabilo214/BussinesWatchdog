<?php

namespace App\Support\Network;

class PublicDestinationPolicy
{
    private const BLOCKED_HOST_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.intranet'];

    private const BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    public function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        return $this->isAllowedHost($parts['host']);
    }

    public function isAllowedHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === '' || $host === 'localhost' || (! str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false)) {
            return false;
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        return true;
    }

    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $lower = strtolower($ip);

            if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $lower, $matches) === 1) {
                return $this->isPublicIp($matches[1]);
            }

            if ($lower === '::' || $lower === '::1' || preg_match('/^(fc|fd|fe8|fe9|fea|feb|ff)/', $lower) === 1) {
                return false;
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
            if ($this->ipv4InCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return (ip2long($ip) & $mask) === (ip2long($network) & $mask);
    }
}
