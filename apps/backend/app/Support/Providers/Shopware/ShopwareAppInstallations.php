<?php

namespace App\Support\Providers\Shopware;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\PairingCode;
use App\Models\ShopwareAppInstallation;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Network\PublicDestinationPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shops that installed the universal app (ADR 0076). Shopware registers with the app secret, confirms with the app's
 * key and sends lifecycle webhooks; nothing is read until the merchant enters the store's pairing code in the app's
 * module and the shop's domain matches the store. Webhooks of a bound shop go to its integration.
 */
class ShopwareAppInstallations
{
    private const MAX_CLOCK_SKEW_SECONDS = 600;

    private const MODULE_MAX_AGE_SECONDS = 3600;

    public function __construct(
        private readonly ShopwareApp $app,
        private readonly PublicDestinationPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array{proof: string, secret: string, confirmation_url: string}
     */
    public function register(string $rawQuery, string $signature, array $query): array
    {
        $secret = ShopwareApp::appSecret();
        $shopId = (string) ($query['shop-id'] ?? '');
        $shopUrl = rtrim((string) ($query['shop-url'] ?? ''), '/');
        $timestamp = (int) ($query['timestamp'] ?? 0);

        if (! hash_equals(hash_hmac('sha256', $rawQuery, $secret), $signature)) {
            throw new AccountRejected('shopware_app_signature_invalid', 401);
        }

        if (abs(time() - $timestamp) > self::MAX_CLOCK_SKEW_SECONDS || preg_match('/^[A-Za-z0-9]{8,64}$/', $shopId) !== 1 || ! $this->shopUrlAllowed($shopUrl)) {
            throw new AccountRejected('shopware_app_registration_invalid', 422);
        }

        $shopSecret = Str::random(64);
        $installation = ShopwareAppInstallation::query()->where('shop_id', $shopId)->first();

        if ($installation?->status === ShopwareAppInstallation::STATUS_BOUND && $this->host($installation->shop_url) !== $this->host($shopUrl)) {
            throw new AccountRejected('shopware_app_shop_moved', 409);
        }

        $secrets = ['shop_secret' => $shopSecret];

        if ($installation === null) {
            ShopwareAppInstallation::query()->create(['shop_id' => $shopId, 'shop_url' => $shopUrl, 'secrets' => $secrets, 'status' => ShopwareAppInstallation::STATUS_REGISTERED, 'registered_at' => Carbon::now()]);
        } else {
            $installation->forceFill([
                'shop_url' => $shopUrl,
                'secrets' => $secrets,
                'status' => $installation->status === ShopwareAppInstallation::STATUS_BOUND ? ShopwareAppInstallation::STATUS_BOUND : ShopwareAppInstallation::STATUS_REGISTERED,
                'registered_at' => Carbon::now(),
            ])->save();
        }

        return [
            'proof' => hash_hmac('sha256', $shopId.$shopUrl.ShopwareApp::APP_NAME, $secret),
            'secret' => $shopSecret,
            'confirmation_url' => url('/api/v1/shopware/app/confirm'),
        ];
    }

    public function confirm(string $rawBody, string $signature): ShopwareAppInstallation
    {
        $body = json_decode($rawBody, true);
        $installation = $this->signed(is_array($body) ? ($body['shopId'] ?? null) : null, $rawBody, $signature);

        if (! is_string($body['apiKey'] ?? null) || ! is_string($body['secretKey'] ?? null)) {
            throw new AccountRejected('shopware_app_registration_invalid', 422);
        }

        $installation->forceFill([
            'secrets' => [...$installation->secrets, 'api_key' => $body['apiKey'], 'secret_key' => $body['secretKey']],
            'status' => $installation->status === ShopwareAppInstallation::STATUS_BOUND ? ShopwareAppInstallation::STATUS_BOUND : ShopwareAppInstallation::STATUS_CONFIRMED,
            'confirmed_at' => Carbon::now(),
        ])->save();

        if ($installation->status === ShopwareAppInstallation::STATUS_BOUND && $installation->integration !== null) {
            $this->app->storeInstallationSecrets($installation->integration, $this->secrets($installation));
            $this->app->activate($installation->integration);
        }

        return $installation;
    }

    /**
     * @return array{event: string, result: string}
     */
    public function webhook(string $rawBody, string $signature): array
    {
        $body = json_decode($rawBody, true);
        $installation = $this->signed(is_array($body) ? ($body['source']['shopId'] ?? null) : null, $rawBody, $signature);
        $event = (string) ($body['data']['event'] ?? '');
        $version = is_string($body['source']['appVersion'] ?? null) && preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $body['source']['appVersion']) === 1 ? $body['source']['appVersion'] : null;

        if ($installation->status === ShopwareAppInstallation::STATUS_BOUND && $installation->integration !== null) {
            $result = $this->app->webhook($installation->integration, $rawBody, $signature);

            if ($event === 'app.deleted') {
                $installation->delete();
            } elseif ($version !== null) {
                $installation->forceFill(['app_version' => $version])->save();
            }

            return $result;
        }

        if ($event === 'app.deleted') {
            $installation->delete();

            return ['event' => $event, 'result' => 'deleted'];
        }

        if ($version !== null) {
            $installation->forceFill(['app_version' => $version])->save();
        }

        return ['event' => $event, 'result' => 'not_bound'];
    }

