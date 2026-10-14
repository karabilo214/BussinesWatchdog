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
use App\Support\Providers\WooCommerce\WooCommerceApiOrderMapper;
use App\Support\Providers\WooCommerce\WooCommerceApiSync;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Stores\FakeDnsClient;
use Tests\TestCase;

class WooCommerceApiPaymentAttemptsTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'UTC'));

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_checkout_orders_become_attempts_and_three_failures_open_an_incident_a_payment_resolves(): void
    {
        $this->wcOrder(1, ['total' => '10.00', 'payment_method' => 'stripe', 'status' => 'failed', 'date_paid_gmt' => null, 'date_created_gmt' => now()->subHours(2)->format('Y-m-d\TH:i:s')]);
        $integration = $this->connected();
        Carbon::setTestNow(now()->addMinutes(5));

        foreach ([2, 3] as $id) {
            $this->wcOrder($id, ['total' => '10.00', 'payment_method' => 'stripe', 'status' => 'failed', 'date_paid_gmt' => null]);
        }

        $this->wcOrder(4, ['total' => '10.00', 'payment_method' => 'stripe', 'status' => 'cancelled', 'date_paid_gmt' => null, 'created_via' => 'store-api']);
        $this->wcOrder(5, ['total' => '19.00', 'payment_method' => 'bacs', 'status' => 'on-hold', 'date_paid_gmt' => null]);
        $this->wcOrder(6, ['total' => '10.00', 'payment_method' => 'stripe', 'status' => 'failed', 'date_paid_gmt' => null, 'created_via' => 'admin']);
        $this->wcOrder(7, ['total' => '0.00', 'payment_method' => '', 'status' => 'completed']);
        $this->wcOrder(8, ['total' => '10.00', 'payment_method' => 'stripe', 'status' => 'checkout-draft', 'date_paid_gmt' => null]);
        Carbon::setTestNow(now()->addMinutes(5));

        app(WooCommerceApiSync::class)->run($integration);

        $attempts = ObservedPaymentAttempt::query()->orderBy('order_ref')->get()->mapWithKeys(fn (ObservedPaymentAttempt $a) => [$a->order_ref => $a->payment_method.':'.$a->outcome.':'.$a->failure_class])->all();
        $this->assertSame(['2' => 'stripe:failed:status_failed', '3' => 'stripe:failed:status_failed', '4' => 'stripe:failed:cancelled', '5' => 'bacs:on_hold:'], $attempts, 'before the connection, admin orders, free orders and drafts are not attempts');
        $incident = Incident::query()->sole();
        $this->assertSame(PaymentAttemptMonitor::RULE_CODE, $incident->title_code);
        $this->assertSame(1, (int) PaymentAttemptWindow::query()->where('payment_method', 'bacs')->sum('on_hold'));

        $revisions = PaymentAttemptWindow::query()->orderBy('id')->pluck('source_revision', 'id')->all();
        app(WooCommerceApiSync::class)->run($integration->refresh());
        $this->assertSame($revisions, PaymentAttemptWindow::query()->orderBy('id')->pluck('source_revision', 'id')->all(), 'reading again changes nothing');

        $this->wcOrder(9, ['total' => '10.00', 'payment_method' => 'stripe', 'status' => 'processing', 'date_paid_gmt' => now()->format('Y-m-d\TH:i:s')]);
        Carbon::setTestNow(now()->addMinutes(5));
        app(WooCommerceApiSync::class)->run($integration->refresh());
        $this->assertSame(Incident::STATE_RESOLVED, $incident->refresh()->state);
    }

    public function test_a_pending_order_becomes_stuck_and_a_later_payment_a_late_success(): void
    {
        $integration = $this->connected();
        Carbon::setTestNow(now()->addMinutes(1));
        $this->wcOrder(1, ['total' => '10.00', 'payment_method' => 'ppcp-gateway', 'status' => 'pending', 'date_paid_gmt' => null]);
        app(WooCommerceApiSync::class)->run($integration);
        $this->assertSame('pending', ObservedPaymentAttempt::query()->value('outcome'));

        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('checkout:payment-attempts-stuck')->assertSuccessful();
        $this->assertSame(['pending_stuck', 'no_result'], [ObservedPaymentAttempt::query()->value('outcome'), ObservedPaymentAttempt::query()->value('failure_class')]);

        $this->wcOrder(1, ['total' => '10.00', 'payment_method' => 'ppcp-gateway', 'status' => 'processing', 'date_paid_gmt' => now()->format('Y-m-d\TH:i:s')]);
        Carbon::setTestNow(now()->addMinutes(1));
        app(WooCommerceApiSync::class)->run($integration->refresh());
        $this->assertSame('late_success', ObservedPaymentAttempt::query()->value('outcome'));
        $this->assertSame([0, 1], [(int) PaymentAttemptWindow::query()->sum('pending_stuck'), (int) PaymentAttemptWindow::query()->sum('late_success')]);
    }

    public function test_the_connector_asks_for_created_via_but_still_no_personal_data(): void
    {
        $integration = $this->connected();
        app(WooCommerceApiSync::class)->run($integration);

        $this->assertStringContainsString('created_via', WooCommerceApiOrderMapper::ORDER_FIELDS);
        $this->assertStringNotContainsString('billing', WooCommerceApiOrderMapper::ORDER_FIELDS);
    }

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
            'transaction_id' => $props['transaction_id'] ?? '',
            'order_key' => 'wc_order_'.$id.'abcdef',
            'created_via' => $props['created_via'] ?? 'checkout',
            'date_created_gmt' => $this->orders[$id]['date_created_gmt'] ?? ($props['date_created_gmt'] ?? now()->format('Y-m-d\TH:i:s')),
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
