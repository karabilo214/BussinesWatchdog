<?php

namespace App\Support\Providers\Stripe;

use App\Models\Integration;
use App\Models\Membership;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Auth\TenantRoles;
use App\Support\Security\Keyring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Stripe Apps OAuth v2: one-use state, scoped token exchange, encrypted rotating tokens. */
class StripeAppOAuth
{
    public function __construct(
        private readonly StripeConnector $connector,
        private readonly Keyring $keyring,
    ) {}

    /** @return array{url: string} */
    public function start(Store $store, string $actorId, string $mode): array
    {
        if (! in_array($mode, ['test', 'live'], true)) {
            throw new AccountRejected('stripe_app_invalid_mode');
        }

        if (! $this->isConfigured($mode)) {
            throw new AccountRejected('stripe_app_unconfigured', 409);
        }
        $clientId = config("watchdog.stripe.app.client_id_{$mode}");
        $install = parse_url((string) config("watchdog.stripe.app.install_url_{$mode}"));
        $redirectUri = config('watchdog.stripe.app.redirect_uri');

        $existing = Integration::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)
            ->where('provider', 'stripe')->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED, Integration::STATUS_PENDING])->exists();
        if ($existing) {
            throw new AccountRejected('provider_already_connected', 409);
        }

        DB::table('stripe_app_oauth_attempts')->where('tenant_id', $store->tenant_id)
            ->where('expires_at', '<', Carbon::now()->subDay())->delete();
        $state = bin2hex(random_bytes(32));
        DB::table('stripe_app_oauth_attempts')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $store->tenant_id, 'store_id' => $store->id,
            'actor_user_id' => $actorId, 'state_hash' => hash('sha256', $state), 'mode' => $mode,
            'expires_at' => Carbon::now()->addMinutes(10), 'created_at' => Carbon::now(),
        ]);

        parse_str($install['query'] ?? '', $parameters);

        return ['url' => 'https://marketplace.stripe.com/oauth/v2/authorize?'.http_build_query([...$parameters,
            'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986)];
    }

    public function isConfigured(string $mode): bool
    {
        if (! in_array($mode, ['test', 'live'], true)) {
            return false;
        }
        $clientId = config("watchdog.stripe.app.client_id_{$mode}");
        $installUrl = config("watchdog.stripe.app.install_url_{$mode}");
        $redirectUri = config('watchdog.stripe.app.redirect_uri');
        $key = config("watchdog.stripe.app.secret_key_{$mode}");
        $install = is_string($installUrl) ? parse_url($installUrl) : false;

        return is_string($clientId) && str_starts_with($clientId, 'ca_') && is_string($key)
            && str_starts_with($key, "sk_{$mode}_") && is_string($redirectUri)
            && str_starts_with($redirectUri, 'https://') && is_array($install)
            && ($install['scheme'] ?? null) === 'https' && ($install['host'] ?? null) === 'marketplace.stripe.com'
            && ($install['path'] ?? null) === '/oauth/v2/authorize'
            && ! isset($install['user']) && ! isset($install['pass']) && ! isset($install['fragment']);
    }

    /** @return array{store_id: string, status: string, error_code?: string} */
    public function complete(string $state, ?string $code, ?string $error): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $state) !== 1) {
            throw new AccountRejected('stripe_app_invalid_state', 400);
        }

        $attempt = DB::transaction(function () use ($state): ?object {
            $row = DB::table('stripe_app_oauth_attempts')->where('state_hash', hash('sha256', $state))->lockForUpdate()->first();
            if ($row === null || $row->consumed_at !== null || Carbon::parse($row->expires_at)->isPast()) {
                return null;
            }
            DB::table('stripe_app_oauth_attempts')->where('id', $row->id)->update(['consumed_at' => Carbon::now()]);

            return $row;
        });
        if ($attempt === null) {
            throw new AccountRejected('stripe_app_invalid_state', 400);
        }
        if ($error !== null || $code === null || $code === '') {
            return ['store_id' => $attempt->store_id, 'status' => 'cancelled'];
        }

        try {
            $key = config("watchdog.stripe.app.secret_key_{$attempt->mode}");
            if (! is_string($key)) {
                throw new AccountRejected('stripe_app_unconfigured', 409);
            }
            $tokens = $this->exchange($key, ['grant_type' => 'authorization_code', 'code' => $code]);
            $this->validateTokens($tokens, $attempt->mode);

            $store = Store::query()->where('tenant_id', $attempt->tenant_id)->whereKey($attempt->store_id)->firstOrFail();
            if (! Membership::query()->where('tenant_id', $attempt->tenant_id)->where('user_id', $attempt->actor_user_id)
                ->whereIn('role', TenantRoles::integrationManage())->exists()) {
                throw new AccountRejected('stripe_app_access_revoked', 403);
            }
            DB::transaction(function () use ($store, $attempt, $tokens): void {
                $integration = $this->connector->connectApp($store, $tokens['access_token'], $tokens['stripe_user_id'], $attempt->mode, $attempt->actor_user_id);
                $encryptedAccess = $this->keyring->encrypt($tokens['access_token']);
                $encryptedRefresh = $this->keyring->encrypt($tokens['refresh_token']);
                $now = Carbon::now();
                DB::table('stripe_app_oauth_connections')->insert([
                    'integration_id' => $integration->id, 'tenant_id' => $store->tenant_id, 'store_id' => $store->id,
                    'access_ciphertext' => $encryptedAccess['ciphertext'], 'refresh_ciphertext' => $encryptedRefresh['ciphertext'],
                    'key_version' => $encryptedAccess['key_version'],
                    'access_expires_at' => $now->copy()->addSeconds(3600),
                    'refresh_expires_at' => $now->copy()->addYear(), 'created_at' => $now, 'updated_at' => $now,
                ]);
            });
        } catch (AccountRejected|StripeRequestFailed $failure) {
            return ['store_id' => $attempt->store_id, 'status' => 'failed', 'error_code' => $failure->reasonCode];
        }

        return ['store_id' => $attempt->store_id, 'status' => 'connected'];
    }

    public function accessToken(Integration $integration): string
    {
        return DB::transaction(function () use ($integration): string {
            $row = DB::table('stripe_app_oauth_connections')->where('tenant_id', $integration->tenant_id)
                ->where('store_id', $integration->store_id)->where('integration_id', $integration->id)->lockForUpdate()->first();
            if ($row === null) {
                throw new StripeRequestFailed('stripe_key_rejected', 401);
            }
            if (Carbon::parse($row->access_expires_at)->isAfter(Carbon::now()->addMinutes(5))) {
                return $this->keyring->decrypt($row->access_ciphertext, (int) $row->key_version);
            }
            if (Carbon::parse($row->refresh_expires_at)->isPast()) {
                throw new StripeRequestFailed('stripe_key_rejected', 401);
            }
            $key = config("watchdog.stripe.app.secret_key_{$integration->mode}");
            if (! is_string($key)) {
                throw new StripeRequestFailed('stripe_key_rejected', 401);
            }
            $refreshToken = $this->keyring->decrypt($row->refresh_ciphertext, (int) $row->key_version);
            $tokens = $this->exchange($key, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
            try {
                $this->validateTokens($tokens, $integration->mode, $integration->external_account_id);
            } catch (StripeRequestFailed) {
                throw new StripeRequestFailed('stripe_key_rejected', 401);
            }
            $encryptedAccess = $this->keyring->encrypt($tokens['access_token']);
            $encryptedRefresh = $this->keyring->encrypt($tokens['refresh_token']);
            $now = Carbon::now();
            DB::table('stripe_app_oauth_connections')->where('integration_id', $integration->id)->update([
                'access_ciphertext' => $encryptedAccess['ciphertext'], 'refresh_ciphertext' => $encryptedRefresh['ciphertext'],
                'key_version' => $encryptedAccess['key_version'], 'access_expires_at' => $now->copy()->addSeconds(3600),
                'refresh_expires_at' => $now->copy()->addYear(), 'updated_at' => $now,
            ]);

            return $tokens['access_token'];
        });
    }

    /** @param array<string, string> $form @return array<string, mixed> */
    private function exchange(string $key, array $form): array
    {
        try {
            $response = Http::asForm()->withBasicAuth($key, '')->timeout((int) config('watchdog.stripe.timeout_seconds'))
                ->post(rtrim((string) config('watchdog.stripe.api_base'), '/').'/v1/oauth/token', $form);
        } catch (ConnectionException) {
            throw new StripeRequestFailed('stripe_unreachable', 0);
        }
        if (! $response->successful()) {
            $rejected = in_array($response->status(), [400, 401, 403], true);
            $code = $rejected
                ? (($form['grant_type'] ?? null) === 'refresh_token' ? 'stripe_key_rejected' : 'stripe_app_invalid_grant')
                : 'stripe_unavailable';
            throw new StripeRequestFailed($code, $response->status());
        }

        return is_array($response->json()) ? $response->json() : throw new StripeRequestFailed('stripe_invalid_response', $response->status());
    }

    /** @param array<string, mixed> $tokens */
    private function validateTokens(array $tokens, string $mode, ?string $accountId = null): void
    {
        if (($tokens['scope'] ?? null) !== 'stripe_apps' || ($tokens['livemode'] ?? null) !== ($mode === 'live')
            || ! is_string($tokens['access_token'] ?? null) || ! is_string($tokens['refresh_token'] ?? null)
            || ! is_string($tokens['stripe_user_id'] ?? null)
            || preg_match('/^acct_[A-Za-z0-9]+$/', $tokens['stripe_user_id']) !== 1
            || ($accountId !== null && $tokens['stripe_user_id'] !== $accountId)) {
            throw new StripeRequestFailed('stripe_app_invalid_grant', 403);
        }
    }
}