    /**
     * The app's module opened in the Shopware Administration: Shopware signs the query with the shop secret.
     *
     * @param  array<string, mixed>  $query
     */
    public function moduleInstallation(string $rawQuery, array $query): ShopwareAppInstallation
    {
        $signature = (string) ($query['shopware-shop-signature'] ?? '');
        $unsigned = preg_replace('/(^|&)shopware-shop-signature=[^&]*/', '', $rawQuery);
        $unsigned = ltrim((string) $unsigned, '&');
        $installation = ShopwareAppInstallation::query()->where('shop_id', (string) ($query['shop-id'] ?? ''))->first();
        $secret = (string) ($installation?->secrets['shop_secret'] ?? '');

        if ($installation === null || $secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $unsigned, $secret), $signature)) {
            throw new AccountRejected('shopware_app_signature_invalid', 401);
        }

        if (abs(time() - (int) ($query['timestamp'] ?? 0)) > self::MODULE_MAX_AGE_SECONDS) {
            throw new AccountRejected('shopware_app_module_expired', 401);
        }

        return $installation;
    }

    /**
     * Binds the installation to the store of the pairing code when the shop's domain is the store's (ADR 0076).
     */
    public function bind(ShopwareAppInstallation $installation, string $code): Integration
    {
        if ($installation->status === ShopwareAppInstallation::STATUS_BOUND) {
            throw new AccountRejected('shopware_app_already_bound', 409);
        }

        if ($installation->status !== ShopwareAppInstallation::STATUS_CONFIRMED) {
            throw new AccountRejected('shopware_app_not_confirmed', 409);
        }

        [$pairingCode, $store] = DB::transaction(function () use ($code): array {
            /** @var PairingCode|null $pairingCode */
            $pairingCode = PairingCode::query()->where('code_hash', hash('sha256', trim($code)))->lockForUpdate()->first();

            if ($pairingCode === null) {
                throw new AccountRejected('pairing_not_found', 404);
            }

            if ($pairingCode->consumed_at !== null) {
                throw new AccountRejected('pairing_consumed', 409);
            }

            if ($pairingCode->expires_at->isPast()) {
                throw new AccountRejected('pairing_expired', 410);
            }

            if ($pairingCode->attempt_count >= PairingCode::MAX_ATTEMPTS) {
                throw new AccountRejected('pairing_attempts_exceeded', 429);
            }

            $pairingCode->increment('attempt_count');
            $store = Store::query()->whereKey($pairingCode->store_id)->where('tenant_id', $pairingCode->tenant_id)->firstOrFail();

            return [$pairingCode, $store];
        });

        if ($store->platform !== 'shopware') {
            throw new AccountRejected('pairing_platform_mismatch', 422);
        }

        if ($this->host($installation->shop_url) !== $this->host($store->base_url)) {
            throw new AccountRejected('pairing_base_url_mismatch', 422);
        }

        $integration = $this->app->bindInstallation($store, $this->secrets($installation), $installation->app_version, $pairingCode->created_by);

        if ($integration->status !== Integration::STATUS_ACTIVE) {
            $this->app->abandon($integration, 'shop_not_reachable_with_app_key');

            throw new AccountRejected('shopware_app_shop_not_reachable', 422);
        }

        DB::transaction(function () use ($pairingCode, $installation, $integration): void {
            $pairingCode->forceFill(['consumed_at' => Carbon::now()])->save();
            $installation->forceFill(['status' => ShopwareAppInstallation::STATUS_BOUND, 'integration_id' => $integration->id, 'bound_at' => Carbon::now()])->save();
            AuditLog::query()->create([
                'tenant_id' => $integration->tenant_id,
                'store_id' => $integration->store_id,
                'actor_user_id' => $pairingCode->created_by,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED,
                'entity_type' => AuditLog::ENTITY_INTEGRATION,
                'entity_id' => $integration->id,
                'changes' => ['provider' => ShopwareConnector::PROVIDER, 'connection' => 'app', 'stage' => 'pairing_code_consumed', 'pairing_code_id' => $pairingCode->id],
                'request_id' => (string) Str::uuid(),
                'created_at' => Carbon::now(),
            ]);
        });

        return $integration->refresh();
    }

    public function pruneUnbound(): int
    {
        return ShopwareAppInstallation::query()
            ->where('status', '!=', ShopwareAppInstallation::STATUS_BOUND)
            ->where('registered_at', '<', Carbon::now()->subDays(ShopwareAppInstallation::UNBOUND_RETENTION_DAYS))
            ->delete();
    }

    private function signed(mixed $shopId, string $rawBody, string $signature): ShopwareAppInstallation
    {
        $installation = is_string($shopId) ? ShopwareAppInstallation::query()->where('shop_id', $shopId)->first() : null;
        $secret = (string) ($installation?->secrets['shop_secret'] ?? '');

        if ($installation === null || $secret === '' || ! hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
            throw new AccountRejected('shopware_app_signature_invalid', 401);
        }

        return $installation;
    }

    /**
     * @return array{shop_id: string, shop_url: string, shop_secret: string, api_key: string, secret_key: string}
     */
    private function secrets(ShopwareAppInstallation $installation): array
    {
        $secrets = $installation->secrets;

        return ['shop_id' => $installation->shop_id, 'shop_url' => $installation->shop_url, 'shop_secret' => (string) ($secrets['shop_secret'] ?? ''), 'api_key' => (string) ($secrets['api_key'] ?? ''), 'secret_key' => (string) ($secrets['secret_key'] ?? '')];
    }

    private function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }

    private function shopUrlAllowed(string $shopUrl): bool
    {
        if ((string) config('watchdog.shopware.dev_endpoint') !== '' && app()->environment(['local', 'testing'])) {
            return in_array(parse_url($shopUrl, PHP_URL_SCHEME), ['http', 'https'], true);
        }

        return parse_url($shopUrl, PHP_URL_SCHEME) === 'https' && $this->policy->isAllowedUrl($shopUrl);
    }
}
