<?php

namespace Tests\Feature\Providers;

use App\Jobs\CheckWooCommerceWebhooks;
use App\Jobs\SyncIntegration;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Support\Network\DnsClient;
use App\Support\Providers\WooCommerce\WooCommerceApiConnector;
use App\Support\Providers\WooCommerce\WooCommerceApiWebhooks;
use App\Support\Security\Keyring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\Feature\Stores\FakeDnsClient;
use Tests\TestCase;

class WooCommerceApiWebhooksTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private FakeDnsClient $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = new FakeDnsClient;
        $this->app->instance(DnsClient::class, $this->dns);
    }

    public function test_a_merchant_sets_up_woocommerce_webhooks_and_a_signed_delivery_asks_for_a_sync(): void
    {
        Queue::fake();
        [$context, $integration] = $this->apiConnection();
        $member = fn (array $ctx) => $this->actingAs($ctx['user'])->withSession(['active_tenant_id' => $ctx['tenant']->id]);

        $viewer = $this->context('viewer', $context['tenant'], $context['store'], $context['integration']);
        $member($viewer)->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook")->assertForbidden();
        $setup = $member($context)->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook")->assertOk()
            ->assertJsonPath('topics', ['order.created', 'order.updated', 'order.deleted', 'order.restored']);
        $this->assertStringEndsWith('/api/v1/webhooks/woocommerce/'.$integration->id, $setup->json('delivery_url'));
        $member($context)->getJson("/api/v1/integrations/{$integration->id}")->assertOk()->assertJsonPath('webhook_url', $setup->json('delivery_url'));
        $secret = $setup->json('secret');
        $this->app['auth']->forgetGuards();

        $this->deliver($integration, 'webhook_id=17', null, null)->assertOk()->assertJsonPath('result', 'ping');
        $this->assertArrayNotHasKey('webhook', $integration->refresh()->health ?? [], 'an unsigned ping proves nothing by itself');
        $this->assertSame(['webhook_id' => 17, 'verified' => false], array_intersect_key($integration->health['webhook_ping'], ['webhook_id' => 1, 'verified' => 1]));
        Queue::assertPushed(CheckWooCommerceWebhooks::class, fn (CheckWooCommerceWebhooks $job): bool => $job->integrationId === $integration->id);

        $order = json_encode(['id' => 1042, 'status' => 'processing'], JSON_THROW_ON_ERROR);
        $this->deliver($integration, $order, 'wrong-secret', 'order.updated')->assertStatus(401);
        $this->assertSame(['reason' => 'signature_invalid', 'topic' => 'order.updated'], array_intersect_key($integration->refresh()->health['webhook_rejected'], ['reason' => 1, 'topic' => 1]));
        $this->deliver($integration, $order, $secret, 'order.updated', 'https://evil.example.test/')->assertStatus(422);
        $this->deliver($integration, $order, $secret, 'product.updated')->assertOk()->assertJsonPath('result', 'topic_not_used');
        Queue::assertNotPushed(SyncIntegration::class);

        $this->deliver($integration, $order, $secret, 'order.updated')->assertOk()->assertJsonPath('result', 'sync_requested');
        Queue::assertPushed(SyncIntegration::class, fn (SyncIntegration $job): bool => $job->integrationId === $integration->id && $job->mode === 'delta');
        $this->assertSame('order.updated', $integration->refresh()->health['webhook']['last_type']);

        $member($context)->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook")->assertOk();
        $this->assertSame([], array_intersect_key($integration->refresh()->health, array_flip(['webhook', 'webhook_ping', 'webhook_rejected', 'webhook_check'])), 'a new secret starts the confirmation again');
        $this->app['auth']->forgetGuards();
        $this->deliver($integration, $order, $secret, 'order.updated')->assertStatus(401, 'a new secret replaces the old one');
    }

    public function test_deliveries_without_a_set_up_webhook_or_for_another_connection_are_refused(): void
    {
        Queue::fake();
        [, $integration] = $this->apiConnection();

        $this->deliver($integration, '{"id":1}', 'any', 'order.updated')->assertStatus(409)->assertJsonPath('code', 'webhook_not_configured');
        $this->postJson('/api/v1/webhooks/woocommerce/not-a-uuid', [])->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_the_check_compares_the_store_webhooks_with_what_the_connection_needs(): void
    {
        Queue::fake();
        [$context, $integration] = $this->apiConnection();
        $this->withKey($integration);
        $member = fn () => $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
        $url = $member()->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook")->assertOk()->json('delivery_url');
        $this->app['auth']->forgetGuards();
        $this->deliver($integration, 'webhook_id=12', null, null)->assertOk();
        $old = 'https://business-watchdog.example/api/v1/webhooks/woocommerce/01a0a0a0-0000-7000-8000-000000000000';
        $listed = [
            ['id' => 11, 'topic' => 'order.created', 'status' => 'active', 'delivery_url' => $url, 'date_modified_gmt' => now()->addMinutes(2)->format('Y-m-d\TH:i:s')],
            ['id' => 12, 'topic' => 'order.updated', 'status' => 'active', 'delivery_url' => $url.'/', 'date_modified_gmt' => now()->addMinutes(2)->format('Y-m-d\TH:i:s')],
            ['id' => 13, 'topic' => 'order.deleted', 'status' => 'disabled', 'delivery_url' => $url, 'date_modified_gmt' => now()->subMinutes(10)->format('Y-m-d\TH:i:s')],
            ['id' => 14, 'topic' => 'order.restored', 'status' => 'active', 'delivery_url' => $old, 'date_modified_gmt' => now()->subMinutes(10)->format('Y-m-d\TH:i:s')],
            ['id' => 15, 'topic' => 'order.restored', 'status' => 'active', 'delivery_url' => 'https://erp.example.test/hook?token=secret-of-another-service'],
        ];
        $requested = [];
        $denied = false;
        Http::fake(function (HttpRequest $request) use (&$listed, &$requested, &$denied) {
            $requested[] = $request->url();

            return $denied ? Http::response(['code' => 'woocommerce_rest_cannot_view'], 401) : Http::response($listed);
        });

        $check = $member()->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook/check")->assertOk();
        $check->assertJsonPath('state', 'issues')
            ->assertJsonPath('topics', ['order.created' => 'active', 'order.updated' => 'active', 'order.deleted' => 'disabled', 'order.restored' => 'missing'])
            ->assertJsonPath('issues', ['old_address']);
        $this->assertStringContainsString('/wp-json/wc/v3/webhooks', $requested[0]);
        $health = $integration->refresh()->health;
        $this->assertTrue($health['webhook_ping']['verified'], 'the ping belongs to an active webhook of this store');
        $this->assertStringNotContainsString('erp.example.test', json_encode($health, JSON_THROW_ON_ERROR), 'other webhooks of the store are not kept');

        $listed[2]['status'] = 'active';
        $listed[3]['delivery_url'] = $url;
        $member()->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook/check")->assertOk()->assertJsonPath('state', 'issues')->assertJsonPath('issues', ['secret_newer_than_webhooks']);

        foreach ($listed as $index => $webhook) {
            $listed[$index]['date_modified_gmt'] = now()->addMinutes(3)->format('Y-m-d\TH:i:s');
        }

        $this->travel(2)->minutes();
        $member()->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook/check")->assertOk()->assertJsonPath('state', 'ok')->assertJsonPath('issues', []);

        $denied = true;
        $this->travel(1)->minutes();
        $member()->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook/check")->assertOk()->assertJsonPath('state', 'not_readable')->assertJsonPath('error_code', 'woocommerce_key_permission_missing');
    }

    public function test_only_managers_check_and_a_queued_check_reads_the_store_once(): void
    {
        [$context, $integration] = $this->apiConnection();
        $this->withKey($integration);
        $viewer = $this->context('viewer', $context['tenant'], $context['store'], $context['integration']);
        $this->actingAs($viewer['user'])->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook/check")->assertForbidden();
        Http::fake(fn () => Http::response([]));

        (new CheckWooCommerceWebhooks($integration->id))->handle(app(WooCommerceApiWebhooks::class));

        $this->assertSame(['order.created' => 'missing', 'order.updated' => 'missing', 'order.deleted' => 'missing', 'order.restored' => 'missing'], $integration->refresh()->health['webhook_check']['topics']);
        Http::assertSentCount(1);
    }

    private function withKey(Integration $integration): void
    {
        $this->dns->ips['www.kaffee.example.test'] = ['93.184.216.34'];
        $encrypted = app(Keyring::class)->encrypt(json_encode(['key' => 'ck_'.str_repeat('a', 40), 'secret' => 'cs_'.str_repeat('b', 40)], JSON_THROW_ON_ERROR));
        IntegrationCredential::query()->create([
            'tenant_id' => $integration->tenant_id, 'store_id' => $integration->store_id, 'integration_id' => $integration->id,
            'kind' => IntegrationCredential::KIND_WOOCOMMERCE_API, 'key_id' => 'woocommerce_api:'.Str::uuid(),
            'ciphertext' => $encrypted['ciphertext'], 'key_version' => $encrypted['key_version'], 'fingerprint' => hash('sha256', 'k'),
            'status' => IntegrationCredential::STATUS_ACTIVE, 'created_at' => now(),
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: Integration}
     */
    private function apiConnection(): array
    {
        $context = $this->context();
        $context['store']->forceFill(['base_url' => 'https://www.kaffee.example.test'])->save();
        $integration = Integration::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'provider' => WooCommerceApiConnector::PROVIDER, 'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => ['orders' => 'read'], 'connector_version' => WooCommerceApiConnector::ADAPTER_VERSION, 'health' => [],
        ]);

        return [$context, $integration];
    }

    private function deliver(Integration $integration, string $body, ?string $secret, ?string $topic, string $source = 'https://kaffee.example.test/'): TestResponse
    {
        $headers = ['CONTENT_TYPE' => $topic === null ? 'application/x-www-form-urlencoded' : 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($secret !== null) {
            $headers['HTTP_X_WC_WEBHOOK_SIGNATURE'] = base64_encode(hash_hmac('sha256', $body, $secret, true));
            $headers['HTTP_X_WC_WEBHOOK_TOPIC'] = (string) $topic;
            $headers['HTTP_X_WC_WEBHOOK_SOURCE'] = $source;
        }

        return $this->call('POST', '/api/v1/webhooks/woocommerce/'.$integration->id, [], [], [], $headers, $body);
    }
}
