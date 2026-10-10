<?php

namespace App\Support\Providers\PayPal;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\ReconciliationDirtySubject;
use App\Models\Store;
use App\Support\Account\AccountRejected;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use App\Support\Security\Keyring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connects a store to a PayPal REST app entered in the dashboard (ADR 0022). PayPal cannot issue read-only
 * credentials, so the owner must acknowledge that the app also allows refunds and captures; the adapter itself only
 * reads (PayPalClient allowlist). Transaction Search must be enabled: it is the only way to list transactions.
 */
class PayPalConnector
{
    public const ADAPTER_VERSION = 'paypal-adapter-1';

    public const SEARCH_SCOPE = 'https://uri.paypal.com/services/reporting/search/read';

    /** Scopes that let the credentials move money; reported to the owner, never used. */
    public const WRITE_SCOPES = [
        'https://uri.paypal.com/services/payments/refund' => 'refund',
        'https://uri.paypal.com/services/payments/payment/authcapture' => 'capture',
        'https://uri.paypal.com/payments/payouts' => 'payouts',
        'https://api.paypal.com/v1/payments/.*' => 'payments_v1',
        'https://uri.paypal.com/services/subscriptions' => 'subscriptions',
        'https://uri.paypal.com/services/disputes/update-seller' => 'disputes',
        'https://uri.paypal.com/services/vault/payment-tokens/readwrite' => 'vault',
    ];

    public function __construct(
        private readonly PayPalClient $client,
        private readonly Keyring $keyring,
        private readonly StoreReconciliationRequeue $requeue,
    ) {}

    public function connect(Store $store, string $environment, string $clientId, string $clientSecret, ?string $webhookId, bool $writeAccessAcknowledged, ?string $actorId): Integration
    {
        if (! in_array($environment, ['sandbox', 'live'], true) || preg_match('/^[A-Za-z0-9_-]{20,128}$/', $clientId) !== 1 || preg_match('/^[A-Za-z0-9_-]{20,128}$/', $clientSecret) !== 1) {
            throw new AccountRejected('paypal_credentials_invalid');
        }

        if ($webhookId !== null && preg_match('/^[A-Z0-9]{10,40}$/', $webhookId) !== 1) {
            throw new AccountRejected('paypal_webhook_id_invalid');
        }

        if (! $writeAccessAcknowledged) {
            throw new AccountRejected('paypal_write_access_not_acknowledged');
        }

        $mode = $environment === 'live' ? 'live' : 'test';

        try {
            $token = $this->client->token($mode, $clientId, $clientSecret, fresh: true);

            if (! in_array(self::SEARCH_SCOPE, $token['scopes'], true)) {
                throw new AccountRejected('paypal_transaction_search_missing');
            }

            $now = Carbon::now()->startOfMinute();
            $this->client->get($mode, $token['token'], '/v1/reporting/transactions', [
                'start_date' => $now->copy()->subDay()->format('Y-m-d\TH:i:s\Z'),
                'end_date' => $now->format('Y-m-d\TH:i:s\Z'),
                'page_size' => 1,
            ]);
        } catch (PayPalRequestFailed $failure) {
            throw new AccountRejected($failure->reasonCode === 'paypal_permission_missing' ? 'paypal_transaction_search_missing' : $failure->reasonCode);
        }

        $writeScopes = array_values(array_unique(array_filter(array_map(fn (string $scope): ?string => self::WRITE_SCOPES[$scope] ?? null, $token['scopes']))));
        sort($writeScopes);

        $integration = DB::transaction(function () use ($store, $mode, $clientId, $clientSecret, $webhookId, $token, $writeScopes, $actorId): Integration {
            $exists = Integration::query()
                ->where('tenant_id', $store->tenant_id)
                ->where('store_id', $store->id)
                ->where('provider', 'paypal')
                ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED, Integration::STATUS_PENDING])
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw new AccountRejected('provider_already_connected', 409);
            }

