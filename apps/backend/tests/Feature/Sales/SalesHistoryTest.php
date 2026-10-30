<?php

namespace Tests\Feature\Sales;

use App\Exceptions\Tenancy\MissingTenantContext;
use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Sales\SalesHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class SalesHistoryTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00 UTC');
        CarbonImmutable::setTestNow('2026-10-30 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_created_orders_and_store_paid_markers_are_separate_and_unknown_is_not_healthy(): void
    {
        $context = $this->context();
        $this->order($context, ['source_created_at' => '2026-10-30 08:00:00', 'paid_marked_at' => '2026-10-30 09:00:00']);
        $this->order($context, ['source_created_at' => '2026-10-30 10:00:00']);
        $this->order($context, ['source_created_at' => '2026-10-30 08:30:00', 'is_synthetic' => true]);
        $this->order($context, ['source_created_at' => '2026-10-30 08:30:00', 'mode' => 'test']);
        $this->order($context, ['source_created_at' => '2026-10-30 08:30:00', 'deleted_at' => now()]);

        $report = $this->report($context);

        $this->assertSame([1, 0], array_column($report['buckets'], 'orders_created_observed'));
        $this->assertSame([0, 1], array_column($report['buckets'], 'store_paid_markers_observed'));
        $this->assertSame([0, 0], array_column($report['buckets'], 'orders_with_capture_observed'));
        $this->assertSame(['unknown', 'unknown'], array_column($report['buckets'], 'coverage'));
        $this->assertSame('unknown', $report['baseline_state']);
        $this->assertFalse($report['alerts_enabled']);
        $this->assertContains('historical_coverage_not_recorded', $report['reason_codes']);
        $this->assertContains('store_source_missing', $report['reason_codes']);
        $this->assertStringNotContainsString('display_number', json_encode($report));
        $this->assertStringNotContainsString('external_id', json_encode($report));
    }

    public function test_one_order_with_multiple_captures_is_counted_only_at_its_first_capture(): void
    {
        $context = $this->context();
        $order = $this->order($context);
        $payment = Payment::query()->where('tenant_id', $order->tenant_id)->where('external_id', $order->transaction_ref)->sole();
        $this->capture($order, $payment, '2026-10-30 08:15:00');
        $this->capture($order, $payment, '2026-10-30 09:15:00');
        $this->capture($order, $payment, '2026-10-30 08:20:00', ['kind' => 'refund']);
        $this->capture($order, $payment, '2026-10-30 08:20:00', ['kind' => 'fee']);
        $this->capture($order, $payment, '2026-10-30 08:20:00', ['status' => 'pending']);
        $this->capture($order, $payment, '2026-10-30 08:20:00', ['source_authority' => 'store_reported']);
        $earlier = $this->order($context);
        $earlierPayment = Payment::query()->where('external_id', $earlier->transaction_ref)->sole();
        $this->capture($earlier, $earlierPayment, '2026-10-30 07:59:59');
        $this->capture($earlier, $earlierPayment, '2026-10-30 09:00:00');

        $first = $this->report($context);
        $second = $this->report($context);

        $this->assertSame([1, 0], array_column($first['buckets'], 'orders_with_capture_observed'));
        $this->assertSame($first, $second);
    }

    public function test_reconnecting_an_order_source_does_not_duplicate_orders_or_lose_older_capture_evidence(): void
    {
        $context = $this->context();
        $old = $this->order($context, ['external_id' => '42', 'source_created_at' => '2026-10-30 08:00:00', 'paid_marked_at' => '2026-10-30 09:00:00', 'source_updated_at' => '2026-10-30 09:00:00']);
        $payment = Payment::query()->where('external_id', $old->transaction_ref)->sole();
        $this->capture($old, $payment, '2026-10-30 08:15:00');
        $otherSource = Integration::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'provider' => 'woocommerce',
            'mode' => 'live', 'source_authority' => 'store_reported', 'status' => 'active',
            'capabilities' => [], 'connector_version' => '0.4.2',
        ]);
        $new = $this->order($context, ['external_id' => '42', 'integration_id' => $otherSource->id, 'source_created_at' => '2026-10-30 08:00:00', 'paid_marked_at' => '2026-10-30 09:00:00', 'source_updated_at' => '2026-10-30 10:00:00', 'transaction_ref' => $old->transaction_ref]);
        $report = $this->report($context);
        $this->assertSame([1, 0], array_column($report['buckets'], 'orders_created_observed'));
        $this->assertSame([0, 1], array_column($report['buckets'], 'store_paid_markers_observed'));
        $this->assertSame([1, 0], array_column($report['buckets'], 'orders_with_capture_observed'));

        $new->forceFill(['deleted_at' => now()])->save();
        $this->assertSame([0, 0], array_column($this->report($context)['buckets'], 'orders_created_observed'));
    }

    public function test_revoked_links_foreign_payments_and_test_mode_do_not_confirm_live_sales(): void
    {
        $context = $this->context();
        foreach (['revoked', 'foreign', 'test', 'synthetic', 'unlinked'] as $case) {
            $order = $this->order($context, ['is_synthetic' => $case === 'synthetic']);
            $payment = Payment::query()->where('external_id', $order->transaction_ref)->sole();
            if ($case === 'foreign') {
                $payment->forceFill(['foreign_store_reason' => 'store_host_mismatch'])->save();
            }
            if ($case === 'test') {
                $payment->forceFill(['mode' => 'test'])->save();
            }
            $allocation = $this->capture($order, $payment, '2026-10-30 08:10:00');
            if ($case === 'revoked') {
                $allocation->forceFill(['revoked_at' => now()])->save();
            }
            if ($case === 'unlinked') {
                $allocation->delete();
            }
        }

        $this->assertSame([0, 0], array_column($this->report($context)['buckets'], 'orders_with_capture_observed'));
    }

    public function test_scope_is_required_and_a_foreign_store_is_not_accessible(): void
    {
        $context = $this->context();
        $foreign = $this->context();
        $this->order($foreign, ['source_created_at' => '2026-10-30 08:00:00']);
        $this->assertSame([0, 0], array_column($this->report($context)['buckets'], 'orders_created_observed'));
        $this->expectException(ModelNotFoundException::class);
        app(TenantContext::class)->scope($context['tenant']->id, 'test', fn () => app(SalesHistory::class)->report($foreign['store']->id, CarbonImmutable::parse('2026-10-30 08:00:00 UTC'), CarbonImmutable::parse('2026-10-30 10:00:00 UTC')));
    }

    public function test_missing_context_fails_closed(): void
    {
        $this->expectException(MissingTenantContext::class);
        app(SalesHistory::class)->report((string) Str::uuid(), CarbonImmutable::now()->subHour(), CarbonImmutable::now());
    }

    public function test_stale_and_paused_sources_are_reported_without_turning_them_into_zero_sales(): void
    {
        $context = $this->context();
        $context['store']->forceFill(['status' => 'paused'])->save();
        Integration::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'provider' => 'woocommerce',
            'mode' => 'live', 'source_authority' => 'store_reported', 'status' => 'active',
            'capabilities' => [], 'connector_version' => '0.4.2', 'last_heartbeat_at' => now()->subHour(),
        ]);
        $report = $this->report($context);
        $this->assertContains('store_source_stale', $report['reason_codes']);
        $this->assertContains('monitoring_paused', $report['reason_codes']);
        $this->assertSame('unverified_history', $report['warmup_stage']);
    }

    public function test_fresh_heartbeat_does_not_prove_historical_coverage(): void
    {
        $context = $this->context();
        $context['store']->forceFill(['status' => 'active'])->save();
        Integration::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'provider' => 'woocommerce',
            'mode' => 'live', 'source_authority' => 'store_reported', 'status' => 'active',
            'capabilities' => [], 'connector_version' => '0.4.2', 'last_heartbeat_at' => now(),
        ]);
        $this->assertSame(['historical_coverage_not_recorded', 'thresholds_not_calibrated'], $this->report($context)['reason_codes']);
    }

    public function test_dst_fallback_keeps_both_local_hours_distinct(): void
    {
        $context = $this->context();
        $context['store']->forceFill(['timezone' => 'Europe/Berlin'])->save();
        $this->order($context, ['source_created_at' => '2026-10-25 00:30:00']);
        $this->order($context, ['source_created_at' => '2026-10-25 01:30:00']);
        $report = $this->report($context, '2026-10-25 00:00:00 UTC', '2026-10-25 02:00:00 UTC');
        $this->assertSame(['2026-10-25T02:00:00+02:00', '2026-10-25T02:00:00+01:00'], array_column($report['buckets'], 'local_hour'));
        $this->assertSame([1, 1], array_column($report['buckets'], 'orders_created_observed'));
    }

    public function test_partial_hour_and_half_open_boundaries_are_respected(): void
    {
        $context = $this->context();
        foreach (['08:14:59', '08:15:00', '09:14:59', '09:15:00'] as $time) {
            $this->order($context, ['source_created_at' => '2026-10-30 '.$time]);
        }
        $report = $this->report($context, '2026-10-30 08:15:00 UTC', '2026-10-30 09:15:00 UTC');
        $this->assertSame([1, 1], array_column($report['buckets'], 'orders_created_observed'));
        $this->assertSame('2026-10-30T08:15:00+00:00', $report['buckets'][0]['from']);
        $this->assertSame('2026-10-30T09:15:00+00:00', $report['buckets'][1]['to']);
    }

    public function test_command_is_scoped_read_only_and_rejects_bad_ranges(): void
    {
        $context = $this->context();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $options = ['--tenant' => $context['tenant']->id, '--store' => $context['store']->id, '--from' => '2026-10-30T08:00:00Z', '--to' => '2026-10-30T10:00:00Z'];
        $this->artisan('sales:history', $options)->expectsOutputToContain('diagnostic_only')->assertSuccessful();
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1)));
        foreach (['2026-11-30T10:00:00Z', '2026-10-01T00:00:00Z', '2026-02-30T08:00:00Z', '2026-10-30'] as $bad) {
            $this->artisan('sales:history', [...$options, '--to' => $bad])->expectsOutputToContain('sales_history_range_invalid')->assertFailed();
        }
        $this->artisan('sales:history', ['--store' => $context['store']->id])->assertFailed();
        $this->assertNull(app(TenantContext::class)->tenantId());
    }

    private function report(array $context, string $from = '2026-10-30 08:00:00 UTC', string $to = '2026-10-30 10:00:00 UTC'): array
    {
        return app(TenantContext::class)->scope($context['tenant']->id, 'test', fn () => app(SalesHistory::class)->report($context['store']->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to)));
    }

    private function capture(Order $order, Payment $payment, string $at, array $overrides = []): PaymentAllocation
    {
        $transaction = FinancialTransaction::query()->create([
            'tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'integration_id' => $payment->integration_id,
            'payment_id' => $payment->id, 'external_operation_id' => (string) Str::uuid(), 'kind' => 'capture', 'status' => 'succeeded',
            'currency' => $order->currency, 'currency_exponent' => $order->currency_exponent, 'amount_minor' => 100,
            'occurred_at' => $at, 'source_authority' => 'independent_provider', 'operation_hash' => hash('sha256', (string) Str::uuid()),
            'created_at' => now(), ...$overrides,
        ]);

        return PaymentAllocation::query()->create([
            'tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'payment_id' => $payment->id,
            'capture_transaction_id' => $transaction->id, 'order_id' => $order->id, 'currency' => $order->currency,
            'amount_minor' => 100, 'strategy' => 'exact_reference', 'evidence' => [], 'created_at' => now(),
        ]);
    }
}
