<?php

namespace App\Support\Providers\Shopware;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\ReconciliationDirtySubject;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Billing\StoreCapacity;
use App\Support\Integrations\OrderSources;
use App\Support\Network\PublicDestinationPolicy;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use App\Support\Stores\StoreVerificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * The Shopware App path (ADR 0027): the dashboard hands out an app archive made for one store, whose manifest names
 * the read permissions (shown to the merchant at install), a registration URL for this integration and its own
 * secret. Shopware registers (signed with that secret), receives our shop secret, confirms with the app's API key
 * (signed with the shop secret) and sends signed webhooks for order changes and the app lifecycle. No key is copied
 * by hand; the permissions are exactly the manifest's.
 */
class ShopwareApp
{
    public const APP_NAME = 'BusinessWatchdog';

    public const APP_VERSION = '1.3.1';

    public const PERMISSIONS = ['order', 'order_transaction', 'order_transaction_capture', 'order_transaction_capture_refund', 'currency', 'payment_method', 'state_machine', 'state_machine_state'];

    /** Payment and order state changes reach apps only as state_enter business events (Shopware sends entity-written
     * webhooks for orders, not for their transactions). */
    public const TRANSACTION_STATES = ['open', 'in_progress', 'unconfirmed', 'authorized', 'paid', 'paid_partially', 'refunded', 'refunded_partially', 'cancelled', 'failed', 'reminded', 'chargeback'];

    public const ORDER_STATES = ['open', 'in_progress', 'completed', 'cancelled'];

    public const LIFECYCLE_EVENTS = ['orderPlaced' => 'checkout.order.placed', 'orderWritten' => 'order.written', 'appActivated' => 'app.activated', 'appDeactivated' => 'app.deactivated', 'appDeleted' => 'app.deleted', 'appUpdated' => 'app.updated'];

    /**
     * @return array<string, string>
     */
    public static function events(): array
    {
        $events = self::LIFECYCLE_EVENTS;

        foreach (self::TRANSACTION_STATES as $state) {
            $events['transaction'.str_replace(' ', '', ucwords(str_replace('_', ' ', $state)))] = 'state_enter.order_transaction.state.'.$state;
        }

        foreach (self::ORDER_STATES as $state) {
            $events['order'.str_replace(' ', '', ucwords(str_replace('_', ' ', $state)))] = 'state_enter.order.state.'.$state;
        }

        return $events;
    }

    private const MAX_CLOCK_SKEW_SECONDS = 600;

    public function __construct(
        private readonly ShopwareConnector $connector,
        private readonly ShopwareClient $client,
        private readonly ShopwareSync $sync,
        private readonly PublicDestinationPolicy $policy,
        private readonly StoreVerificationService $verifications,
        private readonly StoreReconciliationRequeue $requeue,
    ) {}

