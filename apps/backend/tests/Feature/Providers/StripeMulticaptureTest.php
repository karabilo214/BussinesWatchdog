<?php

namespace Tests\Feature\Providers;

use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Ingest\EventIngestor;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Providers\Stripe\StripeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StripeMulticaptureTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rk_test_51Smoke0123456789abcdef';

    private User $user;

    private Tenant $tenant;

    private Store $store;

    private Integration $woo;

    /** @var array<string, list<array<string, mixed>>> */
    private array $stripe = ['payment_intents' => [], 'charges' => [], 'refunds' => [], 'disputes' => []];

    /** @var array<string, int> */
    private array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['watchdog.stripe.api_base' => 'https://stripe.test']);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'de']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin', 'locale' => 'de']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);
        $this->woo = Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'woocommerce', 'install_id' => (string) Str::uuid(),
            'mode' => 'live', 'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [], 'connector_version' => '1.0.0', 'health' => ['freshness' => ['state' => 'fresh']], 'last_heartbeat_at' => now(),
        ]);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $resource = basename($path);

            if (isset($this->failures[$resource])) {
                return Http::response(['error' => ['type' => 'invalid_request_error']], $this->failures[$resource]);
            }

            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_1Smoke', 'object' => 'account']);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $objects = array_values(array_filter($this->stripe[$resource] ?? [], fn (array $object): bool => $object['created'] >= (int) ($query['created']['gte'] ?? 0)
                && (! isset($query['created']['lte']) || $object['created'] <= (int) $query['created']['lte'])));
            usort($objects, fn (array $a, array $b): int => $b['created'] <=> $a['created']);

            return Http::response(['object' => 'list', 'data' => $objects, 'has_more' => false]);
        });
    }

    public function test_a_growing_multicapture_amount_updates_the_capture_and_its_automatic_link(): void
    {
        $order = $this->order(10000);
        $this->stripe['charges'][] = $this->charge(4000);
        $integration = $this->connected();
        app(StripeSync::class)->run($integration);
        $this->drain();

        $capture = FinancialTransaction::query()->where('external_operation_id', 'ch_m')->firstOrFail();
        $this->assertSame(4000, (int) $capture->amount_minor);
        $this->assertSame(4000, (int) PaymentAllocation::query()->where('order_id', $order->id)->value('amount_minor'));
        $this->reconcile($order);
        $this->assertTrue(ReconciliationFinding::query()->where('order_id', $order->id)->where('status', 'mismatch')->exists(), 'a partial capture is not yet the full order');

        $this->travel(5)->minutes();
        $this->stripe['charges'] = [$this->charge(10000)];
        $this->stripe['payment_intents'][0]['status'] = 'succeeded';
        app(StripeSync::class)->run($integration);
        $this->drain();

        $capture->refresh();
        $this->assertSame([10000, 'succeeded'], [(int) $capture->amount_minor, $capture->status]);
        $this->assertSame([['from' => '4000', 'to' => '10000']], array_map(fn (array $step): array => ['from' => $step['from'], 'to' => $step['to']], $capture->metadata['amount_history']));
        $allocation = PaymentAllocation::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(10000, (int) $allocation->amount_minor);
        $this->assertSame('4000', $allocation->evidence['capture_growth'][0]['from']);
        $this->assertSame([], EventInbox::query()->where('status', '!=', EventInbox::STATUS_PROCESSED)->get(['event_type', 'status', 'error_code'])->toArray());
        $this->reconcile($order);
        $latest = ReconciliationFinding::query()->where('order_id', $order->id)->orderByDesc('evaluated_at')->orderByDesc('id')->get()->unique('rule_code')->pluck('status', 'rule_code')->all();
        $this->assertNotContains('mismatch', $latest, json_encode($latest));

        $older = EventInbox::query()->where('event_type', EventInbox::EVENT_TRANSACTION_OBSERVED)->where('payload->data->amount_minor', '4000')->firstOrFail()->payload;
        $older['event_id'] = (string) Str::uuid();
        $older['observed_at'] = now()->subHour()->toJSON();
        app(EventIngestor::class)->ingest($integration, [$older], null, (string) Str::uuid());
        $this->drain();

        $this->assertSame(10000, (int) $capture->refresh()->amount_minor);
        $this->assertSame(0, EventInbox::query()->where('status', '!=', EventInbox::STATUS_PROCESSED)->count(), 'a late smaller amount is ignored, not a conflict');
    }

    public function test_a_manual_link_keeps_its_amount_and_a_shrinking_capture_stays_a_conflict(): void
    {
        $order = $this->order(10000, null);
        $this->stripe['charges'][] = $this->charge(4000);
        $integration = $this->connected();
        app(StripeSync::class)->run($integration);
        $this->drain();
        $capture = FinancialTransaction::query()->where('external_operation_id', 'ch_m')->firstOrFail();
        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payment-allocations', ['payment_id' => $capture->payment_id, 'capture_transaction_id' => $capture->id, 'order_id' => $order->id, 'currency' => 'EUR', 'amount_minor' => '4000', 'reason' => 'Split shipment'])
            ->assertCreated();

        $this->stripe['charges'] = [$this->charge(7000)];
        app(StripeSync::class)->run($integration);
        $this->drain();

        $this->assertSame(7000, (int) $capture->refresh()->amount_minor);
        $this->assertSame(4000, (int) PaymentAllocation::query()->where('order_id', $order->id)->whereNull('revoked_at')->value('amount_minor'));

        $this->stripe['charges'] = [$this->charge(5000)];
        app(StripeSync::class)->run($integration);
        $this->drain();

        $this->assertSame(7000, (int) $capture->refresh()->amount_minor);
        $shrunk = EventInbox::query()->where('event_type', EventInbox::EVENT_TRANSACTION_OBSERVED)->where('payload->data->amount_minor', '5000')->firstOrFail();
        $this->assertSame(EventInbox::STATUS_RECEIVED, $shrunk->status);
        $this->assertSame('transaction_operation_conflict', DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED)->where('payload->event_inbox_id', $shrunk->id)->value('error_code'));
    }

    private function reconcile(Order $order): void
    {
        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$this->store->id}/reconciliations", ['order_ids' => [$order->id]])->assertStatus(202);
    }

    private function connected(): Integration
    {
        $id = $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe", ['restricted_api_key' => self::KEY])
            ->assertCreated()->json('id');

        return Integration::query()->findOrFail($id);
    }

    private function order(int $total, ?string $reference = 'pi_m'): Order
    {
        $this->stripe['payment_intents'] = [['id' => 'pi_m', 'object' => 'payment_intent', 'status' => 'requires_capture', 'latest_charge' => 'ch_m', 'currency' => 'eur', 'livemode' => false, 'created' => now()->subDay()->getTimestamp(), 'amount' => $total]];

        return Order::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'integration_id' => $this->woo->id, 'external_id' => '2001',
            'display_number' => '2001', 'source_revision' => 1, 'status' => 'processing', 'mode' => 'test', 'currency' => 'EUR',
            'currency_exponent' => 2, 'total_minor' => $total, 'payment_expected' => true, 'paid_marked_at' => now()->subDay(), 'transaction_ref' => $reference,
            'gateway' => 'stripe', 'financial_support' => 'supported', 'is_synthetic' => false, 'source_created_at' => now()->subDay(), 'source_updated_at' => now()->subDay(),
            'current_payload_hash' => hash('sha256', 'order-2001'), 'metadata' => [], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(int $captured): array
    {
        return ['id' => 'ch_m', 'object' => 'charge', 'payment_intent' => 'pi_m', 'status' => 'succeeded', 'captured' => true, 'amount' => 10000, 'amount_captured' => $captured, 'currency' => 'eur', 'livemode' => false, 'created' => now()->subDay()->getTimestamp(),
            'payment_method_details' => ['type' => 'card', 'card' => ['multicapture' => ['status' => 'available']]]];
    }

    private function drain(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(DomainOutboxDispatcher::class)->dispatchDue(limit: 100, leaseSeconds: 60);
        }
    }
}
