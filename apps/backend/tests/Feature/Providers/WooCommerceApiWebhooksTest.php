<?php

namespace Tests\Feature\Providers;

use App\Jobs\SyncIntegration;
use App\Models\Integration;
use App\Support\Providers\WooCommerce\WooCommerceApiConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class WooCommerceApiWebhooksTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

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
        $this->assertArrayNotHasKey('webhook', $integration->refresh()->health ?? [], 'an unsigned ping proves nothing');

        $order = json_encode(['id' => 1042, 'status' => 'processing'], JSON_THROW_ON_ERROR);
        $this->deliver($integration, $order, 'wrong-secret', 'order.updated')->assertStatus(401);
        $this->deliver($integration, $order, $secret, 'order.updated', 'https://evil.example.test/')->assertStatus(422);
        $this->deliver($integration, $order, $secret, 'product.updated')->assertOk()->assertJsonPath('result', 'topic_not_used');
        Queue::assertNothingPushed();

        $this->deliver($integration, $order, $secret, 'order.updated')->assertOk()->assertJsonPath('result', 'sync_requested');
        Queue::assertPushed(SyncIntegration::class, fn (SyncIntegration $job): bool => $job->integrationId === $integration->id && $job->mode === 'delta');
        $this->assertSame('order.updated', $integration->refresh()->health['webhook']['last_type']);

        $member($context)->postJson("/api/v1/integrations/{$integration->id}/woocommerce-webhook")->assertOk();
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
