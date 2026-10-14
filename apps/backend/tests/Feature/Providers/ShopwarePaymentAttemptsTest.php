<?php

namespace Tests\Feature\Providers;

use App\Models\Incident;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\ObservedPaymentAttempt;
use App\Models\PaymentAttemptWindow;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Checkout\PaymentAttemptMonitor;
use App\Support\Network\DnsClient;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Providers\Shopware\ShopwareSync;
use App\Support\Stores\StoreCoverage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Stores\FakeDnsClient;
use Tests\TestCase;

class ShopwarePaymentAttemptsTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'UTC'));

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_payment_transactions_become_attempts_and_three_failures_open_an_incident_a_success_resolves(): void
    {
        $integration = $this->connected();
        Carbon::setTestNow(now()->addMinutes(5));
        $this->swOrder('f1', '20001', 40.0, 'EUR', 'open', [$this->transaction('f1t', 'failed', 'swag_paypal_paypal', [], '+0 minutes')]);
        $this->swOrder('f2', '20002', 40.0, 'EUR', 'open', [$this->transaction('f2t', 'failed', 'swag_paypal_paypal', [], '+0 minutes')]);
        $this->swOrder('f3', '20003', 40.0, 'EUR', 'open', [$this->transaction('f3x', 'cancelled', 'swag_paypal_paypal', [], '+0 minutes'), $this->transaction('f3t', 'failed', 'swag_paypal_paypal', [], '+1 minutes')]);
        $this->swOrder('p1', '20004', 19.99, 'EUR', 'open', [$this->transaction('p1t', 'open', 'payment_prepayment', [], '+0 minutes')]);
        Carbon::setTestNow(now()->addMinutes(10));

        app(ShopwareSync::class)->run($integration);

        $failed = ObservedPaymentAttempt::query()->where('payment_method', 'swag_paypal_paypal')->orderBy('attempted_at')->get();
        $this->assertSame(['failed:retried', 'failed:status_failed', 'failed:status_failed', 'failed:status_failed'], $failed->map(fn ($a) => $a->outcome.':'.$a->failure_class)->sort()->values()->all());
        $this->assertSame('on_hold', ObservedPaymentAttempt::query()->where('payment_method', 'payment_prepayment')->value('outcome'));
        $incident = Incident::query()->sole();
        $this->assertSame([PaymentAttemptMonitor::RULE_CODE, PaymentAttemptMonitor::FAMILY], [$incident->title_code, $incident->family]);
        $this->assertSame(4, (int) PaymentAttemptWindow::query()->where('payment_method', 'swag_paypal_paypal')->sum('failed'));
        $this->assertSame(1, (int) PaymentAttemptWindow::query()->where('payment_method', 'payment_prepayment')->sum('on_hold'));
        $this->assertSame('observing', app(StoreCoverage::class)->forStores(collect([$this->store->refresh()]))[$this->store->id]['coverage']['payment_attempts']['state']);

        $revisions = PaymentAttemptWindow::query()->orderBy('id')->pluck('source_revision', 'id')->all();
        app(ShopwareSync::class)->run($integration->refresh());
        $this->assertSame($revisions, PaymentAttemptWindow::query()->orderBy('id')->pluck('source_revision', 'id')->all(), 'reading the same orders again changes nothing');

        $this->swOrder('ok', '20005', 40.0, 'EUR', 'open', [$this->transaction('okt', 'paid', 'swag_paypal_paypal', [], '+0 minutes')]);
        Carbon::setTestNow(now()->addMinutes(10));
        app(ShopwareSync::class)->run($integration->refresh());

        $this->assertSame(0, Incident::query()->whereIn('state', Incident::ACTIVE_STATES)->count());
    }

    public function test_unanswered_payments_become_stuck_after_thirty_minutes_and_a_late_payment_counts_as_success(): void
    {
        $integration = $this->connected();
        Carbon::setTestNow(now()->addMinutes(1));
        $this->swOrder('s1', '30001', 40.0, 'EUR', 'open', [$this->transaction('s1t', 'unconfirmed', 'swag_paypal_paypal', [], '+0 minutes')]);
        app(ShopwareSync::class)->run($integration);
        $this->assertSame('pending', ObservedPaymentAttempt::query()->value('outcome'));
        $this->assertSame(0, PaymentAttemptWindow::query()->count());

        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('checkout:payment-attempts-stuck')->assertSuccessful();
        $attempt = ObservedPaymentAttempt::query()->sole();
        $this->assertSame(['pending_stuck', 'no_result'], [$attempt->outcome, $attempt->failure_class]);
        $this->assertSame(1, (int) PaymentAttemptWindow::query()->sum('pending_stuck'));

        $this->orders[$this->id('s1')]['transactions'][0]['stateMachineState']['technicalName'] = 'paid';
        $this->orders[$this->id('s1')]['transactions'][0]['updatedAt'] = now()->format('Y-m-d\TH:i:s.vP');
        app(ShopwareSync::class)->run($integration->refresh());

        $this->assertSame('late_success', ObservedPaymentAttempt::query()->value('outcome'));
        $this->assertSame([0, 1], [(int) PaymentAttemptWindow::query()->sum('pending_stuck'), (int) PaymentAttemptWindow::query()->sum('late_success')]);
    }

    public function test_transactions_from_before_the_connection_are_history_not_attempts(): void
    {
        $this->swOrder('old', '40001', 40.0, 'EUR', 'open', [$this->transaction('oldt', 'failed', 'swag_paypal_paypal', [], '-2 hours')]);
        $integration = $this->connected();

        app(ShopwareSync::class)->run($integration);

        $this->assertSame(0, ObservedPaymentAttempt::query()->count());
        $this->assertSame(0, PaymentAttemptWindow::query()->count());
        $this->assertSame('no_attempts_seen', app(StoreCoverage::class)->forStores(collect([$this->store->refresh()]))[$this->store->id]['coverage']['payment_attempts']['state']);
    }

    public function test_the_schedule_marks_stuck_attempts_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'checkout:payment-attempts-stuck'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
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
