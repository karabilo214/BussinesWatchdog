<?php

namespace Tests\Feature\Providers;

use App\Http\Dto\Orders\OrderDto;
use App\Models\EventInbox;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\ProviderCoverage;
use App\Support\Network\DnsClient;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Providers\PayPal\PayPalOrderMapper;
use App\Support\Providers\ProviderEventEmitter;
use App\Support\Providers\Shopware\ShopwareClient;
use App\Support\Providers\Shopware\ShopwareOrderMapper;
use App\Support\Providers\Shopware\ShopwareSync;
use App\Support\Providers\Stripe\StripeEventMapper;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Stores\FakeDnsClient;
use Tests\TestCase;

class ShopwareIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS_KEY = 'SWIAQWERTYUIOPASDFGHJKLZXC';

    private const SECRET = 'S3cretAccessKeyForShopwareIntegrationTestsAbcdefgh123';

    private User $user;

    private Tenant $tenant;

    private Store $store;

    private bool $keyValid = true;

    private bool $orderRead = true;

    /** @var array<string, array<string, mixed>> */
    private array $orders = [];

    /** @var list<array{path: string, body: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['watchdog.shopware.request_pause_ms' => 0, 'watchdog.shopware.dev_endpoint' => '']);
        $dns = new FakeDnsClient;
        $dns->ips['shop.example.test'] = ['93.184.216.34'];
        $this->app->instance(DnsClient::class, $dns);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'de']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $body = json_decode($request->body(), true) ?? [];
            $this->requests[] = ['path' => $request->method().' '.$path, 'body' => $body];

            if ($path === '/api/oauth/token') {
                return $this->keyValid && ($body['client_id'] ?? null) === self::ACCESS_KEY && ($body['client_secret'] ?? null) === self::SECRET
                    ? Http::response(['token_type' => 'Bearer', 'expires_in' => 600, 'access_token' => 'sw-token'])
                    : Http::response(['errors' => [['code' => '6', 'title' => 'Unauthorized']]], 401);
            }

            if (! $this->orderRead) {
                return Http::response(['errors' => [['status' => '403', 'code' => 'FRAMEWORK__MISSING_PRIVILEGE_ERROR']]], 403);
            }

            if ($path === '/api/search/order') {
                $rows = array_values($this->orders);
                $limit = (int) ($body['limit'] ?? 100);
                $page = (int) ($body['page'] ?? 1);

                return Http::response(['total' => count($rows), 'data' => array_slice($rows, ($page - 1) * $limit, $limit)]);
            }

            if ($path === '/api/search-ids/order') {
                $ids = array_values(array_intersect((array) ($body['ids'] ?? []), array_keys($this->orders)));

                return Http::response(['total' => count($ids), 'data' => $ids]);
            }

            return Http::response(['errors' => [['code' => 'FRAMEWORK__ROUTE_NOT_FOUND']]], 404);
        });
    }

    public function test_connecting_needs_the_risk_acknowledged_a_working_key_and_order_reading_and_confirms_the_store(): void
    {
        $this->connect(['state_change_risk_acknowledged' => false])->assertStatus(422)->assertJsonPath('code', 'shopware_state_change_risk_not_acknowledged');
        $this->assertSame([], $this->requests, 'nothing is sent before the owner acknowledged the warning');
        $this->connect(['access_key' => 'nope'])->assertStatus(422)->assertJsonPath('code', 'shopware_key_invalid');

        $this->keyValid = false;
        $this->connect()->assertStatus(422)->assertJsonPath('code', 'shopware_key_rejected');
        $this->keyValid = true;
        $this->orderRead = false;
        $this->connect()->assertStatus(422)->assertJsonPath('code', 'shopware_order_read_missing');
        $this->orderRead = true;

        $response = $this->connect()->assertCreated()->assertJsonPath('provider', 'shopware');
        $integration = Integration::query()->findOrFail($response->json('id'));
        $this->assertSame([Integration::SOURCE_STORE_REPORTED, 'state_only'], [$integration->source_authority, $integration->capabilities['refunds']]);
        $this->assertNotNull($integration->health['state_change_risk_acknowledged_at']);
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, (string) IntegrationCredential::query()->where('kind', IntegrationCredential::KIND_SHOPWARE_INTEGRATION)->value('ciphertext'));
        $this->assertSame(StoreVerification::METHOD_API_KEY, StoreVerification::query()->where('store_id', $this->store->id)->sole()->method);
        $this->assertNotNull($this->store->refresh()->verified_at);
        $this->connect()->assertStatus(409)->assertJsonPath('code', 'provider_already_connected');

        foreach ($this->requests as $request) {
            $this->assertContains($request['path'], ['POST /api/oauth/token', 'POST /api/search/order'], 'only the token and order searches are sent');
        }

        $this->assertFalse(ShopwareClient::allowed('POST', '/api/_action/order/abc/state/cancel'));
        $this->assertFalse(ShopwareClient::allowed('PATCH', '/api/order/abc'));
        $this->assertTrue(ShopwareClient::allowed('POST', '/api/search-ids/order'));
    }

    public function test_orders_are_read_without_customer_data_and_become_order_snapshots(): void
    {
        $this->swOrder('a1', '10001', 39.98, 'EUR', 'open', [$this->transaction('t1', 'paid', 'swag_paypal_paypal', ['swag_paypal_resource_id' => 'CAPTURE00000001X', 'swag_paypal_order_id' => 'ORDER0000000001X', 'swag_paypal_is_sandbox' => true])]);
        $this->swOrder('a2', '10002', 19.99, 'EUR', 'open', [$this->transaction('t2', 'open', 'payment_prepayment')]);
        $this->swOrder('a3', '10003', 1500.0, 'JPY', 'completed', [$this->transaction('t3', 'cancelled', 'swag_paypal_paypal'), $this->transaction('t4', 'paid', 'payment_invoicepayment', [], '+1 minute')]);
        $this->swOrder('a4', '10004', 19.999, 'EUR', 'open', [$this->transaction('t5', 'open', 'payment_prepayment')]);
        $integration = $this->connected();

        $result = app(ShopwareSync::class)->run($integration);
        $this->drain();

        $this->assertSame('ok', $result['status']);
        $paypal = Order::query()->where('external_id', $this->id('a1'))->firstOrFail();
        $this->assertSame(['open', 'EUR', 3998, 'swag_paypal_paypal', 'CAPTURE00000001X', 'test', 'supported', '10001', 'none'], [$paypal->status, $paypal->currency, (int) $paypal->total_minor, $paypal->gateway, $paypal->transaction_ref, $paypal->mode, $paypal->financial_support, $paypal->display_number, $paypal->metadata['refund_state']]);
        $this->assertNotNull($paypal->paid_marked_at);
        $this->assertSame(['paid', 'open'], [$paypal->metadata['payment_state'], Order::query()->where('external_id', $this->id('a2'))->firstOrFail()->metadata['payment_state']], 'the store payment state is normalized');
        $this->assertSame($this->id('t1'), $paypal->metadata['store_transaction_ref'], 'the effective transaction id is kept to match SwagPayPal payments');
        $this->assertSame(['unsupported', null], [Order::query()->where('external_id', $this->id('a2'))->value('financial_support'), Order::query()->where('external_id', $this->id('a2'))->value('paid_marked_at')]);
        $yen = Order::query()->where('external_id', $this->id('a3'))->firstOrFail();
        $this->assertSame([1500, 'payment_invoicepayment'], [(int) $yen->total_minor, $yen->gateway], 'the cancelled transaction is skipped for the effective one');
        $this->assertNull(Order::query()->where('external_id', $this->id('a4'))->first());
        $this->assertSame(['amount_not_representable' => 1], $integration->refresh()->health['sync']['skipped']);

        $search = collect($this->requests)->first(fn (array $request): bool => $request['path'] === 'POST /api/search/order' && isset($request['body']['filter']))['body'];
        $this->assertNotContains('orderCustomer', $search['includes']['order']);
        $this->assertArrayNotHasKey('orderCustomer', $search['associations']);
        $this->assertSame('transactions.updatedAt', $search['filter'][0]['queries'][2]['field'], 'payment state changes only touch the transaction');
        $this->assertStringNotContainsString('@', json_encode(EventInbox::query()->pluck('payload'), JSON_THROW_ON_ERROR));

        $again = app(ShopwareSync::class)->run($integration->refresh());
        $this->assertSame(0, $again['emitted']);
    }

    public function test_the_audit_finds_deleted_orders(): void
    {
        $this->swOrder('a1', '10001', 10.0, 'EUR', 'open', [$this->transaction('t1', 'open', 'payment_prepayment')]);
        $this->swOrder('a2', '10002', 12.0, 'EUR', 'open', [$this->transaction('t2', 'open', 'payment_prepayment')]);
        $integration = $this->connected();
        app(ShopwareSync::class)->run($integration);
        $this->drain();

        unset($this->orders[$this->id('a2')]);
        $result = app(ShopwareSync::class)->run($integration->refresh(), ShopwareSync::MODE_AUDIT);
        $this->drain();

        $this->assertSame(['ok', true], [$result['status'], $result['complete']]);
        $this->assertNotNull(Order::query()->where('external_id', $this->id('a2'))->value('deleted_at'));
        $this->assertNull(Order::query()->where('external_id', $this->id('a1'))->value('deleted_at'));
    }

    public function test_refunds_reported_only_as_a_payment_state_are_checked_against_the_provider(): void
    {
        $integration = $this->connected();
        $paypal = Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'paypal', 'mode' => 'test',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => 'paypal-adapter-1', 'health' => [],
        ]);
        $cases = [
            'full_ok' => ['refunded', ['40.00'], 'ok', 'refund_reconciled_ok', '4000'],
            'full_short' => ['refunded', ['10.00'], 'mismatch', 'refund_missing_store_state_full', '1000'],
            'partial' => ['refunded_partially', ['15.00'], 'unknown', 'refund_amount_not_reported', '1500'],
            'partial_none' => ['refunded_partially', [], 'mismatch', 'refund_missing_store_state_partial', '0'],
            'none_extra' => ['paid', ['5.00'], 'mismatch', 'refund_extra_store_state_none', '500'],
            'none_clean' => ['paid', [], null, null, null],
        ];
        $i = 0;

        foreach ($cases as $name => [$state, $refunds]) {
            $i++;
            $capture = sprintf('CAPTURE%09dX', $i);
            $this->swOrder($name, (string) (20000 + $i), 40.0, 'EUR', 'open', [$this->transaction('t'.$i, $state, 'swag_paypal_paypal', ['swag_paypal_resource_id' => $capture, 'swag_paypal_is_sandbox' => true], '-3 hours')], '-3 hours');
            $paypalOrder = ['id' => sprintf('PPORDER%09dX', $i), 'status' => 'COMPLETED', 'intent' => 'CAPTURE', 'create_time' => now()->subHours(3)->toIso8601ZuluString(),
                'purchase_units' => [['custom_id' => (string) $i, 'amount' => ['currency_code' => 'EUR', 'value' => '40.00'], 'payments' => array_filter([
                    'captures' => [['id' => $capture, 'status' => $refunds === [] ? 'COMPLETED' : 'PARTIALLY_REFUNDED', 'amount' => ['currency_code' => 'EUR', 'value' => '40.00'], 'create_time' => now()->subHours(3)->toIso8601ZuluString()]],
                    'refunds' => array_map(fn (string $value, int $k): array => ['id' => sprintf('REFUND%03d%07dX', $i, $k), 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => $value], 'create_time' => now()->subHours(2)->toIso8601ZuluString()], $refunds, array_keys($refunds)),
                ])]]];
            app(ProviderEventEmitter::class)->emit($paypal, app(PayPalOrderMapper::class)->map($paypal, $paypalOrder)['drafts']);
        }

        app(ShopwareSync::class)->run($integration);
        $this->drain();

        foreach ($cases as $name => [, , $status, $reason, $actual]) {
            $order = Order::query()->where('external_id', $this->id($name))->firstOrFail();
            app(OrderReconciliationService::class)->evaluate($order);
            $finding = ReconciliationFinding::query()->where('order_id', $order->id)->whereIn('rule_code', [ReconciliationFinding::RULE_REFUND_MISSING, ReconciliationFinding::RULE_REFUND_EXTRA])->latest('evaluated_at')->first();

            if ($status === null) {
                $this->assertNull($finding, $name);

                continue;
            }

            $this->assertSame([$status, $reason, $actual], [$finding->status, $finding->reason_code, (string) $finding->refund_actual_minor], $name);
        }

        $partial = ReconciliationFinding::query()->where('reason_code', 'refund_amount_not_reported')->sole();
        $this->assertEquals(['refund_state' => 'partial', 'captured_minor' => '4000', 'provider_refunded_minor' => '1500', 'capture_operation_ids' => ['CAPTURE000000003X']], $partial->evidence, 'the provider refunded amount is shown for a partial refund');
    }

    public function test_stripe_payment_plugin_orders_link_both_ways_take_the_payment_mode_and_refunds_only_at_stripe_are_information(): void
    {
        $integration = $this->connected();
        $stripe = Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'stripe', 'mode' => 'test',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => 'stripe-adapter-1', 'health' => [],
        ]);
        $card = 'stripe_shopware_payment_payment_handler_card';
        $context = fn (string $intent): array => ['stripe_payment_context' => ['payment' => ['payment_intent_id' => $intent, 'charge_id' => 'ch_'.substr($intent, 3)]]];
        $this->swOrder('card', '10006', 40.0, 'EUR', 'open', [$this->transaction('t_card', 'paid', $card, $context('pi_card0000000001'), '-3 hours')], '-3 hours');
        $this->swOrder('declined', '10009', 40.0, 'EUR', 'open', [$this->transaction('t_declined', 'failed', $card, [], '-3 hours')], '-3 hours');
        $this->swOrder('stripe_refund', '10011', 40.0, 'EUR', 'open', [$this->transaction('t_stripe_refund', 'paid', $card, $context('pi_refund00000001'), '-3 hours')], '-3 hours');
        $this->swOrder('shop_refund', '10012', 40.0, 'EUR', 'open', [$this->transaction('t_shop_refund', 'refunded', $card, $context('pi_shoprefund0001'), '-3 hours')], '-3 hours');
        $this->swOrder('metadata_only', '10013', 40.0, 'EUR', 'open', [$this->transaction('t_metadata_only', 'paid', 'stripe_shopware_payment_payment_handler_sepa', [], '-3 hours')], '-3 hours');
        $this->swOrder('other_app', '10014', 40.0, 'EUR', 'open', [$this->transaction('t_other_app', 'paid', 'stripe_card', ['stripe_payment_intent' => 'pi_other0000000001'], '-3 hours')], '-3 hours');

        $created = now()->subHours(3)->getTimestamp();
        $payments = ['pi_card0000000001' => 't_card', 'pi_refund00000001' => 't_stripe_refund', 'pi_shoprefund0001' => 't_shop_refund', 'pi_metadata000001' => 't_metadata_only'];

        foreach ($payments as $intent => $transaction) {
            $metadata = ['platform_name' => 'UMXJ4nBknsWR3LN_shopware_v50', 'shopware_order_transaction_id' => $this->id($transaction)];
            $charge = 'ch_'.substr($intent, 3);
            $objects = [
                ['id' => $intent, 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 4000, 'currency' => 'eur', 'livemode' => false, 'latest_charge' => $charge, 'created' => $created, 'metadata' => $metadata, 'description' => 'buyer@example.test / Customer 1 / Order 1'],
                ['id' => $charge, 'object' => 'charge', 'status' => 'succeeded', 'captured' => true, 'amount' => 4000, 'amount_captured' => 4000, 'currency' => 'eur', 'livemode' => false, 'payment_intent' => $intent, 'created' => $created, 'metadata' => $metadata],
            ];

            foreach ($objects as $object) {
                app(ProviderEventEmitter::class)->emit($stripe, app(StripeEventMapper::class)->map($stripe, $object)['drafts']);
            }
        }

        $declined = ['id' => 'pi_declined000001', 'object' => 'payment_intent', 'status' => 'requires_payment_method', 'last_payment_error' => ['code' => 'card_declined'], 'amount' => 4000, 'currency' => 'eur', 'livemode' => false, 'created' => $created, 'metadata' => ['shopware_order_transaction_id' => $this->id('t_declined')]];
        app(ProviderEventEmitter::class)->emit($stripe, app(StripeEventMapper::class)->map($stripe, $declined)['drafts']);
        $refund = ['id' => 're_refund00000001', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'eur', 'livemode' => false, 'payment_intent' => 'pi_refund00000001', 'charge' => 'ch_refund00000001', 'created' => now()->subHours(2)->getTimestamp()];
        app(ProviderEventEmitter::class)->emit($stripe, app(StripeEventMapper::class)->map($stripe, $refund)['drafts']);

        app(ShopwareSync::class)->run($integration);
        $this->drain();

        $order = fn (string $name): Order => Order::query()->where('external_id', $this->id($name))->firstOrFail();
        $this->assertSame(['pi_card0000000001', 'supported', false, false], [$order('card')->transaction_ref, $order('card')->financial_support, $order('card')->metadata['mode_reported'], $order('card')->metadata['provider_refunds_synced']], 'the plugin\'s PaymentIntent id is the reference; Shopware reports no mode and no refunds');
        $this->assertSame(['unsupported', null], [$order('other_app')->financial_support, $order('other_app')->transaction_ref], 'another Stripe app for Shopware leaves no known reference');
        $this->assertSame($this->id('t_card'), Payment::query()->where('intent_ref', 'pi_card0000000001')->sole()->metadata['provider_store_transaction_ref']);
        $this->assertStringNotContainsString('@', json_encode(EventInbox::query()->pluck('payload'), JSON_THROW_ON_ERROR), 'the PaymentIntent description with the customer email is never read');

        $strategy = fn (string $name): ?string => PaymentAllocation::query()->where('order_id', $order($name)->id)->whereNull('revoked_at')->value('strategy');
        $this->assertSame(PaymentAllocation::STRATEGY_EXACT_REFERENCE, $strategy('card'), 'linked by the PaymentIntent id in a test-mode account although the order had no mode');
        $this->assertSame(PaymentAllocation::STRATEGY_VERIFIED_METADATA, $strategy('metadata_only'), 'linked by the Shopware transaction id the plugin gave Stripe');
        $this->assertSame(['test', true], [app(OrderDto::class)->toArray($order('card'))['mode'], app(OrderDto::class)->toArray($order('card'))['mode_reported']], 'the cabinet shows the linked payment\'s mode');
        $this->assertSame(['live', false], [app(OrderDto::class)->toArray($order('declined'))['mode'], app(OrderDto::class)->toArray($order('declined'))['mode_reported']], 'an unlinked order keeps an unknown mode');

        foreach (['card', 'declined', 'stripe_refund', 'shop_refund', 'metadata_only', 'other_app'] as $name) {
            app(OrderReconciliationService::class)->evaluate($order($name));
        }

        $findings = fn (string $name): array => ReconciliationFinding::query()->where('order_id', $order($name)->id)->get()->map(fn (ReconciliationFinding $finding): string => $finding->rule_code.':'.$finding->status.':'.$finding->reason_code)->sort()->values()->all();
        $this->assertNotContains('MONEY_UNSUPPORTED:unknown:'.ProviderCoverage::REASON_MODE_NEEDS_PLUGIN, $findings('card'));
        $this->assertSame([], array_values(array_filter($findings('card'), fn (string $finding): bool => ! str_contains($finding, ':ok:'))), 'a paid card order is all ok');
        $this->assertSame([], array_values(array_filter($findings('metadata_only'), fn (string $finding): bool => ! str_contains($finding, ':ok:'))));
        $this->assertContains('MONEY_REFUND_EXTRA:unsupported:refund_not_synced_by_payment_app', $findings('stripe_refund'), 'a refund only at Stripe is information: the plugin never syncs refunds');
        $this->assertContains('MONEY_REFUND_MISSING:mismatch:refund_missing_store_state_full', $findings('shop_refund'), 'refunded in Shopware without a Stripe refund: the money was not returned');
        $this->assertSame(['MONEY_UNSUPPORTED:unsupported:gateway_unsupported'], $findings('other_app'));
        $this->assertSame([], array_values(array_filter($findings('declined'), fn (string $finding): bool => str_contains($finding, ':mismatch:'))), 'a declined card is not a discrepancy');
    }

    public function test_float_amounts_are_converted_only_when_exact(): void
    {
        $this->assertSame('1999', ShopwareOrderMapper::minor(19.99, 2));
        $this->assertSame('1500', ShopwareOrderMapper::minor(1500.0, 0));
        $this->assertSame('1500', ShopwareOrderMapper::minor(1500, 0));
        $this->assertSame('123457', ShopwareOrderMapper::minor(1234.57, 2));
        $this->assertNull(ShopwareOrderMapper::minor(19.999, 2));
        $this->assertNull(ShopwareOrderMapper::minor(-1.0, 2));
        $this->assertNull(ShopwareOrderMapper::minor('19.99', 2));
    }

    private function id(string $name): string
    {
        return substr(hash('sha256', $name), 0, 32);
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     */
    private function swOrder(string $name, string $number, float $total, string $currency, string $state, array $transactions, string $created = '-1 hour'): void
    {
        $this->orders[$this->id($name)] = [
            'id' => $this->id($name), 'orderNumber' => $number, 'amountTotal' => $total,
            'currency' => ['isoCode' => $currency], 'stateMachineState' => ['technicalName' => $state],
            'transactions' => $transactions, 'createdAt' => now()->modify($created)->format('Y-m-d\TH:i:s.vP'), 'updatedAt' => null,
            'orderCustomer' => ['email' => 'buyer@example.test'],
        ];
    }

    /**
     * @param  array<string, mixed>  $custom
     * @return array<string, mixed>
     */
    private function transaction(string $id, string $state, string $method, array $custom = [], string $created = '-1 hour'): array
    {
        return [
            'id' => $this->id($id), 'amount' => ['totalPrice' => 40.0], 'stateMachineState' => ['technicalName' => $state],
            'paymentMethod' => ['technicalName' => $method], 'customFields' => $custom === [] ? null : $custom,
            'createdAt' => now()->modify($created)->format('Y-m-d\TH:i:s.vP'), 'updatedAt' => $state === 'open' ? null : now()->modify($created)->addMinutes(5)->format('Y-m-d\TH:i:s.vP'),
        ];
    }

    private function connect(array $body = []): TestResponse
    {
        return $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/shopware", [...['access_key' => self::ACCESS_KEY, 'secret_access_key' => self::SECRET, 'state_change_risk_acknowledged' => true], ...$body]);
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
