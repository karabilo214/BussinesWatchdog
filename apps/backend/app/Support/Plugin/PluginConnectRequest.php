<?php

namespace App\Support\Plugin;

use App\Support\Network\PublicDestinationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A one-click connection started by the WooCommerce plugin (ADR 0084), kept only in the server session. The return
 * address is accepted only on the plugin's own admin-post action of the same site.
 */
final class PluginConnectRequest
{
    public const SESSION_KEY = 'plugin_connect';

    public const TTL_MINUTES = 30;

    public const RETURN_ACTION = 'business_watchdog_connect_return';

    private function __construct(
        public readonly string $site,
        public readonly string $returnUrl,
        public readonly string $state,
        public readonly string $challenge,
        public readonly string $locale,
        public readonly ?string $journey,
        public readonly Carbon $expiresAt,
    ) {}

    public static function fromQuery(Request $request, PublicDestinationPolicy $policy): ?self
    {
        $site = rtrim(trim((string) $request->query('site')), '/');
        $return = trim((string) $request->query('return'));
        $state = (string) $request->query('state');
        $challenge = (string) $request->query('challenge');

        if (strlen($site) > 2048 || strlen($return) > 2048 || ! $policy->isAllowedUrl($site) || ! self::returnMatches($site, $return)) {
            return null;
        }

        if (preg_match('/^[0-9a-f]{64}$/', $state) !== 1 || preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
            return null;
        }

        return new self($site, $return, $state, $challenge, self::locale($request->query('lang')), null, Carbon::now()->addMinutes(self::TTL_MINUTES));
    }

    public static function locale(mixed $value): string
    {
        return is_string($value) && in_array($value, ['ru', 'en', 'de'], true) ? $value : 'en';
    }

    public static function fromSession(Request $request): ?self
    {
        $data = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! is_array($data) || ! isset($data['site'], $data['return'], $data['state'], $data['challenge'], $data['expires_at'])) {
            return null;
        }

        $expiresAt = Carbon::parse((string) $data['expires_at']);

        if ($expiresAt->isPast()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return new self((string) $data['site'], (string) $data['return'], (string) $data['state'], (string) $data['challenge'], self::locale($data['locale'] ?? null), is_string($data['journey'] ?? null) ? $data['journey'] : null, $expiresAt);
    }

    public function withJourney(string $journey): self
    {
        return new self($this->site, $this->returnUrl, $this->state, $this->challenge, $this->locale, $journey, $this->expiresAt);
    }

    public function remember(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'site' => $this->site,
            'return' => $this->returnUrl,
            'state' => $this->state,
            'challenge' => $this->challenge,
            'locale' => $this->locale,
            'journey' => $this->journey,
            'expires_at' => $this->expiresAt->toJSON(),
        ]);
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    public function siteHost(): string
    {
        return (string) parse_url($this->site, PHP_URL_HOST);
    }

    public function redirectWith(string $code): string
    {
        return $this->returnUrl.'&'.http_build_query(['state' => $this->state, 'code' => $code]);
    }

    private static function returnMatches(string $site, string $return): bool
    {
        $siteParts = parse_url($site);
        $parts = parse_url($return);

        if (! is_array($siteParts) || ! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        foreach (['scheme', 'host', 'port'] as $part) {
            if (strtolower((string) ($parts[$part] ?? '')) !== strtolower((string) ($siteParts[$part] ?? ''))) {
                return false;
            }
        }

        return str_ends_with((string) ($parts['path'] ?? ''), '/admin-post.php')
            && ($parts['query'] ?? null) === 'action='.self::RETURN_ACTION;
    }
}
