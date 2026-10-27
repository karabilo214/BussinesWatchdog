<?php

namespace Tests\Feature\Providers;

use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
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

class StripeFeeTest extends TestCase
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

    /** @var list<array<string, mixed>> */
    private array $balance = [];

    /** @var list<?string> */
    private array $feeRequests = [];

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

            if ($path === '/v1/balance_transactions') {
                $this->feeRequests[] = $query['source'] ?? null;

                return Http::response(['object' => 'list', 'data' => array_values(array_filter($this->balance, fn (array $row): bool => $row['source'] === ($query['source'] ?? null))), 'has_more' => false]);
            }
            $objects = array_values(array_filter($this->stripe[$resource] ?? [], fn (array $object): bool => $object['created'] >= (int) ($query['created']['gte'] ?? 0)
                && (! isset($query['created']['lte']) || $object['created'] <= (int) $query['created']['lte'])));
            usort($objects, fn (array $a, array $b): int => $b['created'] <=> $a['created']);

            return Http::response(['object' => 'list', 'data' => $objects, 'has_more' => false]);
        });
    }

    public function test_the_stripe_fee_is_shown_beside_the_order_and_in_the_overview_without_changing_the_reconciliation(): void
    {
        $order = $this->order();
        $this->balance[] = ['id' => 'txn_1', 'object' => 'balance_transaction', 'source' => 'ch_f', 'type' => 'charge', 'amount' => 8900, 'fee' => 158, 'net' => 8742, 'currency' => 'eur', 'created' => now()->subDay()->getTimestamp()];
        $integration = $this->connected();

        app(StripeSync::class)->run($integration);
        $this->drain();
        app(StripeSync::class)->run($integration->refresh());
        $this->drain();

        $fee = FinancialTransaction::query()->where('kind', 'fee')->firstOrFail();
        $this->assertSame(['fee:ch_f', 158, 'EUR', 'succeeded'], [$fee->external_operation_id, (int) $fee->amount_minor, $fee->currency, $fee->status]);
        $this->assertSame(FinancialTransaction::query()->where('kind', 'capture')->value('payment_id'), $fee->payment_id);
        $this->assertSame(['ok', true], [$integration->refresh()->health['sync']['fees']['state'], $integration->health['sync']['fees']['complete']]);
        $this->assertSame(1, PaymentAllocation::query()->count(), 'a fee is never linked to an order as money');

        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$this->store->id}/reconciliations", ['order_ids' => [$order->id]])->assertStatus(202);
        $this->assertNotContains('mismatch', ReconciliationFinding::query()->where('order_id', $order->id)->pluck('status')->all());

        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('fees.0.amount_minor', '158')->assertJsonCount(1, 'captures');
        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->getJson('/api/v1/overview')->assertOk()
            ->assertJsonPath('fees.0.fee_minor', '158')
            ->assertJsonPath('fees.0.captured_minor', '8900')
            ->assertJsonPath('fees.0.currency', 'EUR')
            ->assertJsonPath('fees.0.days', 30);

        $requests = count($this->feeRequests);
        app(StripeSync::class)->run($integration->refresh());
        $this->assertSame($requests, count($this->feeRequests), 'a known fee is not requested again');
    }

    public function test_a_key_without_balance_access_keeps_syncing_and_tries_again_later(): void
    {
        $this->order();
        $integration = $this->connected();
        app(StripeSync::class)->run($integration);
        $this->drain();
        $this->failures['balance_transactions'] = 403;

        $this->assertSame('ok', app(StripeSync::class)->run($integration->refresh())['status']);

        $integration->refresh();
        $this->assertSame(Integration::STATUS_ACTIVE, $integration->status);
        $this->assertSame('permission_missing', $integration->health['sync']['fees']['state']);
        $this->assertNull($integration->health['last_error']);
        $this->assertFalse(FinancialTransaction::query()->where('kind', 'fee')->exists());

        unset($this->failures['balance_transactions']);
        $this->balance[] = ['id' => 'txn_2', 'object' => 'balance_transaction', 'source' => 'ch_f', 'type' => 'charge', 'amount' => 8900, 'fee' => 160, 'net' => 8740, 'currency' => 'eur', 'created' => now()->subDay()->getTimestamp()];
        app(StripeSync::class)->run($integration);
        $this->drain();

        $this->assertSame(160, (int) FinancialTransaction::query()->where('kind', 'fee')->value('amount_minor'));
    }

    private function connected(): Integration
    {
        $id = $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe", ['restricted_api_key' => self::KEY])
            ->assertCreated()->json('id');

        return Integration::query()->findOrFail($id);
    }

    private function order(): Order
    {
        $this->stripe['payment_intents'] = [['id' => 'pi_f', 'object' => 'payment_intent', 'status' => 'succeeded', 'latest_charge' => 'ch_f', 'currency' => 'eur', 'livemode' => false, 'created' => now()->subDay()->getTimestamp(), 'amount' => 8900]];
        $this->stripe['charges'] = [['id' => 'ch_f', 'object' => 'charge', 'payment_intent' => 'pi_f', 'status' => 'succeeded', 'captured' => true, 'amount' => 8900, 'amount_captured' => 8900, 'currency' => 'eur', 'livemode' => false, 'created' => now()->subDay()->getTimestamp(), 'balance_transaction' => 'txn_1']];

        return Order::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'integration_id' => $this->woo->id, 'external_id' => '3001',
            'display_number' => '3001', 'source_revision' => 1, 'status' => 'processing', 'mode' => 'test', 'currency' => 'EUR',
            'currency_exponent' => 2, 'total_minor' => 8900, 'payment_expected' => true, 'paid_marked_at' => now()->subDay(), 'transaction_ref' => 'pi_f',
            'gateway' => 'stripe', 'financial_support' => 'supported', 'is_synthetic' => false, 'source_created_at' => now()->subDay(), 'source_updated_at' => now()->subDay(),
            'current_payload_hash' => hash('sha256', 'order-3001'), 'metadata' => [], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function drain(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(DomainOutboxDispatcher::class)->dispatchDue(limit: 100, leaseSeconds: 60);
        }
    }
}
