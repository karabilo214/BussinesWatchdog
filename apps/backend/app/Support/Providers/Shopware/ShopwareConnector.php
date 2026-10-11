<?php

namespace App\Support\Providers\Shopware;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\ReconciliationDirtySubject;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Integrations\OrderSources;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use App\Support\Security\Keyring;
use App\Support\Stores\StoreVerificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connects a Shopware 6 store through an Integration key (ADR 0025/0026). Shopware lets even a read-only role change
 * order, payment and delivery states, so the owner must acknowledge that before anything is sent; the service itself
 * only searches orders. A working key on the store's own origin confirms the store, as for WooCommerce.
 */
class ShopwareConnector
{
    public const PROVIDER = 'shopware';

    public const ADAPTER_VERSION = 'shopware-integration-1';

    public function __construct(
        private readonly ShopwareClient $client,
        private readonly Keyring $keyring,
        private readonly StoreReconciliationRequeue $requeue,
        private readonly StoreVerificationService $verifications,
    ) {}

    public function connect(Store $store, string $accessKey, string $secret, bool $riskAcknowledged, ?string $actorId): Integration
    {
        if (preg_match('/^SWIA[A-Za-z0-9]{10,60}$/', $accessKey) !== 1 || preg_match('/^[A-Za-z0-9]{20,128}$/', $secret) !== 1) {
            throw new AccountRejected('shopware_key_invalid');
        }

        if (! $riskAcknowledged) {
            throw new AccountRejected('shopware_state_change_risk_not_acknowledged');
        }

        $source = OrderSources::active($store);

        if ($source !== null) {
            throw new AccountRejected($source === self::PROVIDER ? 'provider_already_connected' : 'order_source_already_connected', 409);
        }

        try {
            $token = $this->client->token($store->base_url, $accessKey, $secret, fresh: true);
            $this->client->search($store->base_url, $token['token'], '/api/search/order', ['limit' => 1, 'includes' => ['order' => ['id']]]);
        } catch (ShopwareRequestFailed $failure) {
            throw new AccountRejected($failure->reasonCode);
        }

        $integration = DB::transaction(function () use ($store, $accessKey, $secret, $actorId): Integration {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            if (OrderSources::active($store) !== null) {
                throw new AccountRejected('order_source_already_connected', 409);
            }

            $integration = Integration::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'provider' => self::PROVIDER,
                'mode' => 'live',
                'source_authority' => Integration::SOURCE_STORE_REPORTED,
                'status' => Integration::STATUS_ACTIVE,
                'capabilities' => ['orders' => 'read', 'refunds' => 'state_only'],
                'api_version' => 'admin-api',
                'connector_version' => self::ADAPTER_VERSION,
                'health' => ['key_last4' => substr($accessKey, -4), 'state_change_risk_acknowledged_at' => Carbon::now()->toJSON()],
            ]);

            $encrypted = $this->keyring->encrypt(json_encode(['access_key' => $accessKey, 'secret' => $secret], JSON_THROW_ON_ERROR));
            IntegrationCredential::query()->create([
                'tenant_id' => $integration->tenant_id,
                'store_id' => $integration->store_id,
                'integration_id' => $integration->id,
                'kind' => IntegrationCredential::KIND_SHOPWARE_INTEGRATION,
                'key_id' => IntegrationCredential::KIND_SHOPWARE_INTEGRATION.':'.Str::uuid(),
                'ciphertext' => $encrypted['ciphertext'],
                'key_version' => $encrypted['key_version'],
                'fingerprint' => hash('sha256', $accessKey),
                'status' => IntegrationCredential::STATUS_ACTIVE,
                'created_at' => Carbon::now(),
            ]);

            AuditLog::query()->create([
                'tenant_id' => $integration->tenant_id,
                'store_id' => $integration->store_id,
                'actor_user_id' => $actorId,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED,
                'entity_type' => AuditLog::ENTITY_INTEGRATION,
                'entity_id' => $integration->id,
                'changes' => ['provider' => self::PROVIDER, 'state_change_risk_acknowledged' => true],
                'request_id' => (string) Str::uuid(),
                'created_at' => Carbon::now(),
            ]);

            return $integration;
        });

        $this->verifications->confirmByApiKey($store->refresh());
        $this->requeue->requeue($store->tenant_id, $store->id, ReconciliationDirtySubject::REASON_PROVIDER_COVERAGE_CHANGED);

        return $integration->refresh();
    }

    /**
     * The Admin API key and the origin to call: the store's URL for a key entered by hand, the shop URL Shopware
     * reported at app registration for the app.
     *
     * @return array{access_key: string, secret: string, api_url: ?string}|null
     */
    public function credentials(Integration $integration): ?array
    {
        $decoded = $this->secretJson($integration, IntegrationCredential::KIND_SHOPWARE_INTEGRATION);

        return is_string($decoded['access_key'] ?? null) && is_string($decoded['secret'] ?? null)
            ? ['access_key' => $decoded['access_key'], 'secret' => $decoded['secret'], 'api_url' => is_string($decoded['api_url'] ?? null) ? $decoded['api_url'] : null]
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function secretJson(Integration $integration, string $kind): array
    {
        /** @var IntegrationCredential|null $credential */
        $credential = IntegrationCredential::query()
            ->where('integration_id', $integration->id)
            ->where('kind', $kind)
            ->where('status', IntegrationCredential::STATUS_ACTIVE)
            ->latest('created_at')
            ->first();
        $decoded = $credential === null ? null : json_decode($this->keyring->decrypt($credential->ciphertext, $credential->key_version), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Replaces the integration's active secret of this kind.
     *
     * @param  array<string, mixed>  $secret
     */
    public function storeSecretJson(Integration $integration, string $kind, array $secret, string $fingerprintSource): void
    {
        IntegrationCredential::query()
            ->where('integration_id', $integration->id)
            ->where('kind', $kind)
            ->where('status', IntegrationCredential::STATUS_ACTIVE)
            ->update(['status' => IntegrationCredential::STATUS_REVOKED, 'rotated_at' => Carbon::now()]);
        $encrypted = $this->keyring->encrypt(json_encode($secret, JSON_THROW_ON_ERROR));
        IntegrationCredential::query()->create([
            'tenant_id' => $integration->tenant_id,
            'store_id' => $integration->store_id,
            'integration_id' => $integration->id,
            'kind' => $kind,
            'key_id' => $kind.':'.Str::uuid(),
            'ciphertext' => $encrypted['ciphertext'],
            'key_version' => $encrypted['key_version'],
            'fingerprint' => hash('sha256', $fingerprintSource),
            'status' => IntegrationCredential::STATUS_ACTIVE,
            'created_at' => Carbon::now(),
        ]);
    }
}
