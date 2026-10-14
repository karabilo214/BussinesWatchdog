<?php

namespace App\Support\Site;

use App\Models\BrowserWorker;
use App\Models\PublicStorefrontCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PublicStorefrontCheckService
{
    private const PRIVATE_V4 = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /**
     * @return array{check: PublicStorefrontCheck, token: string}
     */
    public function submit(string $locale, string $url, Request $request): array
    {
        $ipHash = $this->ipHash((string) $request->ip());
        $submitKey = 'public-storefront-submit|'.$ipHash;
        $bypassAttemptLimits = $this->bypassAttemptLimits($request);

        if (! $bypassAttemptLimits && RateLimiter::tooManyAttempts($submitKey, (int) config('watchdog.public_check.submit_attempts_per_hour'))) {
            throw new PublicStorefrontCheckRejected('submit_rate_limited', 429);
        }

        if (! $bypassAttemptLimits) {
            RateLimiter::hit($submitKey, 3600);
        }

        $normalized = $this->normalize($url);
        $now = Carbon::now();
        $hostCutoff = $now->copy()->subDays((int) config('watchdog.public_check.host_cooldown_days'));
        $ipCutoff = $now->copy()->subDays((int) config('watchdog.public_check.ip_cooldown_days'));

        if (! $bypassAttemptLimits && PublicStorefrontCheck::query()->where('normalized_origin', $normalized['origin'])->where('created_at', '>=', $hostCutoff)->exists()) {
            throw new PublicStorefrontCheckRejected('host_recently_checked', 409);
        }

        if (! $bypassAttemptLimits && PublicStorefrontCheck::query()->where('ip_hash', $ipHash)->where('created_at', '>=', $ipCutoff)->exists()) {
            throw new PublicStorefrontCheckRejected('ip_recently_checked', 429);
        }

        $token = bin2hex(random_bytes(32));

        $check = PublicStorefrontCheck::query()->create([
            'id' => (string) Str::uuid7(),
            'result_token_hash' => hash('sha256', $token),
            'locale' => $locale,
            'submitted_url' => $url,
            'normalized_origin' => $normalized['origin'],
            'normalized_host' => $normalized['host'],
            'ip_hash' => $ipHash,
            'status' => PublicStorefrontCheck::STATUS_QUEUED,
            'steps' => [],
            'diagnostics' => [],
            'queued_at' => $now,
            'expires_at' => $now->copy()->addMinutes((int) config('watchdog.public_check.artifact_ttl_minutes')),
        ]);

        return ['check' => $check, 'token' => $token];
    }

    public function byToken(string $token): ?PublicStorefrontCheck
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $check = PublicStorefrontCheck::query()
            ->where('result_token_hash', hash('sha256', $token))
            ->with(['artifacts' => fn ($query) => $query->where('state', 'ready')->orderBy('created_at')])
            ->first();

        if ($check !== null && $this->expireQueuedCheck($check)) {
            $check->load(['artifacts' => fn ($query) => $query->where('state', 'ready')->orderBy('created_at')]);
        }

        return $check;
    }

    public function purgeExpired(int $limit = 200): int
    {
        $ids = PublicStorefrontCheck::query()
            ->where('expires_at', '<=', Carbon::now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            app(PublicStorefrontLeaseService::class)->deleteCheck((string) $id);
        }

        return $ids->count();
    }

    /**
     * @return array{origin: string, host: string}
     */
    private function normalize(string $url): array
    {
        $url = trim($url);

        if ($url === '') {
            throw new PublicStorefrontCheckRejected('url_required');
        }

        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new PublicStorefrontCheckRejected('https_required');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($host === '' || $port !== null) {
            throw new PublicStorefrontCheckRejected('public_https_required');
        }

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        $hostForChecks = trim($host, '[]');

        if (in_array($hostForChecks, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($hostForChecks, '.local')
            || str_ends_with($hostForChecks, '.internal')
            || str_ends_with($hostForChecks, '.test')
            || str_ends_with($hostForChecks, '.invalid')) {
            throw new PublicStorefrontCheckRejected('unsafe_destination');
        }

        if (filter_var($hostForChecks, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($hostForChecks);
        } elseif ((bool) config('watchdog.public_check.verify_dns')) {
            $this->assertPublicDns($hostForChecks);
        }

        return ['origin' => 'https://'.$hostForChecks, 'host' => $hostForChecks];
    }

    private function assertPublicDns(string $host): void
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (! is_array($records) || $records === []) {
            throw new PublicStorefrontCheckRejected('dns_failure');
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $this->assertPublicIp($ip);
            }
        }
    }

    private function assertPublicIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new PublicStorefrontCheckRejected('unsafe_destination');
            }

            return;
        }

        $long = ip2long($ip);

        if ($long === false) {
            throw new PublicStorefrontCheckRejected('unsafe_destination');
        }

        $long = sprintf('%u', $long);

        foreach (self::PRIVATE_V4 as [$range, $bits]) {
            $mask = -1 << (32 - $bits);
            $rangeLong = sprintf('%u', ip2long($range));

            if (((int) $long & $mask) === ((int) $rangeLong & $mask)) {
                throw new PublicStorefrontCheckRejected('unsafe_destination');
            }
        }
    }

    private function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    private function bypassAttemptLimits(Request $request): bool
    {
        if (! (bool) config('watchdog.public_check.bypass_limits_on_localhost') || app()->environment('production')) {
            return false;
        }

        $host = strtolower(trim($request->getHost(), '[]'));
        $ip = (string) $request->ip();

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || in_array($ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
    }

    private function expireQueuedCheck(PublicStorefrontCheck $check): bool
    {
        if ($check->status !== PublicStorefrontCheck::STATUS_QUEUED || $check->queued_at === null) {
            return false;
        }

        if ($check->queued_at->greaterThan(Carbon::now()->subSeconds((int) config('watchdog.public_check.queue_wait_seconds')))) {
            return false;
        }

        $recentWorkerSeen = BrowserWorker::query()
            ->where('status', BrowserWorker::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->where('last_seen_at', '>=', Carbon::now()->subSeconds((int) config('watchdog.public_check.queue_wait_seconds')))
            ->exists();

        if ($recentWorkerSeen) {
            return false;
        }

        $check->forceFill([
            'status' => PublicStorefrontCheck::STATUS_INCONCLUSIVE,
            'verdict' => PublicStorefrontCheck::VERDICT_INSUFFICIENT_DATA,
            'error_code' => 'worker_unavailable',
            'finished_at' => Carbon::now(),
        ])->save();

        return true;
    }
}
