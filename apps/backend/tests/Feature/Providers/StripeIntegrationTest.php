<?php

namespace Tests\Feature\Providers;

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
use App\Support\Providers\Stripe\StripeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StripeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rk_test_51Smoke0123456789abcdef';

    private User $user;

    private Tenant $tenant;

    private Store $store;

    private Integration $woo;

    /** @var array<string, list<array<string, mixed>>> */
    private array $stripe = ['payment_intents' => [], 'charges' => [], 'refunds' => []];

    /** @var array<string, int> */
    private array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['watchdog.stripe.api_base' => 'https://stripe.test']);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin']);
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
                && (! isset($query['created']['lt']) || $object['created'] < (int) $query['created']['lt'])));
            usort($objects, fn (array $a, array $b): int => $b['created'] <=> $a['created']);

            if (isset($query['starting_after'])) {
                $index = array_search($query['starting_after'], array_column($objects, 'id'), true);
                $objects = array_slice($objects, $index + 1);
            }

            $limit = (int) ($query['limit'] ?? 100);

            return Http::response(['object' => 'list', 'data' => array_slice($objects, 0, $limit), 'has_more' => count($objects) > $limit]);
        });
    }

    public function test_connect_refuses_full_secret_keys_and_needs_every_read_permission(): void
    {
        $this->connect(['restricted_api_key' => 'sk_test_51Full0123456789abc'])->assertStatus(422)->assertJsonPath('code', 'stripe_secret_key_refused');

        $this->failures['refunds'] = 403;
        $this->connect()->assertStatus(422)->assertJsonPath('code', 'stripe_permission_missing');
        unset($this->failures['refunds']);

        $this->connect(['expected_account_id' => 'acct_Other'])->assertStatus(422)->assertJsonPath('code', 'stripe_account_mismatch');

        $response = $this->connect(['webhook_secret' => 'whsec_0123456789abcdef'])
            ->assertCreated()
            ->assertJsonPath('provider', 'stripe')
            ->assertJsonPath('mode', 'test')
            ->assertJsonPath('source_authority', 'independent_provider')
            ->assertJsonPath('external_account_id', 'acct_1Smoke')
            ->assertJsonPath('health.key_last4', 'cdef');

        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertStringNotContainsString('whsec_0123456789abcdef', $response->getContent());
        $this->assertStringEndsWith('/api/v1/webhooks/stripe/'.$response->json('id'), $response->json('webhook_url'));
        $this->assertSame(2, IntegrationCredential::query()->where('integration_id', $response->json('id'))->count());
        $this->assertFalse(IntegrationCredential::query()->where('ciphertext', 'like', '%'.self::KEY.'%')->exists());

        $this->connect()->assertStatus(409)->assertJsonPath('code', 'provider_already_connected');
    }

    public function test_sync_links_the_capture_to_the_order_by_its_payment_reference_and_reconciles(): void
    {
        $order = $this->order('pi_1', 18400);
        $this->stripe['payment_intents'][] = $this->intent('pi_1', 'succeeded', 'ch_1');
        $this->stripe['charges'][] = $this->charge('ch_0', 'pi_1', 'failed', false, 0, now()->subMinutes(20)->getTimestamp());
        $this->stripe['charges'][] = $this->charge('ch_1', 'pi_1', 'succeeded', true, 18400);
        $integration = $this->connected();

        $result = app(StripeSync::class)->run($integration);
        $this->drain();

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['complete']);
        $payment = Payment::query()->where('external_id', 'pi_1')->firstOrFail();
        $this->assertSame('captured', $payment->status);
        $this->assertSame('ch_1', $payment->charge_ref);
        $capture = FinancialTransaction::query()->where('external_operation_id', 'ch_1')->firstOrFail();
        $this->assertSame([$payment->id, 'capture', 'succeeded', 18400], [$capture->payment_id, $capture->kind, $capture->status, (int) $capture->amount_minor]);
        $this->assertFalse(FinancialTransaction::query()->where('external_operation_id', 'ch_0')->exists());

        $allocation = PaymentAllocation::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame([PaymentAllocation::STRATEGY_EXACT_REFERENCE, 18400], [$allocation->strategy, (int) $allocation->amount_minor]);

        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$this->store->id}/reconciliations", ['order_ids' => [$order->id]])->assertStatus(202);
        $statuses = ReconciliationFinding::query()->where('order_id', $order->id)->pluck('status', 'rule_code')->all();
        $this->assertNotContains('mismatch', $statuses);
        $this->assertNotContains('unknown', $statuses);
    }

    public function test_an_order_referencing_the_charge_like_the_woocommerce_stripe_gateway_is_linked_too(): void
    {
        $order = $this->order('ch_gw_1', 1000);
        $this->stripe['payment_intents'][] = $this->intent('pi_gw_1', 'succeeded', 'ch_gw_1');
        $this->stripe['charges'][] = $this->charge('ch_gw_1', 'pi_gw_1', 'succeeded', true, 1000);

        app(StripeSync::class)->run($this->connected());
        $this->drain();

        $this->assertSame(1000, (int) PaymentAllocation::query()->where('order_id', $order->id)->value('amount_minor'));
    }

    public function test_resync_emits_nothing_new_and_a_refund_moves_from_pending_to_succeeded(): void
    {
        $order = $this->order('pi_2', 5000);
        $storeRefund = Refund::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'integration_id' => $this->woo->id, 'order_id' => $order->id,
            'external_id' => 'woo-refund-1', 'source_revision' => 1, 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => 2000,
            'external_required' => true, 'provider_ref' => 're_1', 'status' => 'recorded', 'occurred_at' => now(),
            'current_payload_hash' => hash('sha256', 'refund'), 'updated_at' => now(),
        ]);
        $this->stripe['payment_intents'][] = $this->intent('pi_2', 'succeeded', 'ch_2');
        $this->stripe['charges'][] = $this->charge('ch_2', 'pi_2', 'succeeded', true, 5000);
        $this->stripe['refunds'][] = $this->refund('re_1', 'pi_2', 'ch_2', 'pending', 2000);
        $integration = $this->connected();

        app(StripeSync::class)->run($integration);
        $this->drain();
        $this->assertSame('pending', FinancialTransaction::query()->where('external_operation_id', 're_1')->value('status'));
        $this->assertFalse(RefundAllocation::query()->exists());

        $again = app(StripeSync::class)->run($integration->refresh());
        $this->assertSame(0, $again['emitted']);

        $this->stripe['refunds'][0]['status'] = 'succeeded';
        $this->travel(1)->seconds();
        app(StripeSync::class)->run($integration->refresh());
        $this->drain();

        $refund = FinancialTransaction::query()->where('external_operation_id', 're_1')->firstOrFail();
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame(['pending', 'succeeded'], [$refund->metadata['status_history'][0]['from'], $refund->metadata['status_history'][0]['to']]);
        $link = RefundAllocation::query()->where('refund_id', $storeRefund->id)->firstOrFail();
        $this->assertSame([PaymentAllocation::STRATEGY_EXACT_REFERENCE, 2000], [$link->strategy, (int) $link->amount_minor]);
    }

    public function test_a_rejected_key_stops_syncing_and_money_checks_become_unknown(): void
    {
        $order = $this->order('pi_3', 1000);
        $integration = $this->connected();
        $this->failures['payment_intents'] = 401;

        $result = app(StripeSync::class)->run($integration);

        $this->assertSame(['failed', 'stripe_key_rejected'], [$result['status'], $result['error']]);
        $this->assertSame(Integration::STATUS_DEGRADED, $integration->refresh()->status);
        $this->assertSame('stripe_key_rejected', $integration->health['last_error']['code']);
        $this->assertSame('skipped', app(StripeSync::class)->run($integration)['status']);

        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$this->store->id}/reconciliations", ['order_ids' => [$order->id]])->assertStatus(202);
        $finding = ReconciliationFinding::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(['unknown', 'provider_not_connected'], [$finding->status, $finding->reason_code]);
    }

    public function test_objects_of_the_other_mode_and_unconfirmed_currencies_are_skipped(): void
    {
        $this->stripe['charges'][] = [...$this->charge('ch_live', null, 'succeeded', true, 100), 'livemode' => true];
        $this->stripe['charges'][] = [...$this->charge('ch_huf', null, 'succeeded', true, 100), 'currency' => 'huf'];
        $integration = $this->connected();

        app(StripeSync::class)->run($integration);
        $this->drain();

        $this->assertSame(['other_mode' => 1, 'unsupported_currency' => 1], $integration->refresh()->health['sync']['skipped']);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_the_webhook_needs_a_valid_signature_and_feeds_the_same_path(): void
    {
        $integration = $this->connected('whsec_0123456789abcdef');
        $payload = json_encode([
            'id' => 'evt_1', 'object' => 'event', 'type' => 'charge.captured', 'livemode' => false, 'created' => time(), 'api_version' => '2026-09-30.endive',
            'data' => ['object' => $this->charge('ch_9', null, 'succeeded', true, 777)],
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', "/api/v1/webhooks/stripe/{$integration->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->signature($payload, 'whsec_wrong0123456789')], $payload)
            ->assertStatus(400)->assertJsonPath('code', 'signature_invalid');

        $this->call('POST', "/api/v1/webhooks/stripe/{$integration->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->signature($payload, 'whsec_0123456789abcdef')], $payload)
            ->assertOk()->assertJsonPath('emitted', 2);
        $this->call('POST', "/api/v1/webhooks/stripe/{$integration->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->signature($payload, 'whsec_0123456789abcdef')], $payload)
            ->assertOk()->assertJsonPath('emitted', 0);
        $this->drain();

        $this->assertSame(777, (int) FinancialTransaction::query()->where('external_operation_id', 'ch_9')->value('amount_minor'));
    }

    public function test_a_window_larger_than_the_page_budget_is_finished_on_the_next_runs(): void
    {
        config(['watchdog.stripe.page_size' => 2, 'watchdog.stripe.max_pages_per_run' => 1, 'watchdog.stripe.delta_overlap_minutes' => 0]);

        foreach (range(1, 5) as $i) {
            $this->stripe['charges'][] = $this->charge("ch_b{$i}", null, 'succeeded', true, 100 * $i, now()->subMinutes(10 * $i)->getTimestamp());
        }

        $integration = $this->connected();
        $first = app(StripeSync::class)->run($integration);
        $this->assertFalse($first['complete']);

        app(StripeSync::class)->run($integration->refresh());
        $third = app(StripeSync::class)->run($integration->refresh());
        $this->drain();

        $this->assertTrue($third['complete']);
        $this->assertSame(5, FinancialTransaction::query()->where('kind', 'capture')->count());
    }

    private function connect(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe", [...['restricted_api_key' => self::KEY], ...$body]);
    }

    private function connected(?string $webhookSecret = null): Integration
    {
        $id = $this->connect(array_filter(['webhook_secret' => $webhookSecret]))->assertCreated()->json('id');

        return Integration::query()->findOrFail($id);
    }

    private function drain(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(DomainOutboxDispatcher::class)->dispatchDue(limit: 100, leaseSeconds: 60);
        }
    }

    private function order(string $transactionRef, int $total): Order
    {
        return Order::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'integration_id' => $this->woo->id, 'external_id' => (string) Str::uuid(),
            'display_number' => '#'.random_int(1000, 9999), 'source_revision' => 1, 'status' => 'processing', 'mode' => 'test', 'currency' => 'EUR',
            'currency_exponent' => 2, 'total_minor' => $total, 'payment_expected' => true, 'paid_marked_at' => now()->subHours(2), 'transaction_ref' => $transactionRef,
            'gateway' => 'stripe', 'financial_support' => 'supported', 'is_synthetic' => false, 'source_created_at' => now()->subHours(2), 'source_updated_at' => now()->subHours(2),
            'current_payload_hash' => hash('sha256', $transactionRef), 'metadata' => [], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function intent(string $id, string $status, ?string $latestCharge): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status, 'latest_charge' => $latestCharge, 'currency' => 'eur', 'livemode' => false, 'created' => now()->subMinutes(30)->getTimestamp(), 'amount' => 0];
    }

    private function charge(string $id, ?string $intent, string $status, bool $captured, int $amountCaptured, ?int $created = null): array
    {
        return ['id' => $id, 'object' => 'charge', 'payment_intent' => $intent, 'status' => $status, 'captured' => $captured, 'amount_captured' => $amountCaptured, 'currency' => 'eur', 'livemode' => false, 'created' => $created ?? now()->subMinutes(15)->getTimestamp()];
    }

    private function refund(string $id, ?string $intent, ?string $charge, string $status, int $amount): array
    {
        return ['id' => $id, 'object' => 'refund', 'payment_intent' => $intent, 'charge' => $charge, 'status' => $status, 'amount' => $amount, 'currency' => 'eur', 'livemode' => false, 'created' => now()->subMinutes(5)->getTimestamp()];
    }

    private function signature(string $payload, string $secret): string
    {
        $timestamp = time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
