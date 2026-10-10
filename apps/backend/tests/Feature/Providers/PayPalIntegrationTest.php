<?php

namespace Tests\Feature\Providers;

use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Providers\PayPal\PayPalClient;
use App\Support\Providers\PayPal\PayPalCurrency;
use App\Support\Providers\PayPal\PayPalRequestFailed;
use App\Support\Providers\PayPal\PayPalSync;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayPalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPp0123456789_-sandboxClientIdForTests';

    private const CLIENT_SECRET = 'EeFfGgHhIiJjKkLlMmNnOoPpQqRrSsTt0123456789_-sandboxSecretForTests';

    private const SCOPES = 'https://uri.paypal.com/services/reporting/search/read https://uri.paypal.com/services/payments/refund https://uri.paypal.com/services/payments/payment/authcapture https://api.paypal.com/v1/payments/.* openid';

    private User $user;

    private Tenant $tenant;

    private Store $store;

    private Integration $woo;

    private string $scopes = self::SCOPES;

    /** @var array<string, array<string, mixed>> */
    private array $orders = [];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private ?string $refreshedAt = null;

    private bool $webhookValid = true;

    private int $tokenStatus = 200;

    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['watchdog.paypal.api_base_sandbox' => 'https://paypal.test', 'watchdog.paypal.api_base_live' => 'https://paypal-live.test']);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'USD']);
        $this->woo = Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'woocommerce', 'install_id' => (string) Str::uuid(),
            'mode' => 'live', 'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [], 'connector_version' => '1.0.0', 'health' => ['freshness' => ['state' => 'fresh']], 'last_heartbeat_at' => now(),
        ]);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->requests[] = $request->method().' '.$path;

            if ($path === '/v1/oauth2/token') {
                return $this->tokenStatus === 200
                    ? Http::response(['access_token' => 'A21-test-token', 'token_type' => 'Bearer', 'expires_in' => 32400, 'app_id' => 'APP-TEST123', 'scope' => $this->scopes])
                    : Http::response(['error' => 'invalid_client'], $this->tokenStatus);
            }

            if ($path === '/v1/notifications/verify-webhook-signature') {
                return Http::response(['verification_status' => $this->webhookValid ? 'SUCCESS' : 'FAILURE']);
            }

            if ($path === '/v1/reporting/transactions') {
                if (! str_contains($this->scopes, 'reporting/search/read')) {
                    return Http::response(['name' => 'NOT_AUTHORIZED'], 403);
                }

                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $rows = array_values(array_filter($this->rows, fn (array $row): bool => $row['transaction_info']['transaction_initiation_date'] >= $query['start_date']
                    && $row['transaction_info']['transaction_initiation_date'] <= $query['end_date']));

                return Http::response(['transaction_details' => $rows, 'total_pages' => 1, 'page' => 1, 'last_refreshed_datetime' => $this->refreshedAt ?? now()->toIso8601ZuluString()]);
            }

            if (preg_match('#^/v2/payments/captures/([A-Z0-9]+)$#', $path, $match) === 1) {
                foreach ($this->orders as $order) {
                    foreach ($order['purchase_units'][0]['payments']['captures'] ?? [] as $capture) {
                        if ($capture['id'] === $match[1]) {
                            return Http::response([...$capture, 'links' => [['rel' => 'up', 'href' => 'https://paypal.test/v2/checkout/orders/'.$order['id']]]]);
                        }
                    }
                }

                return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
            }

            if (preg_match('#^/v2/checkout/orders/([A-Z0-9]+)$#', $path, $match) === 1) {
                return isset($this->orders[$match[1]]) ? Http::response($this->orders[$match[1]]) : Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
            }

            return Http::response(['name' => 'UNEXPECTED'], 500);
        });
    }

    public function test_connecting_needs_the_write_access_warning_acknowledged_and_transaction_search(): void
    {
        $this->connect(['write_access_acknowledged' => false])->assertStatus(422)->assertJsonPath('code', 'paypal_write_access_not_acknowledged');
        $this->assertSame([], $this->requests, 'nothing is sent to PayPal before the owner acknowledged the warning');

        $this->scopes = 'https://uri.paypal.com/services/payments/refund openid';
        $this->connect()->assertStatus(422)->assertJsonPath('code', 'paypal_transaction_search_missing');

        $this->scopes = self::SCOPES;
        $response = $this->connect()->assertCreated()->assertJsonPath('provider', 'paypal')->assertJsonPath('mode', 'test');
        $integration = Integration::query()->findOrFail($response->json('id'));

        $this->assertSame(['capture', 'payments_v1', 'refund'], $integration->health['write_scopes']);
        $this->assertSame(Integration::SOURCE_INDEPENDENT_PROVIDER, $integration->source_authority);
        $this->assertStringNotContainsString(self::CLIENT_SECRET, $response->getContent());
        $this->assertStringNotContainsString(self::CLIENT_SECRET, (string) IntegrationCredential::query()->where('kind', IntegrationCredential::KIND_PAYPAL_CLIENT)->value('ciphertext'));
        $this->assertStringNotContainsString(self::CLIENT_SECRET, json_encode(AuditLog::query()->pluck('changes'), JSON_THROW_ON_ERROR));
        $this->assertStringEndsWith('/api/v1/webhooks/paypal/'.$integration->id, $response->json('webhook_url'));
        $this->connect()->assertStatus(409)->assertJsonPath('code', 'provider_already_connected');
    }

    public function test_a_plugin_order_is_linked_by_its_capture_and_a_store_refund_by_the_unique_amount(): void
    {
        $order = $this->order('5TR80192ED162870P', 1000, '41');
        $storeRefund = $this->storeRefund($order, 300);
        $this->paypalOrder('56072027MF355241N', '41', [$this->capture('5TR80192ED162870P', 'PARTIALLY_REFUNDED', '10.00')], [$this->refundObject('7BN46482F9200004D', 'COMPLETED', '3.00')]);
        $this->row('5TR80192ED162870P', 'T0006');
        $this->row('7BN46482F9200004D', 'T1107', '5TR80192ED162870P');
        $integration = $this->connected();

        $result = app(PayPalSync::class)->run($integration);
        $this->drain();

        $this->assertSame('ok', $result['status']);
        $payment = Payment::query()->where('external_id', '56072027MF355241N')->firstOrFail();
        $this->assertSame(['captured', '56072027MF355241N', '5TR80192ED162870P', 'test'], [$payment->status, $payment->intent_ref, $payment->charge_ref, $payment->mode]);
        $this->assertSame(['provider_order_ref' => '41'], $payment->metadata);
        $this->assertSame([PaymentAllocation::STRATEGY_EXACT_REFERENCE, 1000], [PaymentAllocation::query()->value('strategy'), (int) PaymentAllocation::query()->value('amount_minor')]);
        $refundLink = RefundAllocation::query()->where('refund_id', $storeRefund->id)->firstOrFail();
        $this->assertSame([PaymentAllocation::STRATEGY_UNIQUE_AMOUNT, 300], [$refundLink->strategy, (int) $refundLink->amount_minor]);
        $this->assertSame('unique_amount_v1', $refundLink->evidence['matcher']);

        app(OrderReconciliationService::class)->evaluate($order->refresh());
        $statuses = ReconciliationFinding::query()->where('order_id', $order->id)->pluck('status', 'rule_code')->all();
        $this->assertSame('ok', $statuses[ReconciliationFinding::RULE_CAPTURE_AMOUNT] ?? null);
        $this->assertNotContains('mismatch', $statuses);

        $again = app(PayPalSync::class)->run($integration->refresh());
        $this->assertSame(0, $again['emitted']);
    }

    public function test_a_store_refund_that_arrives_after_the_paypal_refund_is_linked_by_the_unique_amount(): void
    {
        $order = $this->order('CAP0000000000005R', 1000, '55');
        $this->paypalOrder('ORD0000000000005R', '55', [$this->capture('CAP0000000000005R', 'PARTIALLY_REFUNDED', '10.00')], [$this->refundObject('REF0000000000005R', 'COMPLETED', '4.00')]);
        $integration = $this->connected();
        app(PayPalSync::class)->syncOrder($integration, 'ORD0000000000005R');
        $this->drain();
        $this->assertSame(0, RefundAllocation::query()->count());

        app(\App\Support\Ingest\EventIngestor::class)->ingest($this->woo, [[
            'schema_version' => '1.0', 'event_id' => (string) Str::uuid(), 'type' => 'refund.snapshot', 'aggregate_type' => 'refund', 'aggregate_id' => 'wc-refund-55',
            'aggregate_revision' => 1, 'occurred_at' => now()->toJSON(), 'observed_at' => now()->toJSON(), 'is_synthetic' => false,
            'data' => ['order_id' => '55', 'currency' => 'USD', 'currency_exponent' => 2, 'amount_minor' => '400', 'external_required' => true, 'provider_ref' => null, 'status' => 'recorded'],
        ]], null, (string) Str::uuid());
        $this->drain();

        $link = RefundAllocation::query()->firstOrFail();
        $this->assertSame([PaymentAllocation::STRATEGY_UNIQUE_AMOUNT, 400, $order->id], [$link->strategy, (int) $link->amount_minor, Refund::query()->whereKey($link->refund_id)->value('order_id')]);
    }

    public function test_a_completed_paypal_order_with_a_pending_capture_is_not_money_and_authorizations_are_not_captures(): void
    {
        $this->paypalOrder('66R898720L175282K', '44', [$this->capture('8FD32502RN560521G', 'PENDING', '10.00')], [], 'COMPLETED');
        $this->paypalOrder('97A82844CG3709233', '45', [], [], 'COMPLETED', [['id' => '0VC2340831108915V', 'status' => 'CREATED', 'amount' => ['currency_code' => 'USD', 'value' => '25.00']]]);
        $integration = $this->connected();

        app(PayPalSync::class)->syncOrder($integration, '66R898720L175282K');
        app(PayPalSync::class)->syncOrder($integration, '97A82844CG3709233');
        $this->drain();

        $this->assertSame('pending', Payment::query()->where('external_id', '66R898720L175282K')->value('status'));
        $authorized = Payment::query()->where('external_id', '97A82844CG3709233')->firstOrFail();
        $this->assertSame(['authorized', '0VC2340831108915V'], [$authorized->status, $authorized->charge_ref]);
        $this->assertSame(0, FinancialTransaction::query()->count());

        $this->orders['66R898720L175282K']['purchase_units'][0]['payments']['captures'][0]['status'] = 'COMPLETED';
        $this->travel(1)->seconds();
        app(PayPalSync::class)->syncOrder($integration, '66R898720L175282K');
        $this->drain();
        $this->assertSame('captured', Payment::query()->where('external_id', '66R898720L175282K')->value('status'));
        $this->assertSame(['1000'], FinancialTransaction::query()->where('kind', 'capture')->pluck('amount_minor')->map(fn ($value) => (string) $value)->all());
    }

    public function test_the_watermark_follows_transaction_search_refresh_and_an_ambiguous_refund_pair_is_not_linked(): void
    {
        $this->refreshedAt = now()->subHours(2)->startOfSecond()->toIso8601ZuluString();
        $order = $this->order('CAP0000000000001A', 1000, '50');
        $this->storeRefund($order, 300);
        $this->storeRefund($order, 300);
        $this->paypalOrder('ORD0000000000001A', '50', [$this->capture('CAP0000000000001A', 'PARTIALLY_REFUNDED', '10.00')], [$this->refundObject('REF0000000000001A', 'COMPLETED', '3.00'), $this->refundObject('REF0000000000002A', 'COMPLETED', '3.00')]);
        $this->row('CAP0000000000001A', 'T0006', null, now()->subHours(3));
        $integration = $this->connected();

        app(PayPalSync::class)->run($integration);
        $this->drain();

        $this->assertSame($this->refreshedAt, \Illuminate\Support\Carbon::parse($integration->refresh()->health['sync']['watermark'])->toIso8601ZuluString());
        $this->assertSame(1, PaymentAllocation::query()->count());
        $this->assertSame(0, RefundAllocation::query()->count(), 'two equal store refunds and two equal provider refunds are left for a person');
    }

    public function test_only_reads_leave_the_service(): void
    {
        $this->paypalOrder('ORD0000000000009Z', '9', [$this->capture('CAP0000000000009Z', 'COMPLETED', '10.00')], []);
        $this->row('CAP0000000000009Z', 'T0006');
        $integration = $this->connected();
        app(PayPalSync::class)->run($integration);
        app(PayPalSync::class)->run($integration->refresh(), PayPalSync::MODE_AUDIT);
        $this->postWebhook($integration, $this->webhookId($integration), ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAP0000000000009Z', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORD0000000000009Z']]]]);

        $this->assertNotEmpty($this->requests);

        foreach ($this->requests as $request) {
            [$method, $path] = explode(' ', $request, 2);
            $this->assertTrue(PayPalClient::allowed($method, $path), "not allowed: {$request}");
        }

        $this->assertFalse(PayPalClient::allowed('POST', '/v2/payments/captures/CAP0000000000009Z/refund'));
        $this->assertFalse(PayPalClient::allowed('POST', '/v2/payments/authorizations/AUTH00000000001/capture'));
        $this->assertFalse(PayPalClient::allowed('GET', '/v2/payments/captures/CAP0000000000009Z/refund'));
        $this->assertFalse(PayPalClient::allowed('PATCH', '/v2/checkout/orders/ORD0000000000009Z'));
        $this->expectException(PayPalRequestFailed::class);
        app(PayPalClient::class)->get('test', 'token', '/v1/payments/payouts');
    }

    public function test_a_webhook_is_only_a_hint_verified_by_paypal_and_the_order_is_read_through_the_api(): void
    {
        $this->paypalOrder('ORD0000000000007W', '7', [$this->capture('CAP0000000000007W', 'COMPLETED', '12.50')], [$this->refundObject('REF0000000000007W', 'COMPLETED', '2.50')]);
        $integration = $this->connected();
        $event = ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => 'REF0000000000007W', 'payer' => ['email_address' => 'buyer@example.test'], 'links' => [['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/CAP0000000000007W']]]];

        $this->postWebhook($integration, null, $event)->assertStatus(409)->assertJsonPath('code', 'webhook_not_configured');
        $webhookId = $this->webhookId($integration);
        $this->webhookValid = false;
        $this->postWebhook($integration, $webhookId, $event)->assertStatus(400)->assertJsonPath('code', 'signature_invalid');
        $this->assertSame(0, Payment::query()->count());

        $this->webhookValid = true;
        $this->postWebhook($integration, $webhookId, $event, now()->subHours(2)->toIso8601ZuluString())->assertStatus(400);
        $this->postWebhook($integration, $webhookId, $event)->assertOk()->assertJsonPath('received', true);
        $this->drain();

        $this->assertSame(['1250', '250'], FinancialTransaction::query()->orderBy('kind')->pluck('amount_minor')->map(fn ($value) => (string) $value)->all());
        $this->assertStringNotContainsString('buyer@example.test', json_encode(\App\Models\EventInbox::query()->pluck('payload'), JSON_THROW_ON_ERROR));
        $this->assertSame('PAYMENT.CAPTURE.REFUNDED', $integration->refresh()->health['webhook']['last_type']);
    }

    public function test_paypal_orders_need_paypal_connected_and_rejected_credentials_degrade_the_integration(): void
    {
        Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'stripe', 'mode' => 'test',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => '1', 'health' => [],
        ]);
        $order = $this->order('CAP0000000000003C', 1000, '3');

        app(OrderReconciliationService::class)->evaluate($order);
        $finding = ReconciliationFinding::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(['unknown', 'provider_not_connected'], [$finding->status, $finding->reason_code]);

        $integration = $this->connected();
        ReconciliationFinding::query()->where('order_id', $order->id)->delete();
        app(OrderReconciliationService::class)->evaluate($order->refresh());
        $this->assertNotContains('provider_not_connected', ReconciliationFinding::query()->where('order_id', $order->id)->pluck('reason_code')->all());

        $this->tokenStatus = 401;
        app()->forgetInstance(PayPalClient::class);
        $result = app(PayPalSync::class)->run($integration);
        $this->assertSame(['failed', 'paypal_credentials_rejected'], [$result['status'], $result['error']]);
        $this->assertSame(Integration::STATUS_DEGRADED, $integration->refresh()->status);
    }

    public function test_amounts_are_converted_as_strings_per_paypal_currency_rules(): void
    {
        $this->assertSame('1050', PayPalCurrency::toMinor('10.5', 2));
        $this->assertSame('123456789012345', PayPalCurrency::toMinor('1234567890123.45', 2));
        $this->assertSame('500', PayPalCurrency::toMinor('500', PayPalCurrency::exponent('JPY')));
        $this->assertNull(PayPalCurrency::toMinor('10.505', 2));
        $this->assertNull(PayPalCurrency::toMinor('-1.00', 2));
        $this->assertNull(PayPalCurrency::toMinor(10.5, 2));
        $this->assertSame(0, PayPalCurrency::exponent('HUF'));
        $this->assertNull(PayPalCurrency::exponent('UAH'));
    }

    private function connect(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/paypal", [...['environment' => 'sandbox', 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET, 'write_access_acknowledged' => true], ...$body]);
    }

    private function connected(): Integration
    {
        $id = $this->connect()->assertCreated()->json('id');

        return Integration::query()->findOrFail($id);
    }

    private function webhookId(Integration $integration): string
    {
        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->putJson("/api/v1/integrations/{$integration->id}/webhook-id", ['webhook_id' => 'WH1234567890ABCDEF'])->assertOk();

        return 'WH1234567890ABCDEF';
    }

    private function postWebhook(Integration $integration, ?string $webhookId, array $event, ?string $sentAt = null): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', "/api/v1/webhooks/paypal/{$integration->id}", [], [], [], [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-test',
            'HTTP_PAYPAL_TRANSMISSION_ID' => (string) Str::uuid(),
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => $sentAt ?? now()->toIso8601ZuluString(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['id' => 'WH-'.Str::random(8), ...$event], JSON_THROW_ON_ERROR));
    }

    private function drain(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(DomainOutboxDispatcher::class)->dispatchDue(limit: 100, leaseSeconds: 60);
        }
    }

    private function order(string $transactionRef, int $total, string $externalId): Order
    {
        return Order::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'integration_id' => $this->woo->id, 'external_id' => $externalId,
            'display_number' => '#'.$externalId, 'source_revision' => 1, 'status' => 'processing', 'mode' => 'test', 'currency' => 'USD',
            'currency_exponent' => 2, 'total_minor' => $total, 'payment_expected' => true, 'paid_marked_at' => now()->subHours(2), 'transaction_ref' => $transactionRef,
            'gateway' => 'ppcp-gateway', 'financial_support' => 'supported', 'is_synthetic' => false, 'source_created_at' => now()->subHours(2), 'source_updated_at' => now()->subHours(2),
            'current_payload_hash' => hash('sha256', $transactionRef), 'metadata' => [], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function storeRefund(Order $order, int $amount): Refund
    {
        return Refund::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'integration_id' => $this->woo->id, 'order_id' => $order->id,
            'external_id' => (string) Str::uuid(), 'source_revision' => 1, 'currency' => 'USD', 'currency_exponent' => 2, 'amount_minor' => $amount,
            'external_required' => true, 'provider_ref' => null, 'status' => 'recorded', 'occurred_at' => now()->subHour(),
            'current_payload_hash' => hash('sha256', (string) Str::uuid()), 'updated_at' => now(),
        ]);
    }

    private function paypalOrder(string $id, string $customId, array $captures, array $refunds, string $status = 'COMPLETED', array $authorizations = []): void
    {
        $this->orders[$id] = [
            'id' => $id, 'status' => $status, 'intent' => $authorizations === [] ? 'CAPTURE' : 'AUTHORIZE', 'create_time' => now()->subHour()->toIso8601ZuluString(),
            'payer' => ['email_address' => 'buyer@example.test', 'name' => ['given_name' => 'Buyer']],
            'purchase_units' => [['reference_id' => 'default', 'custom_id' => $customId, 'invoice_id' => 'wc-'.$customId, 'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
                'payments' => array_filter(['captures' => $captures, 'refunds' => $refunds, 'authorizations' => $authorizations])]],
        ];
    }

    private function capture(string $id, string $status, string $value): array
    {
        return ['id' => $id, 'status' => $status, 'amount' => ['currency_code' => 'USD', 'value' => $value], 'final_capture' => true, 'create_time' => now()->subMinutes(50)->toIso8601ZuluString()];
    }

    private function refundObject(string $id, string $status, string $value): array
    {
        return ['id' => $id, 'status' => $status, 'amount' => ['currency_code' => 'USD', 'value' => $value], 'create_time' => now()->subMinutes(20)->toIso8601ZuluString()];
    }

    private function row(string $transactionId, string $eventCode, ?string $reference = null, ?\Illuminate\Support\Carbon $at = null): void
    {
        $this->rows[] = ['transaction_info' => array_filter([
            'transaction_id' => $transactionId,
            'transaction_event_code' => $eventCode,
            'transaction_initiation_date' => ($at ?? now()->subMinutes(30))->toIso8601ZuluString(),
            'paypal_reference_id' => $reference,
            'paypal_reference_id_type' => $reference === null ? null : 'TXN',
        ])];
    }
}
