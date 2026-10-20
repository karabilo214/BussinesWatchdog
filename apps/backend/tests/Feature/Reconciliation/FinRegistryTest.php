<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Incident;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Ingest\EventIngestor;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Providers\Stripe\StripeSync;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The control examples of TZ §35.1 (`spec/examples/reconciliation-cases.json`), each run through the real path:
 * store order/refund snapshots through the ingestor, Stripe objects through StripeSync (HTTP faked with JSON fixtures),
 * the exact-reference matcher, order reconciliation and the money incident correlator.
 */
class FinRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rk_test_FinRegistryFixture';

    private const FIXTURE = __DIR__.'/../../../../../spec/examples/reconciliation-cases.json';

    /**
     * Control examples where the engine still differs from the TZ (docs/reconciliation-registry.md); each waits for the
     * owner's decision. The test fails as soon as one of them starts to match, so the list cannot go stale.
     */
    private const DEVIATIONS = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $stripe = ['payment_intents' => [], 'charges' => [], 'refunds' => []];

    private User $user;

    private Tenant $tenant;

    private Store $store;

    private Integration $woo;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now()->startOfSecond());
        config(['watchdog.stripe.api_base' => 'https://stripe.test']);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $this->tenant = Tenant::query()->create(['name' => 'Registry', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);
        $this->woo = Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'woocommerce', 'install_id' => (string) Str::uuid(),
            'mode' => 'test', 'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [], 'connector_version' => '1.0.0', 'health' => ['freshness' => ['state' => 'fresh']], 'last_heartbeat_at' => now(),
        ]);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/v1/account') {
                return Http::response(['id' => 'acct_1Registry', 'object' => 'account']);
            }

            return Http::response(['object' => 'list', 'data' => $this->stripe[basename($path)] ?? [], 'has_more' => false]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{array<string, mixed>|null}>
     */
    public static function cases(): array
    {
        if (! is_file(self::FIXTURE)) {
            return ['spec not mounted' => [null]];
        }

        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);

        return collect($fixture['cases'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    public function test_the_registry_covers_every_control_example(): void
    {
        if (! is_file(self::FIXTURE)) {
            $this->markTestSkipped('spec/ is not mounted.');
        }

        $this->assertSame(array_map(fn (int $n): string => sprintf('FIN-%02d', $n), range(1, 24)), array_keys(self::cases()));
    }

    /**
     * @param  array<string, mixed>|null  $case
     */
    #[DataProvider('cases')]
    public function test_control_example(?array $case): void
    {
        if ($case === null) {
            $this->markTestSkipped('spec/ is not mounted.');
        }

        $differences = $this->compare($case, $this->outcome($case));

        if (isset(self::DEVIATIONS[$case['id']])) {
            $this->assertNotSame([], $differences, $case['id'].' now matches the TZ: remove it from DEVIATIONS and docs/reconciliation-registry.md.');

            return;
        }

        $this->assertSame([], $differences, $case['id'].' '.$case['name']);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function outcome(array $case): array
    {
        $input = $case['input'];
        $id = strtolower(str_replace('-', '', $case['id']));
        $currency = $case['currency'] ?? $input['order_currency'] ?? 'EUR';
        $exponent = $case['currency_exponent'] ?? 2;
        $paymentCurrency = $input['payment_currency'] ?? $currency;
        $gross = (string) $input['gross_minor'];
        $captured = (string) ($input['captured_minor'] ?? '0');
        $intent = 'pi_'.$id;
        $reference = ($input['exact_match'] ?? true) ? $intent : 'pi_unknown_'.$id;
        $paidMarkedAt = ($input['paid_marked'] ?? true)
            ? (($input['grace_elapsed'] ?? true) ? now()->subHours(2) : now()->subMinutes(10))
            : null;

        if (($input['coverage'] ?? 'complete') === 'stale') {
            $this->woo->forceFill(['health' => ['freshness' => ['state' => 'stale']]])->save();
        }

        $orderData = [
            'status' => $input['order_status'] ?? 'processing', 'display_number' => '#'.$id, 'currency' => $currency, 'currency_exponent' => $exponent,
            'total_minor' => $gross, 'gateway' => 'stripe', 'transaction_ref' => $reference, 'payment_expected' => $input['payment_expected'] ?? true,
            'paid_marked_at' => $paidMarkedAt?->toJSON(), 'source_created_at' => now()->subHours(3)->toJSON(), 'source_updated_at' => now()->subHours(3)->toJSON(),
            'mode' => 'test', 'financial_support' => ($input['supported'] ?? true) ? 'supported' : 'unsupported',
        ];

        if (isset($input['previous_total_minor'])) {
            $this->storeEvent('order.snapshot', 'order', 'wc-'.$id, 1, now()->subHours(3), [...$orderData, 'total_minor' => (string) $input['previous_total_minor']]);
            $this->storeEvent('order.snapshot', 'order', 'wc-'.$id, 2, now()->subHour(), [...$orderData, 'source_updated_at' => now()->subHour()->toJSON()]);
        } else {
            $this->storeEvent('order.snapshot', 'order', 'wc-'.$id, 1, now()->subHours(3), $orderData);
        }

        $refundExpected = (string) ($input['refund_expected_minor'] ?? '0');
        $refundActual = (string) ($input['refund_actual_minor'] ?? '0');

        if ($refundExpected !== '0') {
            $this->storeEvent('refund.snapshot', 'refund', 'wc-refund-'.$id, 1, now()->subHours(2), [
                'order_id' => 'wc-'.$id, 'currency' => $currency, 'currency_exponent' => $exponent, 'amount_minor' => $refundExpected,
                'external_required' => true, 'provider_ref' => 're_'.$id, 'status' => 'recorded',
            ]);
        }

        if (isset($input['manual_refund_minor'])) {
            $this->storeEvent('refund.snapshot', 'refund', 'wc-manual-refund-'.$id, 1, now()->subHours(2), [
                'order_id' => 'wc-'.$id, 'currency' => $currency, 'currency_exponent' => $exponent, 'amount_minor' => (string) $input['manual_refund_minor'],
                'external_required' => $input['external_required'], 'provider_ref' => null, 'status' => 'recorded',
            ]);
        }

        $operations = $input['operations'] ?? ($captured !== '0' ? [['id' => 'ch_'.$id, 'kind' => 'capture', 'amount_minor' => $captured]] : []);
        $authorized = isset($input['authorization_minor']);
        $this->stripe['payment_intents'][] = ['id' => $intent, 'object' => 'payment_intent', 'status' => $authorized ? 'requires_capture' : ($operations === [] ? 'requires_payment_method' : 'succeeded'),
            'latest_charge' => $operations === [] ? ($authorized ? 'ch_auth_'.$id : null) : end($operations)['id'], 'currency' => strtolower($paymentCurrency), 'livemode' => false,
            'created' => now()->subHours(2)->subMinutes(5)->getTimestamp(), 'amount' => 0];

        if ($authorized) {
            $this->stripe['charges'][] = $this->charge('ch_auth_'.$id, $intent, $paymentCurrency, false, 0);
        }

        foreach ($operations as $operation) {
            $this->stripe['charges'][] = $this->charge($operation['id'], $intent, $paymentCurrency, true, $operation['amount_minor']);
        }

        if ($refundActual !== '0') {
            $this->stripe['refunds'][] = ['id' => 're_'.$id, 'object' => 'refund', 'payment_intent' => $intent, 'charge' => end($operations)['id'], 'status' => 'succeeded',
                'amount' => (int) $refundActual, 'currency' => strtolower($paymentCurrency), 'livemode' => false, 'created' => now()->subMinutes(90)->getTimestamp()];
        }

        $stripe = $this->connectStripe();
        app(StripeSync::class)->run($stripe);
        $this->drain();

        foreach (array_slice($input['repeated_operation_ids'] ?? [], 1) as $_) {
            app(StripeSync::class)->run($stripe->refresh());
            $this->drain();
        }

        $order = Order::query()->where('tenant_id', $this->tenant->id)->where('external_id', 'wc-'.$id)->firstOrFail();
        $run = app(OrderReconciliationService::class)->evaluate($order);
        app(MoneyIncidentCorrelator::class)->correlate($run);

        return [
            'order' => $order,
            'findings' => $run->findings()->get(),
            'published' => Incident::query()->where('tenant_id', $this->tenant->id)->where('family', 'money')->whereIn('state', Incident::ACTIVE_STATES)
                ->whereNotNull('verified_discrepancy_minor')->exists(),
        ];
    }

    /**
     * The fixture's classification of the engine's findings: a result, the three differences of TZ §12 from findings that
     * are final (ok or mismatch), and whether a money incident with an amount was published.
     *
     * @param  array<string, mixed>  $case
     * @param  array{order: Order, findings: Collection<int, ReconciliationFinding>, published: bool}  $outcome
     * @return list<string>
     */
    private function compare(array $case, array $outcome): array
    {
        $expected = $case['expected'];
        $findings = $outcome['findings'];
        $mismatches = $findings->where('status', ReconciliationFinding::STATUS_MISMATCH)->pluck('rule_code')->all();
        $statuses = $findings->pluck('status')->unique()->all();
        $unpaid = $outcome['order']->paid_marked_at === null && (int) $outcome['order']->total_minor > 0;

        $result = match (true) {
            in_array(ReconciliationFinding::STATUS_UNSUPPORTED, $statuses, true) => 'unsupported',
            $mismatches === [ReconciliationFinding::RULE_PAYMENT_NOT_MATCHED] => 'unmatched',
            $mismatches !== [] => 'mismatch',
            $findings->contains('reason_code', 'refund_external_unknown') => 'unknown_refund',
            in_array(ReconciliationFinding::STATUS_UNKNOWN, $statuses, true) => 'unknown',
            in_array(ReconciliationFinding::STATUS_PENDING, $statuses, true) || ($findings->isEmpty() && $unpaid) => 'pending',
            default => 'ok',
        };
        $final = $result === 'ok' || $result === 'mismatch';
        $difference = function (array $rules) use ($findings, $final): ?string {
            if (! $final) {
                return null;
            }

            $finding = $findings->first(fn (ReconciliationFinding $finding): bool => in_array($finding->rule_code, $rules, true) && $finding->difference_minor !== null);

            return $finding === null ? '0' : (string) $finding->difference_minor;
        };
        $capture = $difference([ReconciliationFinding::RULE_CAPTURE_AMOUNT, ReconciliationFinding::RULE_CAPTURE_MISSING]);
        $refund = $difference([ReconciliationFinding::RULE_REFUND_MISSING, ReconciliationFinding::RULE_REFUND_EXTRA]);
        $actual = [
            'capture_difference_minor' => $capture,
            'refund_difference_minor' => $refund,
            'net_difference_minor' => $capture === null || $refund === null ? null : (string) ((int) $capture - (int) $refund),
            'difference_minor' => $capture,
            'captured_minor' => ($captured = $findings->firstWhere('rule_code', ReconciliationFinding::RULE_CAPTURE_AMOUNT)?->captured_minor) === null ? null : (string) $captured,
            'publish_monetary_mismatch' => $outcome['published'],
        ];

        $differences = [];
        $expectedResult = $expected['result'];
        $resultMatches = match (true) {
            str_starts_with($expectedResult, 'MONEY_') => in_array($expectedResult, $mismatches, true),
            $expectedResult === 'review_cancelled_paid' => in_array(ReconciliationFinding::RULE_CANCELLED_PAID, $mismatches, true),
            $expectedResult === 'two_mismatches' => count(array_intersect($mismatches, [
                ReconciliationFinding::RULE_CAPTURE_AMOUNT, ReconciliationFinding::RULE_CAPTURE_MISSING,
                ReconciliationFinding::RULE_REFUND_MISSING, ReconciliationFinding::RULE_REFUND_EXTRA,
            ])) === 2,
            default => $expectedResult === $result,
        };

        if (! $resultMatches) {
            $differences[] = sprintf('result: expected %s, got %s [%s]', $expectedResult, $result, implode(', ', $mismatches));
        }

        foreach ($expected as $key => $value) {
            if ($key === 'result' || ! array_key_exists($key, $actual)) {
                continue;
            }

            if ($key === 'difference_minor' && $value === null && $outcome['published']) {
                $differences[] = 'difference_minor: expected no published amount';

                continue;
            }

            if ($key !== 'difference_minor' && $actual[$key] !== $value) {
                $differences[] = sprintf('%s: expected %s, got %s', $key, json_encode($value), json_encode($actual[$key]));
            }
        }

        return $differences;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeEvent(string $type, string $aggregate, string $aggregateId, int $revision, Carbon $observedAt, array $data): void
    {
        $results = app(EventIngestor::class)->ingest($this->woo, [[
            'schema_version' => '1.0', 'event_id' => (string) Str::uuid(), 'type' => $type, 'aggregate_type' => $aggregate, 'aggregate_id' => $aggregateId,
            'aggregate_revision' => $revision, 'occurred_at' => $observedAt->toJSON(), 'observed_at' => $observedAt->toJSON(), 'is_synthetic' => false, 'data' => $data,
        ]], null, (string) Str::uuid());

        $this->assertSame('accepted', $results[0]['status'], json_encode($results[0]));
        $this->drain();
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(string $id, string $intent, string $currency, bool $captured, int|string $amount): array
    {
        return ['id' => $id, 'object' => 'charge', 'payment_intent' => $intent, 'status' => 'succeeded', 'captured' => $captured, 'amount_captured' => (int) $amount,
            'currency' => strtolower($currency), 'livemode' => false, 'created' => now()->subHours(2)->getTimestamp()];
    }

    private function connectStripe(): Integration
    {
        $id = $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe", ['restricted_api_key' => self::KEY])
            ->assertCreated()
            ->json('id');

        return Integration::query()->findOrFail($id);
    }

    private function drain(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(DomainOutboxDispatcher::class)->dispatchDue(limit: 100, leaseSeconds: 60);
        }
    }
}