    /**
     * Creates (or replaces) the store's pending app integration and returns the archive to upload in Shopware.
     *
     * @return array{integration: Integration, archive: string, filename: string}
     */
    public function package(Store $store, bool $riskAcknowledged, ?string $actorId): array
    {
        $this->assertReleaseApproved($store);

        if (! $riskAcknowledged) {
            throw new AccountRejected('shopware_state_change_risk_not_acknowledged');
        }

        $manifestSecret = Str::random(48);

        $integration = DB::transaction(function () use ($store, $manifestSecret, $actorId): Integration {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();
            Integration::query()
                ->where('tenant_id', $store->tenant_id)
                ->where('store_id', $store->id)
                ->where('provider', ShopwareConnector::PROVIDER)
                ->where('status', Integration::STATUS_PENDING)
                ->get()
                ->each(fn (Integration $old) => $this->revoke($old, 'replaced_by_new_package'));

            if (OrderSources::active($store) !== null) {
                throw new AccountRejected(OrderSources::active($store) === ShopwareConnector::PROVIDER ? 'provider_already_connected' : 'order_source_already_connected', 409);
            }

            $integration = Integration::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'provider' => ShopwareConnector::PROVIDER,
                'mode' => 'live',
                'source_authority' => Integration::SOURCE_STORE_REPORTED,
                'status' => Integration::STATUS_PENDING,
                'capabilities' => ['orders' => 'read', 'refunds' => 'state_only', 'connection' => 'app', 'webhooks' => 'orders'],
                'api_version' => 'admin-api',
                'connector_version' => 'shopware-app-'.self::APP_VERSION,
                'health' => ['app_package_created_at' => Carbon::now()->toJSON(), 'state_change_risk_acknowledged_at' => Carbon::now()->toJSON()],
            ]);
            $this->connector->storeSecretJson($integration, IntegrationCredential::KIND_SHOPWARE_APP, ['manifest_secret' => $manifestSecret], $manifestSecret);
            $this->audit($integration, $actorId, AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED, ['provider' => ShopwareConnector::PROVIDER, 'connection' => 'app', 'stage' => 'package_created']);

            return $integration;
        });

        return ['integration' => $integration, 'archive' => $this->archive($integration, $manifestSecret), 'filename' => self::APP_NAME.'.zip'];
    }

    /**
     * Step 1 of Shopware's registration: verify the request was signed with this integration's manifest secret and
     * answer with the proof, a new shop secret and the confirmation URL.
     *
     * @return array{proof: string, secret: string, confirmation_url: string}
     */
    public function register(Integration $integration, string $rawQuery, string $signature, array $query): array
    {
        $app = $this->connector->secretJson($integration, IntegrationCredential::KIND_SHOPWARE_APP);
        $manifestSecret = (string) ($app['manifest_secret'] ?? '');
        $shopId = (string) ($query['shop-id'] ?? '');
        $shopUrl = rtrim((string) ($query['shop-url'] ?? ''), '/');
        $timestamp = (int) ($query['timestamp'] ?? 0);

        if ($integration->provider !== ShopwareConnector::PROVIDER || ! in_array($integration->status, [Integration::STATUS_PENDING, Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED], true)
            || $manifestSecret === '' || ! hash_equals(hash_hmac('sha256', $rawQuery, $manifestSecret), $signature)) {
            throw new AccountRejected('shopware_app_signature_invalid', 401);
        }

        if (abs(time() - $timestamp) > self::MAX_CLOCK_SKEW_SECONDS || preg_match('/^[A-Za-z0-9]{8,64}$/', $shopId) !== 1 || ! $this->shopUrlAllowed($shopUrl)) {
            throw new AccountRejected('shopware_app_registration_invalid', 422);
        }

        $shopSecret = Str::random(64);
        $this->connector->storeSecretJson($integration, IntegrationCredential::KIND_SHOPWARE_APP, [...$app, 'shop_secret' => $shopSecret, 'shop_id' => $shopId, 'shop_url' => $shopUrl], $manifestSecret);

        return [
            'proof' => hash_hmac('sha256', $shopId.$shopUrl.self::APP_NAME, $manifestSecret),
            'secret' => $shopSecret,
            'confirmation_url' => url('/api/v1/shopware/app/'.$integration->id.'/confirm'),
        ];
    }

    /**
     * Step 2: Shopware sends the app's own API key, signed with our shop secret. Shopware confirms before the app is
     * activated and an inactive app's key cannot log in, so the key is stored now and checked when the app is active
     * (here if it already is, otherwise on `app.activated`).
     */
    public function confirm(Integration $integration, string $rawBody, string $signature): Integration
    {
        $app = $this->connector->secretJson($integration, IntegrationCredential::KIND_SHOPWARE_APP);
        $this->verifyShopSignature($app, $rawBody, $signature);
        $body = json_decode($rawBody, true);

        if (! is_array($body) || ($body['shopId'] ?? null) !== ($app['shop_id'] ?? null) || ! is_string($body['apiKey'] ?? null) || ! is_string($body['secretKey'] ?? null)) {
            throw new AccountRejected('shopware_app_registration_invalid', 422);
        }

        $shopUrl = (string) $app['shop_url'];
        $this->connector->storeSecretJson($integration, IntegrationCredential::KIND_SHOPWARE_INTEGRATION, ['access_key' => $body['apiKey'], 'secret' => $body['secretKey'], 'api_url' => $shopUrl], $body['apiKey']);
        $integration->forceFill([
            'external_account_id' => $body['shopId'],
            'health' => [...($integration->health ?? []), 'key_last4' => substr($body['apiKey'], -4), 'app_registered_at' => Carbon::now()->toJSON(), 'shop_url' => $shopUrl],
        ])->save();
        $this->audit($integration, null, AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED, ['provider' => ShopwareConnector::PROVIDER, 'connection' => 'app', 'stage' => 'registered']);
        $this->activate($integration);

        return $integration->refresh();
    }

    /**
     * Checks the app key against the shop's Admin API and makes the app the store's order source. Returns the
     * resulting state; an app that is not active yet stays pending until `app.activated`.
     */
    public function activate(Integration $integration): string
    {
        $credentials = $this->connector->credentials($integration);

        if ($credentials === null || $integration->status === Integration::STATUS_REVOKED) {
            return 'ignored';
        }

        try {
            $token = $this->client->token((string) $credentials['api_url'], $credentials['access_key'], $credentials['secret'], fresh: true);
            $this->client->search((string) $credentials['api_url'], $token['token'], '/api/search/order', ['limit' => 1, 'includes' => ['order' => ['id']]]);
        } catch (ShopwareRequestFailed $failure) {
            $integration->forceFill(['health' => [...($integration->health ?? []), 'app_waiting_since' => Carbon::now()->toJSON(), 'app_check_error' => $failure->reasonCode]])->save();

            return 'waiting_for_activation';
        }

        $store = Store::query()->findOrFail($integration->store_id);
        $activated = DB::transaction(function () use ($integration, $store): bool {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();
            $other = Integration::query()
                ->where('store_id', $store->id)
                ->whereIn('provider', OrderSources::PROVIDERS)
                ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])
                ->whereKeyNot($integration->id)
                ->exists();

            if ($other) {
                return false;
            }

            $health = $integration->health ?? [];
            unset($health['key_rejected_at'], $health['app_deactivated_at'], $health['app_waiting_since'], $health['app_check_error']);
            $integration->forceFill(['status' => Integration::STATUS_ACTIVE, 'health' => [...$health, 'app_active_since' => Carbon::now()->toJSON()]])->save();

            return true;
        });

        if (! $activated) {
            return 'order_source_already_connected';
        }

        if (strtolower((string) parse_url((string) $credentials['api_url'], PHP_URL_HOST)) === strtolower((string) parse_url($store->base_url, PHP_URL_HOST))) {
            $this->verifications->confirmByApiKey($store->refresh());
        }

        $this->requeue->requeue($store->tenant_id, $store->id, ReconciliationDirtySubject::REASON_PROVIDER_COVERAGE_CHANGED);

        return Integration::STATUS_ACTIVE;
    }

    /**
     * A signed webhook: order changes are read right away (the delta stays the guarantee), lifecycle events move the
     * integration. The body is only a hint; the orders are read through the API.
     *
     * @return array{event: string, result: string}
     */
    public function webhook(Integration $integration, string $rawBody, string $signature): array
    {
        $app = $this->connector->secretJson($integration, IntegrationCredential::KIND_SHOPWARE_APP);
        $this->verifyShopSignature($app, $rawBody, $signature);
        $body = json_decode($rawBody, true);
        $event = is_array($body) ? (string) ($body['data']['event'] ?? '') : '';
        $timestamp = is_array($body) ? (int) ($body['timestamp'] ?? 0) : 0;

        if ($event === '' || ($body['source']['shopId'] ?? null) !== ($app['shop_id'] ?? null) || abs(time() - $timestamp) > 86400) {
            throw new AccountRejected('shopware_app_webhook_invalid', 422);
        }

        $payload = is_array($body['data']['payload'] ?? null) ? $body['data']['payload'] : [];
        $keys = fn (string $entity): array => array_values(array_filter(array_map(fn (mixed $row): mixed => is_array($row) && ($row['entity'] ?? null) === $entity ? ($row['primaryKey'] ?? null) : null, $payload), 'is_string'));

        $orderOfEvent = is_string($payload['order']['id'] ?? null) ? [$payload['order']['id']] : [];

        $paused = (in_array($event, ['order.written', 'checkout.order.placed'], true) || str_starts_with($event, 'state_enter.')) && StoreCapacity::paused($integration);

        $result = match (true) {
            $paused => StoreCapacity::ERROR_PAUSED,
            $event === 'order.written' => $this->sync->syncChanged($integration, $keys('order'), [])['status'],
            $event === 'checkout.order.placed', str_starts_with($event, 'state_enter.') => $this->sync->syncChanged($integration, $orderOfEvent, [])['status'],
            $event === 'app.deleted' => $this->revoke($integration, 'app_deleted'),
            $event === 'app.deactivated' => $this->setState($integration, Integration::STATUS_DEGRADED, ['app_deactivated_at' => Carbon::now()->toJSON()]),
            $event === 'app.activated' => $this->activate($integration),
            $event === 'app.updated' => 'updated',
            default => 'ignored',
        };

        $version = is_string($body['source']['appVersion'] ?? null) && preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $body['source']['appVersion']) === 1 ? $body['source']['appVersion'] : null;
        $integration->refresh()->forceFill([
            'health' => array_merge($integration->health ?? [], ['webhook' => ['last_received_at' => Carbon::now()->toJSON(), 'last_type' => $event]], $version === null ? [] : ['app_version' => $version]),
            ...($version === null ? [] : ['connector_version' => 'shopware-app-'.$version]),
        ])->save();

        return ['event' => $event, 'result' => $result];
    }

    public static function permissionsXml(): string
    {
        return implode("\n", array_map(fn (string $entity): string => '        <read>'.$entity.'</read>', self::PERMISSIONS));
    }

    /**
     * The current app version built for the store's connected app integration (ADR 0061): same integration, URLs and
     * manifest secret, so Shopware installs it as an update of the app it runs.
     *
     * @return array{integration: Integration, archive: string, filename: string}
     */
    public function updatePackage(Store $store, ?string $actorId): array
    {
        $this->assertReleaseApproved($store);

        /** @var Integration|null $integration */
        $integration = Integration::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('provider', ShopwareConnector::PROVIDER)
            ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED, Integration::STATUS_PENDING])
            ->latest('created_at')
            ->get()
            ->first(fn (Integration $candidate): bool => ($candidate->capabilities['connection'] ?? null) === 'app');
        $manifestSecret = $integration === null ? '' : (string) ($this->connector->secretJson($integration, IntegrationCredential::KIND_SHOPWARE_APP)['manifest_secret'] ?? '');

        if ($integration === null || $manifestSecret === '') {
            throw new AccountRejected('shopware_app_not_connected', 404);
        }

        $this->audit($integration, $actorId, AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED, ['provider' => ShopwareConnector::PROVIDER, 'connection' => 'app', 'stage' => 'update_package_created', 'version' => self::APP_VERSION]);

        return ['integration' => $integration, 'archive' => $this->archive($integration, $manifestSecret), 'filename' => self::APP_NAME.'.zip'];
    }

    private function archive(Integration $integration, string $manifestSecret, string $version = self::APP_VERSION, bool $withSources = true): string
    {
        $base = url('/api/v1/shopware/app/'.$integration->id);
        $events = self::events();
        $webhooks = implode("\n", array_map(fn (string $name, string $event): string => sprintf('        <webhook name="%s" url="%s" event="%s"/>', $name, htmlspecialchars($base.'/webhook', ENT_XML1), $event), array_keys($events), $events));
        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/Framework/App/Manifest/Schema/manifest-2.0.xsd">
    <meta>
        <name>%s</name>
        <label>Business Watchdog</label>
        <description>Reads orders and payment states to find payment discrepancies. Read-only; never changes orders.</description>
        <description lang="de-DE">Liest Bestellungen und Zahlungsstatus, um Zahlungsabweichungen zu finden. Nur lesend; ändert keine Bestellungen.</description>
        <author>Business Watchdog</author>
        <copyright>(c) Business Watchdog</copyright>
        <version>%s</version>
        <license>proprietary</license>
    </meta>
    <setup>
        <registrationUrl>%s</registrationUrl>
        <secret>%s</secret>
    </setup>
    <permissions>
%s
    </permissions>
    <webhooks>
%s
    </webhooks>
</manifest>

XML;
        $xml = sprintf($manifest, self::APP_NAME, $version, htmlspecialchars($base.'/register', ENT_XML1), $manifestSecret, self::permissionsXml(), $webhooks);
        $path = tempnam(sys_get_temp_dir(), 'bw-shopware-app');

        if ($path === false) {
            throw new \RuntimeException('shopware_app_archive_temp_failed');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('shopware_app_archive_open_failed');
            }

            try {
                if (! $zip->addFromString(self::APP_NAME.'/manifest.xml', $xml)) {
                    throw new \RuntimeException('shopware_app_manifest_write_failed');
                }

                foreach ($withSources ? $this->sourceFiles() : [] as $relative => $contents) {
                    if (! $zip->addFromString(self::APP_NAME.'/'.$relative, $contents)) {
                        throw new \RuntimeException('shopware_app_source_write_failed');
                    }
                }
            } finally {
                if (! $zip->close()) {
                    throw new \RuntimeException('shopware_app_archive_close_failed');
                }
            }

            $bytes = file_get_contents($path);

            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('shopware_app_archive_read_failed');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    /**
     * The app's files (`resources/shopware-app`, linked as `plugins/shopware-app/src`): the checkout observation
     * script, its separate storefront endpoint and its entities. No app code runs during checkout page rendering.
     *
     * @return array<string, string>
     */
    public function sourceFiles(): array
    {
        $root = resource_path('shopware-app');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $contents = file_get_contents($file->getPathname());

                if ($contents === false) {
                    throw new \RuntimeException('shopware_app_source_read_failed');
                }

                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $contents;
            }
        }

        ksort($files);

        return $files;
    }

    public function releaseFingerprint(): string
    {
        return hash('sha256', json_encode([
            'version' => self::APP_VERSION,
            'package_builder_sha256' => hash_file('sha256', __FILE__),
            'permissions' => self::PERMISSIONS,
            'events' => self::events(),
            'sources' => $this->sourceFiles(),
        ], JSON_THROW_ON_ERROR));
    }

    public function releaseApproved(?string $storeId = null): bool
    {
        if (! (bool) config('watchdog.connector_release_gate')) {
            return true;
        }

        $approved = strtolower((string) config('watchdog.shopware.app_approved_sha256'));

        if (preg_match('/^[0-9a-f]{64}$/', $approved) !== 1 || ! hash_equals($approved, $this->releaseFingerprint())) {
            return false;
        }

        $storeIds = array_filter(array_map('trim', explode(',', (string) config('watchdog.shopware.app_approved_store_ids'))));

        return $storeId !== null && in_array($storeId, $storeIds, true);
    }

    private function assertReleaseApproved(Store $store): void
    {
        if (! $this->releaseApproved((string) $store->id)) {
            throw new AccountRejected('shopware_app_release_not_approved', 409);
        }
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function verifyShopSignature(array $app, string $rawBody, string $signature): void
    {
        $secret = (string) ($app['shop_secret'] ?? '');

        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
            throw new AccountRejected('shopware_app_signature_invalid', 401);
        }
    }

    private function shopUrlAllowed(string $shopUrl): bool
    {
        if ((string) config('watchdog.shopware.dev_endpoint') !== '' && app()->environment(['local', 'testing'])) {
            return in_array(parse_url($shopUrl, PHP_URL_SCHEME), ['http', 'https'], true);
        }

        return parse_url($shopUrl, PHP_URL_SCHEME) === 'https' && $this->policy->isAllowedUrl($shopUrl);
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function setState(Integration $integration, string $status, array $health): string
    {
        if ($integration->status === Integration::STATUS_REVOKED) {
            return 'ignored';
        }

        $integration->forceFill(['status' => $status, 'health' => array_filter([...($integration->health ?? []), ...$health], fn (mixed $value): bool => $value !== null)])->save();

        return $status;
    }

    private function revoke(Integration $integration, string $reason): string
    {
        $integration->forceFill(['status' => Integration::STATUS_REVOKED, 'health' => [...($integration->health ?? []), 'revoked_reason' => $reason, 'revoked_at' => Carbon::now()->toJSON()]])->save();
        IntegrationCredential::query()->where('integration_id', $integration->id)->update(['status' => IntegrationCredential::STATUS_REVOKED]);
        $this->requeue->requeue($integration->tenant_id, $integration->store_id, ReconciliationDirtySubject::REASON_PROVIDER_COVERAGE_CHANGED);

        return 'revoked';
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(Integration $integration, ?string $actorId, string $action, array $changes): void
    {
        AuditLog::query()->create([
            'tenant_id' => $integration->tenant_id,
            'store_id' => $integration->store_id,
            'actor_user_id' => $actorId,
            'actor_type' => $actorId === null ? 'system' : AuditLog::ACTOR_USER,
            'action' => $action,
            'entity_type' => AuditLog::ENTITY_INTEGRATION,
            'entity_id' => $integration->id,
            'changes' => $changes,
            'request_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);
    }
}
