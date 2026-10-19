<?php

namespace App\Support\Providers\Stripe;

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
 * Connects a store to its Stripe account with a restricted read-only key entered in the dashboard (ADR 0006, 0020).
 * Full secret keys are refused; every read the adapter needs is probed before anything is stored.
 */
class StripeConnector
{
    public const ADAPTER_VERSION = 'stripe-adapter-1';

    /** @var array<string, string> */
    public const READ_PROBES = [
        'payment_intents' => '/v1/payment_intents',
        'charges' => '/v1/charges',
        'refunds' => '/v1/refunds',
    ];

    public function __construct(
        private readonly StripeClient $client,
        private readonly Keyring $keyring,
        private readonly StoreReconciliationRequeue $requeue,
    ) {}

    public function connect(Store $store, string $apiKey, ?string $webhookSecret, ?string $expectedAccountId, ?string $actorId): Integration
    {
        if (preg_match('/^rk_(test|live)_[A-Za-z0-9]{10,}$/', $apiKey, $matches) !== 1) {
            throw new AccountRejected(str_starts_with($apiKey, 'sk_') ? 'stripe_secret_key_refused' : 'stripe_key_invalid');
        }

        if ($webhookSecret !== null && preg_match('/^whsec_[A-Za-z0-9]{10,}$/', $webhookSecret) !== 1) {
            throw new AccountRejected('stripe_webhook_secret_invalid');
        }

        $mode = $matches[1];
        $capabilities = [];

        foreach (self::READ_PROBES as $name => $path) {
            try {
                $this->client->get($apiKey, $path, ['limit' => 1]);
                $capabilities[$name] = 'read';
            } catch (StripeRequestFailed $failure) {
                throw new AccountRejected($failure->reasonCode === 'stripe_permission_missing' ? 'stripe_permission_missing' : $failure->reasonCode);
            }
        }

        $accountId = null;

        try {
            $account = $this->client->get($apiKey, '/v1/account');
            $accountId = is_string($account['id'] ?? null) ? $account['id'] : null;
            $capabilities['account'] = 'read';
        } catch (StripeRequestFailed $failure) {
            if ($failure->keyUnusable()) {
                throw new AccountRejected($failure->reasonCode);
            }
        }

        if ($expectedAccountId !== null && $accountId !== null && $expectedAccountId !== $accountId) {
            throw new AccountRejected('stripe_account_mismatch');
        }

        $integration = DB::transaction(function () use ($store, $apiKey, $webhookSecret, $mode, $capabilities, $accountId, $expectedAccountId, $actorId): Integration {
            $exists = Integration::query()
                ->where('tenant_id', $store->tenant_id)
                ->where('store_id', $store->id)
                ->where('provider', 'stripe')
                ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED, Integration::STATUS_PENDING])
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw new AccountRejected('provider_already_connected', 409);
            }

            $integration = Integration::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'provider' => 'stripe',
                'external_account_id' => $accountId ?? $expectedAccountId,
                'mode' => $mode,
                'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
                'status' => Integration::STATUS_ACTIVE,
                'capabilities' => $capabilities,
                'api_version' => (string) config('watchdog.stripe.api_version'),
                'connector_version' => self::ADAPTER_VERSION,
                'health' => ['key_last4' => substr($apiKey, -4), 'account_verified' => $accountId !== null],
            ]);

            $this->storeSecret($integration, IntegrationCredential::KIND_STRIPE_API, $apiKey);

            if ($webhookSecret !== null) {
                $this->storeSecret($integration, IntegrationCredential::KIND_STRIPE_WEBHOOK, $webhookSecret);
            }

            $this->audit($integration, $actorId, AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED, ['provider' => 'stripe', 'mode' => $mode, 'account_verified' => $accountId !== null]);

            return $integration;
        });

        $this->requeue->requeue($store->tenant_id, $store->id, ReconciliationDirtySubject::REASON_PROVIDER_COVERAGE_CHANGED);

        return $integration->refresh();
    }

    public function setWebhookSecret(Integration $integration, string $webhookSecret, ?string $actorId): Integration
    {
        if (preg_match('/^whsec_[A-Za-z0-9]{10,}$/', $webhookSecret) !== 1) {
            throw new AccountRejected('stripe_webhook_secret_invalid');
        }

        DB::transaction(function () use ($integration, $webhookSecret, $actorId): void {
            IntegrationCredential::query()
                ->where('integration_id', $integration->id)
                ->where('kind', IntegrationCredential::KIND_STRIPE_WEBHOOK)
                ->where('status', IntegrationCredential::STATUS_ACTIVE)
                ->update(['status' => IntegrationCredential::STATUS_REVOKED, 'rotated_at' => Carbon::now()]);
            $this->storeSecret($integration, IntegrationCredential::KIND_STRIPE_WEBHOOK, $webhookSecret);
            $this->audit($integration, $actorId, AuditLog::ACTION_INTEGRATION_WEBHOOK_SECRET_SET, []);
        });

        return $integration->refresh();
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
