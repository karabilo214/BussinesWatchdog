<?php

namespace Tests\Feature\Providers;

use App\Http\Dto\Orders\OrderDto;
use App\Models\AuditLog;
use App\Models\EventInbox;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PairingCode;
use App\Models\Refund;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Network\DnsClient;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Providers\WooCommerce\WooCommerceAmount;
use App\Support\Providers\WooCommerce\WooCommerceApiClient;
use App\Support\Providers\WooCommerce\WooCommerceApiSync;
use App\Support\Reconciliation\OrderReconciliationService;
use App\Support\Stores\StoreVerificationService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Stores\FakeDnsClient;
use Tests\TestCase;

class WooCommerceApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'ck_0123456789abcdef0123456789abcdef01234567';

    private const SECRET = 'cs_89abcdef0123456789abcdef0123456789abcdef';

    private User $user;

    private Tenant $tenant;

    private Store $store;

    private FakeDnsClient $dns;

    private string $permission = 'read';

    private bool $keyValid = true;

    /** @var array<int, array<string, mixed>> */
    private array $orders = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $refunds = [];

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['watchdog.woocommerce_api.request_pause_ms' => 0, 'watchdog.woocommerce_api.dev_endpoint' => '']);
        $this->dns = new FakeDnsClient;
        $this->dns->ips['shop.example.test'] = ['93.184.216.34'];
        $this->app->instance(DnsClient::class, $this->dns);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'de']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->requests[] = $request->method().' '.$path.(isset($query['status']) ? ' status='.$query['status'] : '').(isset($query['include']) ? ' include' : '');

            if (! $this->keyValid || $request->header('Authorization')[0] !== 'Basic '.base64_encode(self::KEY.':'.self::SECRET)) {
                return Http::response(['code' => 'woocommerce_rest_authentication_error', 'message' => 'Consumer key is invalid.'], 401);
            }

            if ($request->method() !== 'GET') {
                return $this->permission === 'read'
                    ? Http::response(['code' => 'woocommerce_rest_authentication_error', 'message' => 'The API key provided does not have write permissions.'], 401)
                    : Http::response(['code' => 'rest_no_route', 'message' => 'No route was found.'], 404);
            }

            if ($path === '/wp-json/wc/v3/orders') {
                return $this->listOrders($query);
            }

            if (preg_match('#^/wp-json/wc/v3/orders/(\d+)/refunds$#', $path, $match) === 1) {
                return Http::response(array_values($this->refunds[(int) $match[1]] ?? []));
            }

            return Http::response(['code' => 'rest_no_route'], 404);
        });
    }

    public function test_only_a_read_key_on_the_stores_own_origin_connects_and_nothing_is_written(): void
    {
        $this->connect(['consumer_key' => 'ck_short'])->assertStatus(422)->assertJsonPath('code', 'woocommerce_key_invalid');

        $this->permission = 'read_write';
        $this->connect()->assertStatus(422)->assertJsonPath('code', 'woocommerce_key_not_read_only');

        $this->permission = 'read';
        $this->keyValid = false;
        $this->connect()->assertStatus(422)->assertJsonPath('code', 'woocommerce_key_rejected');

        $this->keyValid = true;
        $this->requests = [];
        $response = $this->connect()->assertCreated()->assertJsonPath('provider', 'woocommerce_api');

        $this->assertSame(['GET /wp-json/wc/v3/orders', 'POST /wp-json/wc/v3/system_status'], $this->requests, 'one read and the permission probe on a route without a write handler');
        $integration = Integration::query()->findOrFail($response->json('id'));
        $this->assertSame([Integration::SOURCE_STORE_REPORTED, 'read'], [$integration->source_authority, $integration->capabilities['key_permission']]);
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, (string) IntegrationCredential::query()->where('kind', IntegrationCredential::KIND_WOOCOMMERCE_API)->value('ciphertext'));
        $this->assertStringNotContainsString(self::SECRET, json_encode(AuditLog::query()->pluck('changes'), JSON_THROW_ON_ERROR));
        $this->connect()->assertStatus(409)->assertJsonPath('code', 'provider_already_connected');

        $this->assertFalse(WooCommerceApiClient::allowed('POST', '/wc/v3/orders'));
        $this->assertFalse(WooCommerceApiClient::allowed('GET', '/wc/v3/customers'));
        $this->assertTrue(WooCommerceApiClient::allowed('GET', '/wc/v3/orders/12/refunds'));
    }

    public function test_one_order_source_per_store_and_private_addresses_are_refused(): void
    {
        Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'woocommerce', 'install_id' => (string) Str::uuid(),
            'mode' => 'live', 'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => '0.2.0', 'health' => [],
        ]);
        $this->connect()->assertStatus(409)->assertJsonPath('code', 'order_source_already_connected');
        Integration::query()->update(['status' => Integration::STATUS_REVOKED]);

        $this->dns->ips['shop.example.test'] = ['10.0.0.7'];
        $this->requests = [];
        $this->connect()->assertStatus(422)->assertJsonPath('code', 'woocommerce_store_url_not_allowed');
        $this->assertSame([], $this->requests);
    }

    public function test_orders_and_refunds_become_the_same_events_the_plugin_sends_without_personal_data(): void
    {
        $this->wcOrder(41, ['payment_method' => 'stripe', 'transaction_id' => 'ch_3Abc', 'total' => '184.000000', 'meta' => ['_bw_payment_mode' => 'test']]);
        $this->wcRefund(41, 51, '30.000000', ['_stripe_refund_id' => 're_3Abc'], true);
        $this->wcOrder(42, ['payment_method' => 'ppcp-gateway', 'transaction_id' => '5TR80192ED162870P', 'currency' => 'USD', 'total' => '10.000000', 'meta' => ['_ppcp_paypal_payment_mode' => 'sandbox', '_ppcp_paypal_payer_email' => 'buyer@example.test']]);
        $this->wcOrder(43, ['payment_method' => 'merchant_special', 'payment_method_title' => ' <b>Pay at pickup</b> ', 'currency' => 'JPY', 'total' => '1500.000000', 'status' => 'on-hold', 'date_paid_gmt' => null]);
        $this->wcOrder(44, ['payment_method' => 'stripe', 'total' => '19.999000']);
        $integration = $this->connected();

        $result = app(WooCommerceApiSync::class)->run($integration);
        $this->drain();

        $this->assertSame('ok', $result['status']);
        $stripe = Order::query()->where('external_id', '41')->firstOrFail();
        $this->assertSame(['processing', 'EUR', 18400, 'ch_3Abc', 'stripe', 'test', 'supported', '41'], [$stripe->status, $stripe->currency, (int) $stripe->total_minor, $stripe->transaction_ref, $stripe->gateway, $stripe->mode, $stripe->financial_support, $stripe->display_number]);
        $refund = Refund::query()->where('external_id', '51')->firstOrFail();
        $this->assertSame([3000, 're_3Abc', true, $stripe->id], [(int) $refund->amount_minor, $refund->provider_ref, (bool) $refund->external_required, $refund->order_id]);
        $this->assertSame('test', Order::query()->where('external_id', '42')->value('mode'));
        $this->assertSame(1500, (int) Order::query()->where('external_id', '43')->value('total_minor'));
        $this->assertSame('Pay at pickup', Order::query()->where('external_id', '43')->firstOrFail()->metadata['gateway_name']);
        $this->assertSame('merchant_special', Order::query()->where('external_id', '43')->value('gateway'));
        $this->assertNull(Order::query()->where('external_id', '44')->first(), 'an amount finer than the currency allows is not sent');
        $this->assertSame(['amount_not_representable' => 1], $integration->refresh()->health['sync']['skipped']);
        $this->assertSame([EventInbox::STATUS_PROCESSED], EventInbox::query()->distinct()->pluck('status')->all());
        $this->assertStringNotContainsString('buyer@example.test', json_encode(EventInbox::query()->pluck('payload'), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Private', json_encode(EventInbox::query()->pluck('payload'), JSON_THROW_ON_ERROR));

        $this->requests = [];
        $again = app(WooCommerceApiSync::class)->run($integration->refresh());
        $this->assertSame(0, $again['emitted']);
        $this->assertSame(['GET /wp-json/wc/v3/orders status=any', 'GET /wp-json/wc/v3/orders status=trash'], $this->requests, 'unchanged refund summaries are not re-read');
    }

    public function test_a_changed_order_gets_the_next_revision_and_late_or_deleted_refunds_are_found(): void
    {
        $this->wcOrder(41, ['payment_method' => 'stripe', 'transaction_id' => 'ch_3Abc', 'total' => '184.000000']);
        $this->wcRefund(41, 51, '30.000000', [], false);
        $integration = $this->connected();
        app(WooCommerceApiSync::class)->run($integration);
        $this->drain();

        $this->travel(2)->seconds();
        $this->wcOrder(41, ['payment_method' => 'stripe', 'transaction_id' => 'ch_3Abc', 'total' => '184.000000', 'status' => 'completed']);
        app(WooCommerceApiSync::class)->run($integration->refresh());
        $this->drain();
        $this->assertSame(['completed', 2], [Order::query()->where('external_id', '41')->value('status'), (int) Order::query()->where('external_id', '41')->value('source_revision')]);

        $this->wcRefund(41, 52, '4.000000', [], false);
        $this->orders[41]['date_modified_gmt'] = now()->subHour()->format('Y-m-d\TH:i:s');
        $this->orders[41]['date_created_gmt'] = now()->subHours(3)->format('Y-m-d\TH:i:s');
        app(WooCommerceApiSync::class)->run($integration->refresh());
        $this->drain();
        $this->assertNull(Refund::query()->where('external_id', '52')->first(), 'the delta does not see a refund that left the order untouched');

        app(WooCommerceApiSync::class)->run($integration->refresh(), WooCommerceApiSync::MODE_RECENT);
        $this->drain();
        $this->assertSame(400, (int) Refund::query()->where('external_id', '52')->value('amount_minor'));

        unset($this->refunds[41][0]);
        $this->orders[41]['refunds'] = [['id' => 52, 'total' => '-4.00']];
        $this->orders[41]['date_modified_gmt'] = now()->format('Y-m-d\TH:i:s');
        app(WooCommerceApiSync::class)->run($integration->refresh());
        $this->drain();
        $this->assertSame('deleted', Refund::query()->where('external_id', '51')->value('status'));
    }

    public function test_reconnecting_the_same_woocommerce_api_keeps_order_identity_and_avoids_reimport(): void
    {
        $this->wcOrder(41, ['payment_method' => 'stripe', 'transaction_id' => 'ch_3Abc', 'total' => '184.000000']);
        $this->wcRefund(41, 51, '30.000000', ['_stripe_refund_id' => 're_3Abc'], true);
        $old = $this->connected();
        app(WooCommerceApiSync::class)->run($old);
        $this->drain();
        $order = Order::query()->where('external_id', '41')->sole();
        $refund = Refund::query()->where('external_id', '51')->sole();
        $old->forceFill(['status' => Integration::STATUS_REVOKED])->save();

        $current = $this->connected();
        $this->assertSame($order->id, Order::query()->where('integration_id', $current->id)->where('external_id', '41')->sole()->id);
        $this->assertSame(1, Order::query()->where('store_id', $this->store->id)->count());
        $this->assertSame($refund->id, Refund::query()->where('integration_id', $current->id)->where('external_id', '51')->sole()->id);

        $result = app(WooCommerceApiSync::class)->run($current);
        $this->drain();
        $this->assertSame(0, $result['emitted']);
        $this->assertSame(1, Order::query()->where('store_id', $this->store->id)->count());
        $this->assertSame(1, Refund::query()->where('store_id', $this->store->id)->count());
    }

    public function test_the_audit_finds_deleted_orders_and_keeps_trashed_ones(): void
    {
        $this->wcOrder(41, ['payment_method' => 'stripe', 'transaction_id' => 'ch_3Abc', 'total' => '184.000000']);
        $this->wcOrder(42, ['payment_method' => 'stripe', 'transaction_id' => 'ch_3Def', 'total' => '10.000000']);
        $this->wcOrder(43, ['payment_method' => 'stripe', 'transaction_id' => 'ch_3Ghi', 'total' => '12.000000']);
        $integration = $this->connected();
        app(WooCommerceApiSync::class)->run($integration);
        $this->drain();

        unset($this->orders[42]);
        $this->orders[43]['status'] = 'trash';
        $result = app(WooCommerceApiSync::class)->run($integration->refresh(), WooCommerceApiSync::MODE_AUDIT);
        $this->drain();

        $this->assertSame(['ok', true], [$result['status'], $result['complete']]);
        $this->assertNotNull(Order::query()->where('external_id', '42')->value('deleted_at'));
        $this->assertNull(Order::query()->where('external_id', '43')->value('deleted_at'));
        $this->assertSame('trash', Order::query()->where('external_id', '43')->value('status'));
        $this->assertNull(Order::query()->where('external_id', '41')->value('deleted_at'));
    }

    public function test_a_rejected_key_stops_polling_and_the_store_data_is_stale_until_a_sync_succeeds(): void
    {
        $integration = $this->connected();
        $freshness = app(ConnectorFreshness::class);
        $this->assertSame(ConnectorFreshness::STATE_WARMING_UP, $freshness->assess($integration)['state']);

        app(WooCommerceApiSync::class)->run($integration);
        $this->assertSame(ConnectorFreshness::STATE_FRESH, $freshness->assess($integration->refresh())['state']);

        $this->travel(31)->minutes();
        $this->assertSame([ConnectorFreshness::STATE_STALE, 'api_sync_failing'], array_values($freshness->assess($integration->refresh())));

        $this->keyValid = false;
        $failed = app(WooCommerceApiSync::class)->run($integration->refresh());
        $this->assertSame(['failed', 'woocommerce_key_rejected'], [$failed['status'], $failed['error']]);
        $integration->refresh();
        $this->assertSame(Integration::STATUS_DEGRADED, $integration->status);
        $this->assertSame([ConnectorFreshness::STATE_STALE, 'api_key_rejected'], array_values($freshness->assess($integration)));

        $this->requests = [];
        $this->assertSame('skipped', app(WooCommerceApiSync::class)->run($integration)['status']);
        $this->assertSame([], $this->requests, 'no more calls with a rejected key');
    }

    public function test_pairing_the_plugin_is_refused_while_the_api_is_the_order_source(): void
    {
        $this->connected();
        $code = 'bwpc_'.str_repeat('a', 40);
        PairingCode::query()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'code_hash' => hash('sha256', $code), 'created_by' => $this->user->id, 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);

        $this->postJson('/api/v1/pairing/exchange', [
            'pairing_code' => $code, 'base_url' => 'https://shop.example.test', 'install_id' => (string) Str::uuid(),
            'connector_code' => 'woocommerce', 'plugin_version' => '0.2.0', 'platform' => ['wordpress' => '6.8', 'woocommerce' => '9.9', 'php' => '8.3'],
        ])->assertStatus(409)->assertJsonPath('code', 'order_source_already_connected');
    }

    public function test_without_a_reported_mode_a_test_mode_provider_cannot_check_the_money_and_says_the_plugin_is_needed(): void
    {
        $this->wcOrder(41, ['payment_method' => 'stripe', 'transaction_id' => 'ch_3Abc', 'total' => '184.000000']);
        $this->wcOrder(42, ['payment_method' => 'ppcp-gateway', 'transaction_id' => '5TR80192ED162870P', 'currency' => 'USD', 'total' => '10.000000', 'meta' => ['_ppcp_paypal_payment_mode' => 'sandbox']]);
        $integration = $this->connected();
        app(WooCommerceApiSync::class)->run($integration);
        $this->drain();
        $stripeOrder = Order::query()->where('external_id', '41')->firstOrFail();
        $this->assertSame(['live', false], [$stripeOrder->mode, app(OrderDto::class)->toArray($stripeOrder)['mode_reported']]);
        $this->assertTrue(app(OrderDto::class)->toArray(Order::query()->where('external_id', '42')->firstOrFail())['mode_reported']);

        $stripe = Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'stripe', 'mode' => 'test',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => '1', 'health' => [],
        ]);
        $finding = app(OrderReconciliationService::class)->evaluate($stripeOrder)->findings()->sole();
        $this->assertSame(['unknown', 'payment_mode_needs_plugin'], [$finding->status, $finding->reason_code]);

        $stripe->forceFill(['mode' => 'live'])->save();
        $live = app(OrderReconciliationService::class)->evaluate($stripeOrder->refresh())->findings()->pluck('reason_code')->all();
        $this->assertNotContains('payment_mode_needs_plugin', $live, 'a live provider checks the order as usual');
    }

    public function test_a_working_read_key_on_the_stores_own_origin_confirms_the_store(): void
    {
        $this->assertNull($this->store->verified_at);
        $this->connected();

        $store = $this->store->refresh();
        $this->assertNotNull($store->verified_at);
        $this->assertSame('active', $store->status);
        $verification = StoreVerification::query()->where('store_id', $store->id)->sole();
        $this->assertSame([StoreVerification::METHOD_API_KEY, 'verified', 'https://shop.example.test'], [$verification->method, $verification->status, $verification->verified_origin]);
        $this->assertSame('api_key', AuditLog::query()->where('action', AuditLog::ACTION_STORE_VERIFIED)->sole()->changes['method']);

        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$store->id}/verify", ['method' => 'api_key'])->assertStatus(422);
    }

    public function test_a_store_already_verified_for_its_url_is_not_verified_again(): void
    {
        app(StoreVerificationService::class)->confirmByApiKey($this->store);
        $this->assertNull(app(StoreVerificationService::class)->confirmByApiKey($this->store->refresh()));
        $this->connected();
        $this->assertSame(1, StoreVerification::query()->where('store_id', $this->store->id)->count());
    }

    public function test_amounts_are_converted_as_strings(): void
    {
        $this->assertSame('18400', WooCommerceAmount::toMinor('184.000000', 2));
        $this->assertSame('1500', WooCommerceAmount::toMinor('1500.00', WooCommerceAmount::exponent('JPY')));
        $this->assertSame('3000', WooCommerceAmount::toMinor('-30.00', 2, absolute: true));
        $this->assertNull(WooCommerceAmount::toMinor('-30.00', 2));
        $this->assertNull(WooCommerceAmount::toMinor('19.999', 2));
        $this->assertSame('1234', WooCommerceAmount::toMinor('1.234', WooCommerceAmount::exponent('KWD')));
        $this->assertNull(WooCommerceAmount::toMinor(18.4, 2));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function listOrders(array $query): PromiseInterface
    {
        $status = $query['status'] ?? 'any';
        $rows = array_filter($this->orders, function (array $order) use ($status, $query): bool {
            if ($status === 'trash' ? $order['status'] !== 'trash' : $order['status'] === 'trash') {
                return false;
            }

            if (isset($query['include'])) {
                return in_array((string) $order['id'], explode(',', (string) $query['include']), true);
            }

            if (isset($query['modified_after']) && $order['date_modified_gmt'] <= $query['modified_after']) {
                return false;
            }

            return ! isset($query['after']) || $order['date_created_gmt'] > $query['after'];
        });
        usort($rows, fn (array $a, array $b): int => strcmp($a['date_modified_gmt'], $b['date_modified_gmt']) ?: $a['id'] <=> $b['id']);
        $fields = isset($query['_fields']) ? explode(',', (string) $query['_fields']) : null;
        $rows = array_map(fn (array $order): array => $fields === null ? $order : array_intersect_key($order, array_flip($fields)), $rows);

        return Http::response(array_values($rows), 200, ['X-WP-Total' => (string) count($rows), 'X-WP-TotalPages' => '1']);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function wcOrder(int $id, array $props): void
    {
        $now = now()->format('Y-m-d\TH:i:s');
        $this->orders[$id] = [
            'id' => $id,
            'number' => (string) $id,
            'status' => $props['status'] ?? 'processing',
            'currency' => $props['currency'] ?? 'EUR',
            'total' => $props['total'],
            'payment_method' => $props['payment_method'],
            'payment_method_title' => $props['payment_method_title'] ?? null,
            'transaction_id' => $props['transaction_id'] ?? '',
            'order_key' => 'wc_order_'.$id.'abcdef',
            'date_created_gmt' => $this->orders[$id]['date_created_gmt'] ?? now()->subHour()->format('Y-m-d\TH:i:s'),
            'date_modified_gmt' => $now,
            'date_paid_gmt' => array_key_exists('date_paid_gmt', $props) ? $props['date_paid_gmt'] : now()->subHour()->format('Y-m-d\TH:i:s'),
            'billing' => ['first_name' => 'Private', 'email' => 'buyer@example.test'],
            'refunds' => $this->orders[$id]['refunds'] ?? [],
            'meta_data' => array_map(fn (string $key, string $value): array => ['id' => crc32($key), 'key' => $key, 'value' => $value], array_keys($props['meta'] ?? []), array_values($props['meta'] ?? [])),
        ];
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function wcRefund(int $orderId, int $refundId, string $amount, array $meta, bool $refundedPayment): void
    {
        $this->refunds[$orderId][] = [
            'id' => $refundId,
            'amount' => $amount,
            'date_created_gmt' => now()->format('Y-m-d\TH:i:s'),
            'refunded_payment' => $refundedPayment,
            'reason' => 'Customer',
            'meta_data' => array_map(fn (string $key, string $value): array => ['id' => crc32($key), 'key' => $key, 'value' => $value], array_keys($meta), array_values($meta)),
        ];
        $this->orders[$orderId]['refunds'][] = ['id' => $refundId, 'total' => '-'.$amount];
        $this->orders[$orderId]['date_modified_gmt'] = now()->format('Y-m-d\TH:i:s');
    }

    private function connect(array $body = []): TestResponse
    {
        return $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/woocommerce-api", [...['consumer_key' => self::KEY, 'consumer_secret' => self::SECRET], ...$body]);
    }

    private function connected(): Integration
    {
        return Integration::query()->findOrFail($this->connect()->assertCreated()->json('id'));
    }

    private function drain(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(DomainOutboxDispatcher::class)->dispatchDue(limit: 100, leaseSeconds: 60);
        }
    }
}
