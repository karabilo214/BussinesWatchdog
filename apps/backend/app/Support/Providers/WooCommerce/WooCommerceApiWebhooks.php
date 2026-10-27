<?php

namespace App\Support\Providers\WooCommerce;

use App\Jobs\CheckWooCommerceWebhooks;
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
 * the body is never trusted and polling stays the guarantee. The merchant sees whether the set-up works: the unsigned
 * ping WooCommerce sends when a webhook is saved is checked against the store's own webhook list (read with the key),
 * a rejected signature is kept as the reason, and only a signed delivery counts as working.
 */
class WooCommerceApiWebhooks
{
    public const TOPICS = ['order.created', 'order.updated', 'order.deleted', 'order.restored'];

    public const CHECK_NOT_READABLE = 'not_readable';

    public function __construct(
        private readonly Keyring $keyring,
        private readonly WooCommerceApiClient $client,
        private readonly WooCommerceApiConnector $connector,
    ) {}

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
            unset($health['webhook'], $health['webhook_ping'], $health['webhook_rejected'], $health['webhook_check']);
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

        if (preg_match('/^webhook_id=(\d{1,20})$/', trim($rawBody), $ping) === 1) {
            $this->remember($integration, 'webhook_ping', ['webhook_id' => (int) $ping[1], 'received_at' => Carbon::now()->toJSON(), 'verified' => false]);
            CheckWooCommerceWebhooks::dispatch($integration->id);

            return ['status' => 200, 'result' => 'ping'];
        }

        $topic = $headers['x-wc-webhook-topic'] ?? '';
        $source = $headers['x-wc-webhook-source'] ?? '';
        $store = Store::query()->find($integration->store_id);
        $fromStore = $store !== null && $this->host($source) === $this->host($store->base_url);

        if (! $signed) {
            if ($fromStore && in_array($topic, self::TOPICS, true)) {
                $this->remember($integration, 'webhook_rejected', ['reason' => 'signature_invalid', 'topic' => $topic, 'at' => Carbon::now()->toJSON()]);
            }

            return ['status' => 401, 'result' => 'signature_invalid'];
        }

        if (! $fromStore) {
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

    /**
     * Reads the store's webhooks with the read-only key and compares them with what this connection needs. Only this
     * connection's own webhooks are kept (id, topic, status, last change); other webhooks of the store and their
     * delivery addresses are dropped in memory.
     *
     * @return array<string, mixed>
     */
    public function check(Integration $integration): array
    {
        $now = Carbon::now();
        $credentials = $this->connector->credentials($integration);
        $store = Store::query()->find($integration->store_id);

        if ($credentials === null || $store === null) {
            return $this->storeCheck($integration, ['checked_at' => $now->toJSON(), 'state' => self::CHECK_NOT_READABLE, 'error_code' => 'woocommerce_key_rejected']);
        }

        try {
            $listed = $this->client->get($store->base_url, $credentials['key'], $credentials['secret'], '/wc/v3/webhooks', ['per_page' => 100, 'status' => 'all'])['json'];
        } catch (WooCommerceApiRequestFailed $failure) {
            return $this->storeCheck($integration, ['checked_at' => $now->toJSON(), 'state' => self::CHECK_NOT_READABLE, 'error_code' => $failure->reasonCode]);
        }

        $address = rtrim(self::deliveryUrl($integration), '/');
        $ours = [];
        $elsewhere = 0;

        foreach (is_array($listed) ? $listed : [] as $webhook) {
            if (! is_array($webhook) || ! in_array($webhook['topic'] ?? null, self::TOPICS, true)) {
                continue;
            }

            $url = rtrim((string) ($webhook['delivery_url'] ?? ''), '/');

            if ($url === $address) {
                $ours[] = [
                    'id' => (int) ($webhook['id'] ?? 0),
                    'topic' => (string) $webhook['topic'],
                    'status' => (string) ($webhook['status'] ?? ''),
                    'modified_at' => $this->gmt($webhook['date_modified_gmt'] ?? $webhook['date_created_gmt'] ?? null),
                ];
            } elseif (str_contains($url, '/api/v1/webhooks/woocommerce/')) {
                $elsewhere++;
            }
        }

        $topics = [];

        foreach (self::TOPICS as $topic) {
            $matching = array_values(array_filter($ours, fn (array $webhook): bool => $webhook['topic'] === $topic));
            $active = array_filter($matching, fn (array $webhook): bool => $webhook['status'] === 'active');
            $topics[$topic] = $active !== [] ? 'active' : ($matching !== [] ? ($matching[0]['status'] === 'disabled' ? 'disabled' : 'paused') : 'missing');
        }

        $health = $integration->health ?? [];
        $issues = [];
        $setUpAt = is_string($health['webhook_set_up_at'] ?? null) ? Carbon::parse($health['webhook_set_up_at']) : null;
        $lastChange = collect($ours)->where('status', 'active')->pluck('modified_at')->filter()->map(fn (string $at): Carbon => Carbon::parse($at))->sort()->first();

        if ($setUpAt !== null && $lastChange !== null && $lastChange->lessThan($setUpAt->copy()->subMinute()) && ! isset($health['webhook']['last_received_at'])) {
            $issues[] = 'secret_newer_than_webhooks';
        }

        if ($elsewhere > 0) {
            $issues[] = 'old_address';
        }

        $ping = $health['webhook_ping'] ?? null;

        if (is_array($ping) && in_array((int) ($ping['webhook_id'] ?? 0), array_column(array_filter($ours, fn (array $webhook): bool => $webhook['status'] === 'active'), 'id'), true)) {
            $this->remember($integration, 'webhook_ping', [...$ping, 'verified' => true]);
        }

        return $this->storeCheck($integration, [
            'checked_at' => $now->toJSON(),
            'state' => in_array('missing', $topics, true) || in_array('paused', $topics, true) || in_array('disabled', $topics, true) || $issues !== [] ? 'issues' : 'ok',
            'topics' => $topics,
            'issues' => $issues,
        ]);
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function storeCheck(Integration $integration, array $check): array
    {
        $this->remember($integration, 'webhook_check', $check);

        return $check;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function remember(Integration $integration, string $key, array $value): void
    {
        DB::transaction(function () use ($integration, $key, $value): void {
            $locked = Integration::query()->whereKey($integration->id)->lockForUpdate()->first();

            if ($locked !== null) {
                $locked->forceFill(['health' => [...($locked->health ?? []), $key => $value]])->save();
                $integration->setRawAttributes($locked->getAttributes(), true);
            }
        });
    }

    private function gmt(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value, 'UTC')->toJSON() : null;
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
        $this->remember($integration, 'webhook', ['last_received_at' => Carbon::now()->toJSON(), 'last_type' => $type]);
    }

    private function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }
}
