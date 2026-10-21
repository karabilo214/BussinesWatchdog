<?php

namespace App\Support\Providers\WooCommerce;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\ReconciliationDirtySubject;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Integrations\OrderSourceContinuity;
use App\Support\Integrations\OrderSources;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use App\Support\Security\Keyring;
use App\Support\Stores\StoreVerificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connects a store through a WooCommerce REST API key with Read permission (ADR 0024). The key must read orders on
 * the store's own HTTPS origin and must not be able to write (checked without writing); such a key also confirms
 * the store. A store has one order source: this connection or the plugin, not both.
 */
class WooCommerceApiConnector
{
    public const PROVIDER = 'woocommerce_api';

    public const PLUGIN_PROVIDER = 'woocommerce';

    public const ADAPTER_VERSION = 'woocommerce-api-1';

    public function __construct(
        private readonly WooCommerceApiClient $client,
        private readonly Keyring $keyring,
        private readonly StoreReconciliationRequeue $requeue,
        private readonly StoreVerificationService $verifications,
    ) {}

    public function connect(Store $store, string $consumerKey, string $consumerSecret, ?string $actorId): Integration
    {
        if (preg_match('/^ck_[a-f0-9]{40}$/', $consumerKey) !== 1 || preg_match('/^cs_[a-f0-9]{40}$/', $consumerSecret) !== 1) {
            throw new AccountRejected('woocommerce_key_invalid');
        }

        if (self::activeOrderSource($store) !== null) {
            throw new AccountRejected(self::activeOrderSource($store) === self::PROVIDER ? 'provider_already_connected' : 'order_source_already_connected', 409);
        }

        try {
            $this->client->get($store->base_url, $consumerKey, $consumerSecret, '/wc/v3/orders', ['per_page' => 1, '_fields' => 'id']);

            if ($this->client->keyCanWrite($store->base_url, $consumerKey, $consumerSecret)) {
                throw new AccountRejected('woocommerce_key_not_read_only');
            }
        } catch (WooCommerceApiRequestFailed $failure) {
            throw new AccountRejected(match ($failure->reasonCode) {
                'woocommerce_not_found', 'woocommerce_invalid_response', 'woocommerce_api_unavailable' => 'woocommerce_api_unavailable',
                'woocommerce_key_permission_missing' => 'woocommerce_key_not_recognized',
                default => $failure->reasonCode,
            });
        }

        $integration = DB::transaction(function () use ($store, $consumerKey, $consumerSecret, $actorId): Integration {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            if (self::activeOrderSource($store) !== null) {
                throw new AccountRejected('order_source_already_connected', 409);
            }

            $integration = Integration::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'provider' => self::PROVIDER,
                'mode' => 'live',
                'source_authority' => Integration::SOURCE_STORE_REPORTED,
                'status' => Integration::STATUS_ACTIVE,
                'capabilities' => ['orders' => 'read', 'refunds' => 'read', 'key_permission' => 'read'],
                'api_version' => 'wc/v3',
                'connector_version' => self::ADAPTER_VERSION,
                'health' => ['key_last7' => substr($consumerKey, -7), 'key_read_only_checked_at' => Carbon::now()->toJSON()],
            ]);

            $encrypted = $this->keyring->encrypt(json_encode(['key' => $consumerKey, 'secret' => $consumerSecret], JSON_THROW_ON_ERROR));
            IntegrationCredential::query()->create([
                'tenant_id' => $integration->tenant_id,
                'store_id' => $integration->store_id,
                'integration_id' => $integration->id,
                'kind' => IntegrationCredential::KIND_WOOCOMMERCE_API,
                'key_id' => IntegrationCredential::KIND_WOOCOMMERCE_API.':'.Str::uuid(),
                'ciphertext' => $encrypted['ciphertext'],
                'key_version' => $encrypted['key_version'],
                'fingerprint' => hash('sha256', $consumerKey),
                'status' => IntegrationCredential::STATUS_ACTIVE,
                'created_at' => Carbon::now(),
            ]);

            $previous = Integration::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)
                ->where('provider', self::PROVIDER)->where('status', Integration::STATUS_REVOKED)
                ->orderByDesc('created_at')->first();

            if ($previous !== null) {
                app(OrderSourceContinuity::class)->carryForward($previous, $integration);
            }

            AuditLog::query()->create([
                'tenant_id' => $integration->tenant_id,
                'store_id' => $integration->store_id,
                'actor_user_id' => $actorId,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED,
                'entity_type' => AuditLog::ENTITY_INTEGRATION,
                'entity_id' => $integration->id,
                'changes' => ['provider' => self::PROVIDER, 'key_permission' => 'read'],
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
     * @return array{key: string, secret: string}|null
     */
    public function credentials(Integration $integration): ?array
    {
        /** @var IntegrationCredential|null $credential */
        $credential = IntegrationCredential::query()
            ->where('integration_id', $integration->id)
            ->where('kind', IntegrationCredential::KIND_WOOCOMMERCE_API)
            ->where('status', IntegrationCredential::STATUS_ACTIVE)
            ->latest('created_at')
            ->first();
        $decoded = $credential === null ? null : json_decode($this->keyring->decrypt($credential->ciphertext, $credential->key_version), true);

        return is_array($decoded) && is_string($decoded['key'] ?? null) && is_string($decoded['secret'] ?? null)
            ? ['key' => $decoded['key'], 'secret' => $decoded['secret']]
            : null;
    }

    public static function activeOrderSource(Store $store): ?string
    {
        return OrderSources::active($store);
    }
}
