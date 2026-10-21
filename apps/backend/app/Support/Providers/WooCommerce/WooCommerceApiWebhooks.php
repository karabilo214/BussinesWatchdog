<?php

namespace App\Support\Providers\WooCommerce;

use App\Jobs\SyncIntegration;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Support\Billing\StoreCapacity;
use App\Support\Security\Keyring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Order webhooks the merchant sets up in WooCommerce for a REST API connection (ADR 0077): no plugin, only
 * WooCommerce's own Settings → Advanced → Webhooks. A signed delivery only asks for the next delta sync at once;
 * the body is never trusted and polling stays the guarantee.
 */
class WooCommerceApiWebhooks
{
    public const TOPICS = ['order.created', 'order.updated', 'order.deleted', 'order.restored'];

    public function __construct(private readonly Keyring $keyring) {}

    /**
     * Creates a new secret (an older one stops working) and returns what the merchant enters in WooCommerce.
     *
     * @return array{delivery_url: string, secret: string, topics: list<string>}
     */
    public function setUp(Integration $integration, ?string $actorId): array
    {
        $secret = Str::random(40);

        DB::transaction(function () use ($integration, $secret, $actorId): void {
            IntegrationCredential::query()->where('integration_id', $integration->id)->where('kind', IntegrationCredential::KIND_WOOCOMMERCE_WEBHOOK)->update(['status' => IntegrationCredential::STATUS_REVOKED]);
            $encrypted = $this->keyring->encrypt($secret);
            IntegrationCredential::query()->create([
                'tenant_id' => $integration->tenant_id,
                'store_id' => $integration->store_id,
                'integration_id' => $integration->id,
                'kind' => IntegrationCredential::KIND_WOOCOMMERCE_WEBHOOK,
                'key_id' => IntegrationCredential::KIND_WOOCOMMERCE_WEBHOOK.':'.Str::uuid(),
                'ciphertext' => $encrypted['ciphertext'],
                'key_version' => $encrypted['key_version'],
                'fingerprint' => hash('sha256', $secret),
                'status' => IntegrationCredential::STATUS_ACTIVE,
                'created_at' => Carbon::now(),
            ]);
            $health = $integration->health ?? [];
            unset($health['webhook']);
            $integration->forceFill(['health' => [...$health, 'webhook_set_up_at' => Carbon::now()->toJSON()]])->save();
            AuditLog::query()->create([
                'tenant_id' => $integration->tenant_id,
                'store_id' => $integration->store_id,
                'actor_user_id' => $actorId,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED,
                'entity_type' => AuditLog::ENTITY_INTEGRATION,
                'entity_id' => $integration->id,
                'changes' => ['provider' => WooCommerceApiConnector::PROVIDER, 'stage' => 'webhook_secret_created'],
                'request_id' => (string) Str::uuid(),
                'created_at' => Carbon::now(),
            ]);
        });

        return ['delivery_url' => self::deliveryUrl($integration), 'secret' => $secret, 'topics' => self::TOPICS];
    }

    public static function deliveryUrl(Integration $integration): string
    {
        return url('/api/v1/webhooks/woocommerce/'.$integration->id);
    }

    /**
     * @param  array<string, string>  $headers  lower-case header names
     * @return array{status: int, result: string}
     */
    public function receive(Integration $integration, string $rawBody, array $headers): array
    {
        $secret = $this->secret($integration);

        if ($secret === null) {
            return ['status' => 409, 'result' => 'webhook_not_configured'];
        }

        $signature = $headers['x-wc-webhook-signature'] ?? '';
        $signed = $signature !== '' && hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $signature);

        if (preg_match('/^webhook_id=\d+$/', trim($rawBody)) === 1) {
            if ($signed) {
                $this->record($integration, 'ping');
            }

            return ['status' => 200, 'result' => 'ping'];
        }

        if (! $signed) {
            return ['status' => 401, 'result' => 'signature_invalid'];
        }

        $topic = $headers['x-wc-webhook-topic'] ?? '';
        $source = $headers['x-wc-webhook-source'] ?? '';
        $store = Store::query()->find($integration->store_id);

        if ($store === null || $this->host($source) !== $this->host($store->base_url)) {
            return ['status' => 422, 'result' => 'source_mismatch'];
        }

        if (! in_array($topic, self::TOPICS, true)) {
            return ['status' => 200, 'result' => 'topic_not_used'];
        }

        $this->record($integration, $topic);

        if (! in_array($integration->status, [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED], true) || StoreCapacity::paused($integration)) {
            return ['status' => 200, 'result' => 'ignored'];
        }

        SyncIntegration::dispatch($integration->id, WooCommerceApiSync::MODE_DELTA);

        return ['status' => 200, 'result' => 'sync_requested'];
    }

    private function secret(Integration $integration): ?string
    {
        /** @var IntegrationCredential|null $credential */
        $credential = IntegrationCredential::query()
            ->where('integration_id', $integration->id)
            ->where('kind', IntegrationCredential::KIND_WOOCOMMERCE_WEBHOOK)
            ->where('status', IntegrationCredential::STATUS_ACTIVE)
            ->latest('created_at')
            ->first();

        return $credential === null ? null : $this->keyring->decrypt($credential->ciphertext, $credential->key_version);
    }

    private function record(Integration $integration, string $type): void
    {
        $integration->forceFill(['health' => array_merge($integration->health ?? [], ['webhook' => ['last_received_at' => Carbon::now()->toJSON(), 'last_type' => $type]])])->save();
    }

    private function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }
}