            $integration = Integration::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'provider' => 'paypal',
                'external_account_id' => $token['app_id'],
                'mode' => $mode,
                'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
                'status' => Integration::STATUS_ACTIVE,
                'capabilities' => ['transaction_search' => 'read', 'orders' => 'read', 'payments' => 'read'],
                'api_version' => 'v2',
                'connector_version' => self::ADAPTER_VERSION,
                'health' => ['client_id_last4' => substr($clientId, -4), 'write_scopes' => $writeScopes, 'write_access_acknowledged_at' => Carbon::now()->toJSON()],
            ]);

            $this->storeSecret($integration, IntegrationCredential::KIND_PAYPAL_CLIENT, json_encode(['client_id' => $clientId, 'client_secret' => $clientSecret], JSON_THROW_ON_ERROR));

            if ($webhookId !== null) {
                $this->storeSecret($integration, IntegrationCredential::KIND_PAYPAL_WEBHOOK, $webhookId);
            }

            $this->audit($integration, $actorId, AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED, ['provider' => 'paypal', 'mode' => $mode, 'write_scopes' => $writeScopes, 'write_access_acknowledged' => true]);

            return $integration;
        });

        $this->requeue->requeue($store->tenant_id, $store->id, ReconciliationDirtySubject::REASON_PROVIDER_COVERAGE_CHANGED);

        return $integration->refresh();
    }

    public function setWebhookId(Integration $integration, string $webhookId, ?string $actorId): Integration
    {
        if (preg_match('/^[A-Z0-9]{10,40}$/', $webhookId) !== 1) {
            throw new AccountRejected('paypal_webhook_id_invalid');
        }

        DB::transaction(function () use ($integration, $webhookId, $actorId): void {
            IntegrationCredential::query()
                ->where('integration_id', $integration->id)
                ->where('kind', IntegrationCredential::KIND_PAYPAL_WEBHOOK)
                ->where('status', IntegrationCredential::STATUS_ACTIVE)
                ->update(['status' => IntegrationCredential::STATUS_REVOKED, 'rotated_at' => Carbon::now()]);
            $this->storeSecret($integration, IntegrationCredential::KIND_PAYPAL_WEBHOOK, $webhookId);
            $this->audit($integration, $actorId, AuditLog::ACTION_INTEGRATION_WEBHOOK_SECRET_SET, ['provider' => 'paypal']);
        });

        return $integration->refresh();
    }

    /**
     * @return array{client_id: string, client_secret: string}|null
     */
    public function credentials(Integration $integration): ?array
    {
        $secret = $this->secret($integration, IntegrationCredential::KIND_PAYPAL_CLIENT);
        $decoded = $secret === null ? null : json_decode($secret, true);

        return is_array($decoded) && is_string($decoded['client_id'] ?? null) && is_string($decoded['client_secret'] ?? null)
            ? ['client_id' => $decoded['client_id'], 'client_secret' => $decoded['client_secret']]
            : null;
    }

    public function secret(Integration $integration, string $kind): ?string
    {
        /** @var IntegrationCredential|null $credential */
        $credential = IntegrationCredential::query()
            ->where('integration_id', $integration->id)
            ->where('kind', $kind)
            ->where('status', IntegrationCredential::STATUS_ACTIVE)
            ->latest('created_at')
            ->first();

        return $credential === null ? null : $this->keyring->decrypt($credential->ciphertext, $credential->key_version);
    }

    private function storeSecret(Integration $integration, string $kind, string $secret): void
    {
        $encrypted = $this->keyring->encrypt($secret);

        IntegrationCredential::query()->create([
            'tenant_id' => $integration->tenant_id,
            'store_id' => $integration->store_id,
            'integration_id' => $integration->id,
            'kind' => $kind,
            'key_id' => $kind.':'.Str::uuid(),
            'ciphertext' => $encrypted['ciphertext'],
            'key_version' => $encrypted['key_version'],
            'fingerprint' => hash('sha256', $secret),
            'status' => IntegrationCredential::STATUS_ACTIVE,
            'created_at' => Carbon::now(),
        ]);
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
            'actor_type' => AuditLog::ACTOR_USER,
            'action' => $action,
            'entity_type' => AuditLog::ENTITY_INTEGRATION,
            'entity_id' => $integration->id,
            'changes' => $changes,
            'request_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);
    }
}
