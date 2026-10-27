<?php

namespace Tests\Feature\Providers;

use App\Models\EventInbox;
use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProviderDispute;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Disputes\DisputeIncidents;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Providers\PayPal\PayPalSync;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PayPalDisputeTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPp0123456789_-sandboxClientIdForTests';

    private const CLIENT_SECRET = 'EeFfGgHhIiJjKkLlMmNnOoPpQqRrSsTt0123456789_-sandboxSecretForTests';

    private const SCOPES = 'https://uri.paypal.com/services/reporting/search/read https://uri.paypal.com/services/disputes/read-seller openid';

    private User $user;

    private Tenant $tenant;

    private Store $store;

    private Integration $woo;

    /** @var array<string, array<string, mixed>> */
    private array $disputes = [];

    private int $disputesStatus = 200;

    /** @var list<array<string, mixed>> */
    private array $listQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['watchdog.paypal.api_base_sandbox' => 'https://paypal.test']);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'USD']);
        $this->woo = Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'woocommerce', 'install_id' => (string) Str::uuid(),
            'mode' => 'live', 'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [], 'connector_version' => '1.0.0', 'health' => ['freshness' => ['state' => 'fresh']], 'last_heartbeat_at' => now(),
        ]);
        $order = [
            'id' => 'ORDER1', 'status' => 'COMPLETED', 'intent' => 'CAPTURE', 'create_time' => now()->subDays(5)->toIso8601ZuluString(),
            'purchase_units' => [['reference_id' => 'default', 'custom_id' => '1042', 'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
                'payments' => ['captures' => [['id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '10.00'], 'final_capture' => true, 'create_time' => now()->subDays(5)->toIso8601ZuluString(),
                    'seller_receivable_breakdown' => ['gross_amount' => ['currency_code' => 'USD', 'value' => '10.00'], 'paypal_fee' => ['currency_code' => 'USD', 'value' => '0.84'], 'net_amount' => ['currency_code' => 'USD', 'value' => '9.16']]]]]]],
        ];

        Http::fake(function (HttpRequest $request) use ($order) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match (true) {
                $path === '/v1/oauth2/token' => Http::response(['access_token' => 'A21-test-token', 'token_type' => 'Bearer', 'expires_in' => 32400, 'app_id' => 'APP-TEST123', 'scope' => self::SCOPES]),
                $path === '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
                $path === '/v1/reporting/transactions' => Http::response(['transaction_details' => [['transaction_info' => ['transaction_id' => 'CAP1', 'transaction_event_code' => 'T0006', 'transaction_initiation_date' => now()->subDays(5)->toIso8601ZuluString()]]], 'total_pages' => 1, 'page' => 1, 'last_refreshed_datetime' => now()->toIso8601ZuluString()]),
                $path === '/v2/payments/captures/CAP1' => Http::response([...$order['purchase_units'][0]['payments']['captures'][0], 'links' => [['rel' => 'up', 'href' => 'https://paypal.test/v2/checkout/orders/ORDER1']]]),
                $path === '/v2/checkout/orders/ORDER1' => Http::response($order),
                $path === '/v1/customer/disputes' => $this->listDisputes($query),
                preg_match('#^/v1/customer/disputes/([A-Z0-9-]+)$#', $path, $match) === 1 => isset($this->disputes[$match[1]]) ? Http::response($this->disputes[$match[1]]) : Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404),
                default => Http::response(['name' => 'UNEXPECTED'], 500),
            };
        });
    }

    public function test_a_chargeback_waiting_for_the_seller_opens_an_incident_on_the_paypal_order(): void
    {
        config(['watchdog.paypal.dispute_days' => 365]);
        $order = $this->storeOrder();
        $due = now()->addDays(8)->startOfSecond();
        $this->dispute('PP-D-1001', 'WAITING_FOR_SELLER_RESPONSE', 'CHARGEBACK', 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED', $due->toIso8601ZuluString());
        $integration = $this->connected();

        $this->assertSame('ok', app(PayPalSync::class)->run($integration)['status']);
        $this->drain();

        $dispute = ProviderDispute::query()->where('external_id', 'PP-D-1001')->firstOrFail();
        $this->assertSame(['needs_response', '1000', 'USD', 'merchandise_or_service_not_received', 'CAP1', 'ORDER1'], [$dispute->status, $dispute->amount_minor, $dispute->currency, $dispute->reason, $dispute->charge_ref, $dispute->payment_external_id]);
        $this->assertSame(Payment::query()->where('external_id', 'ORDER1')->value('id'), $dispute->payment_id);
        $this->assertSame($due->getTimestamp(), $dispute->evidence_due_by?->getTimestamp());
        $health = $integration->refresh()->health['sync']['disputes'];
        $this->assertSame(['ok', true], [$health['state'], $health['complete']]);
        $this->assertNotNull($health['watermark']);
        $this->assertGreaterThan(now()->subDays(180)->getTimestamp(), strtotime($this->listQueries[0]['update_time_after']));

        $incident = Incident::query()->where('family', DisputeIncidents::FAMILY)->firstOrFail();
        $this->assertSame([Incident::SEVERITY_WARNING, DisputeIncidents::RULE_OPEN], [$incident->severity, $incident->title_code]);
        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('disputes.0.provider', 'paypal');
        $this->assertStringNotContainsString('buyer@example.test', json_encode(EventInbox::query()->pluck('payload'), JSON_THROW_ON_ERROR));
    }

    public function test_outcomes_map_to_won_closed_and_inquiries(): void
    {
        $this->storeOrder();
        $this->dispute('PP-D-2001', 'UNDER_REVIEW', 'CHARGEBACK', 'UNAUTHORISED', null);
        $this->dispute('PP-D-2002', 'OPEN', 'CHARGEBACK', 'OTHER', null);
        $this->dispute('PP-R-2003', 'WAITING_FOR_SELLER_RESPONSE', 'INQUIRY', 'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED', now()->addDays(10)->toIso8601ZuluString());
        $integration = $this->connected();
        app(PayPalSync::class)->run($integration);
        $this->drain();

        $this->assertSame(['under_review', 'under_review', 'warning_needs_response'], ProviderDispute::query()->orderBy('external_id')->pluck('status')->all());
        $this->assertSame(DisputeIncidents::RULE_INQUIRY, Incident::query()->where('fingerprint', ProviderDispute::query()->where('external_id', 'PP-R-2003')->value('incident_fingerprint'))->value('title_code'));

        $this->dispute('PP-D-2001', 'RESOLVED', 'CHARGEBACK', 'UNAUTHORISED', null, 'RESOLVED_SELLER_FAVOUR');
        $this->dispute('PP-D-2002', 'RESOLVED', 'CHARGEBACK', 'OTHER', null, 'NONE');
        $this->dispute('PP-R-2003', 'RESOLVED', 'INQUIRY', 'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED', null, 'CANCELED_BY_BUYER');
        app(PayPalSync::class)->run($integration->refresh());
        $this->drain();

        $this->assertSame(['won', 'closed', 'warning_closed'], ProviderDispute::query()->orderBy('external_id')->pluck('status')->all());
        $this->assertSame(['auto_resolved_dispute_closed', 'auto_resolved_dispute_closed', 'auto_resolved_dispute_won'], Incident::query()->where('family', DisputeIncidents::FAMILY)->orderBy('resolution_reason')->pluck('resolution_reason')->all());
    }

    public function test_the_paypal_fee_of_a_capture_is_kept_as_information(): void
    {
        $order = $this->storeOrder();
        $integration = $this->connected();

        app(PayPalSync::class)->run($integration);
        $this->drain();

        $fee = FinancialTransaction::query()->where('kind', 'fee')->firstOrFail();
        $this->assertSame(['fee:CAP1', 84, 'USD'], [$fee->external_operation_id, (int) $fee->amount_minor, $fee->currency]);
        $this->assertSame(Payment::query()->where('external_id', 'ORDER1')->value('id'), $fee->payment_id);
        $this->assertSame(1000, (int) PaymentAllocation::query()->where('order_id', $order->id)->value('amount_minor'));
        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('fees.0.amount_minor', '84');
    }

    public function test_an_app_without_dispute_access_keeps_syncing_payments(): void
    {
        $this->storeOrder();
        $this->disputesStatus = 403;
        $integration = $this->connected();

        $this->assertSame('ok', app(PayPalSync::class)->run($integration)['status']);
        $this->drain();

        $integration->refresh();
        $this->assertSame(Integration::STATUS_ACTIVE, $integration->status);
        $this->assertSame('permission_missing', $integration->health['sync']['disputes']['state']);
        $this->assertNull($integration->health['last_error']);
        $this->assertTrue(Payment::query()->where('external_id', 'ORDER1')->exists());
    }

    public function test_a_dispute_webhook_reads_the_dispute_from_the_api(): void
    {
        $this->storeOrder();
        $integration = $this->connected();
        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->putJson("/api/v1/integrations/{$integration->id}/webhook-id", ['webhook_id' => 'WH1234567890ABCDEF'])->assertOk();
        $this->dispute('PP-D-3001', 'WAITING_FOR_SELLER_RESPONSE', 'CHARGEBACK', 'DUPLICATE_TRANSACTION', now()->addDays(5)->toIso8601ZuluString());

        $this->postWebhook($integration, ['event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => ['dispute_id' => 'PP-D-3001', 'reason' => 'FORGED', 'buyer' => ['email' => 'buyer@example.test']]])
            ->assertOk()->assertJsonPath('emitted', 1);
        $this->postWebhook($integration, ['event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => ['dispute_id' => '../evil']])
            ->assertOk()->assertJsonPath('ignored', 'event_not_used');
        $this->drain();

        $this->assertSame('duplicate_transaction', ProviderDispute::query()->where('external_id', 'PP-D-3001')->value('reason'));
    }

    private function listDisputes(array $query): PromiseInterface
    {
        $this->listQueries[] = $query;

        if (strtotime((string) ($query['update_time_after'] ?? '')) < now()->subDays(180)->getTimestamp()) {
            return Http::response(['name' => 'INVALID_REQUEST', 'details' => [['field' => 'update_time_after', 'issue' => 'INVALID_DATE_RANGE']]], 400);
        }

        if ($this->disputesStatus !== 200) {
            return Http::response(['name' => 'NOT_AUTHORIZED'], $this->disputesStatus);
        }

        return Http::response(['items' => array_values(array_map(fn (array $dispute): array => ['dispute_id' => $dispute['dispute_id'], 'create_time' => $dispute['create_time'], 'update_time' => $dispute['update_time'], 'status' => $dispute['status']], $this->disputes)), 'links' => []]);
    }

    private function dispute(string $id, string $status, string $stage, string $reason, ?string $due, ?string $outcome = null): void
    {
        $this->disputes[$id] = array_filter([
            'dispute_id' => $id, 'create_time' => now()->subDay()->toIso8601ZuluString(), 'update_time' => now()->toIso8601ZuluString(),
            'disputed_transactions' => [['seller_transaction_id' => 'CAP1', 'buyer_transaction_id' => 'BUYER1', 'buyer' => ['name' => 'Buyer Person', 'email' => 'buyer@example.test']]],
            'reason' => $reason, 'status' => $status, 'dispute_life_cycle_stage' => $stage, 'dispute_channel' => 'INTERNAL',
            'dispute_amount' => ['currency_code' => 'USD', 'value' => '10.00'],
            'seller_response_due_date' => $due,
            'dispute_outcome' => $outcome === null ? null : ['outcome_code' => $outcome],
            'messages' => [['posted_by' => 'BUYER', 'content' => 'Where is my order? buyer@example.test']],
        ], fn (mixed $value): bool => $value !== null);
    }

    private function storeOrder(): Order
    {
        return Order::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'integration_id' => $this->woo->id, 'external_id' => '1042',
            'display_number' => '1042', 'source_revision' => 1, 'status' => 'processing', 'mode' => 'test', 'currency' => 'USD',
            'currency_exponent' => 2, 'total_minor' => 1000, 'payment_expected' => true, 'paid_marked_at' => now()->subDays(5), 'transaction_ref' => 'CAP1',
            'gateway' => 'ppcp-gateway', 'financial_support' => 'supported', 'is_synthetic' => false, 'source_created_at' => now()->subDays(5), 'source_updated_at' => now()->subDays(5),
            'current_payload_hash' => hash('sha256', 'order-1042'), 'metadata' => [], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function connected(): Integration
    {
        $id = $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/paypal", ['environment' => 'sandbox', 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET, 'write_access_acknowledged' => true])
            ->assertCreated()->json('id');

        return Integration::query()->findOrFail($id);
    }

    private function postWebhook(Integration $integration, array $event): TestResponse
    {
        return $this->call('POST', "/api/v1/webhooks/paypal/{$integration->id}", [], [], [], [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-test',
            'HTTP_PAYPAL_TRANSMISSION_ID' => (string) Str::uuid(),
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601ZuluString(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['id' => 'WH-'.Str::random(8), ...$event], JSON_THROW_ON_ERROR));
    }

    private function drain(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(DomainOutboxDispatcher::class)->dispatchDue(limit: 100, leaseSeconds: 60);
        }
    }
}
