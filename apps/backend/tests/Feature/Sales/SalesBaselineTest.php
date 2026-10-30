<?php

namespace Tests\Feature\Sales;

use App\Exceptions\Tenancy\MissingTenantContext;
use App\Models\Integration;
use App\Support\Sales\SalesBaseline;
use App\Support\Sales\SalesCoverage;
use App\Support\Sales\SalesHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class SalesBaselineTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-30 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_same_weekday_bands_have_exact_median_mad_and_wider_counts_without_alerts(): void
    {
        $context = $this->fixture();
        foreach (['02' => 0, '09' => 1, '16' => 2, '23' => 9] as $day => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->order($context, ['source_created_at' => "2026-10-$day 08:30:00 UTC", 'transaction_ref' => null]);
            }
        }
        $this->order($context, ['source_created_at' => '2026-10-23 09:30:00 UTC', 'transaction_ref' => null]);
        $this->order($context, ['source_created_at' => '2026-10-22 08:30:00 UTC', 'transaction_ref' => null]);
        $report = $this->report($context);
        $band = $this->band($report, 1, 10);
        $this->assertSame([0, 1, 2, 9], array_column($band['samples'], 'orders_created'));
        $this->assertSame(['numerator' => 3, 'denominator' => 2], $band['expected_orders']);
        $this->assertSame(['numerator' => 4, 'denominator' => 4], $band['mad_orders']);
        $this->assertSame(['numerator' => 12, 'denominator' => 4], $band['mean_orders']);
        $this->assertSame([0, 1, 2, 10], array_column($this->band($report, 2, 10)['samples'], 'orders_created'));
        $this->assertSame(39, count($report['bands']));
        $this->assertFalse($report['alerts_enabled']);
        $this->assertSame('unknown', $report['baseline_state']);
        $this->assertSame($report, $this->report($context));
        $this->assertStringNotContainsString('external_id', json_encode($report));
        $this->assertStringNotContainsString('display_number', json_encode($report));
    }

    public function test_holiday_target_uses_only_ordinary_sundays_and_omits_dst_hour_samples(): void
    {
        $this->clock('2026-10-31 12:00:00 UTC');
        $context = $this->fixture('DE-SN');
        $report = $this->report($context);
        $this->assertSame(7, $report['comparison_weekday']);
        $this->assertContains('holiday', $report['target_exclusions']);
        $this->assertSame(['2026-10-04', '2026-10-11', '2026-10-18'], array_column($this->band($report, 1, 10)['samples'], 'date'));
        $this->assertSame([['date' => '2026-10-25', 'reason' => 'dst_duration_not_comparable']], $this->band($report, 24)['omitted_samples']);
        $this->assertSame(['numerator' => 0, 'denominator' => 2], $this->band($report, 24)['expected_orders']);
    }

    public function test_covered_zero_is_distinct_from_missing_calendar_or_coverage(): void
    {
        $context = $this->fixture();
        $band = $this->band($this->report($context), 1, 10);
        $this->assertSame(4, $band['sample_count']);
        $this->assertSame(['numerator' => 0, 'denominator' => 2], $band['expected_orders']);
        $this->assertContains('dispersion_zero_no_significance_inferred', $band['reason_codes']);
        $context['store']->forceFill(['settings' => []])->save();
        $report = $this->report($context);
        $this->assertNull($this->band($report, 24)['expected_orders']);
        $this->assertContains('holiday_calendar_not_configured', $this->band($report, 24)['reason_codes']);
        $context['store']->forceFill(['settings' => ['sales_calendar' => ['region' => 'DE-BE', 'local_holidays' => []]]])->save();
        $context['source']->forceFill(['sales_coverage' => null])->save();
        $this->assertNull($this->band($this->report($context), 24)['expected_orders']);
    }

    public function test_gap_backfill_and_manual_exclusions_remove_samples_separately_by_mode(): void
    {
        $context = $this->fixture();
        $this->scope($context, fn () => app(SalesCoverage::class)->excludeDay($context['store']->id, '2026-10-23', 'promotion'));
        $journal = $context['source']->sales_coverage;
        $journal['first_observed_at'] = '2026-10-03T00:00:00Z';
        $journal['intervals'] = [
            ['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-16T08:00:00Z'],
            ['from' => '2026-10-16T08:00:01Z', 'to' => now()->toIso8601String()],
        ];
        $context['source']->forceFill(['sales_coverage' => $journal])->save();
        $this->assertSame(['2026-10-09'], array_column($this->band($this->report($context), 24)['samples'], 'date'));
        $this->assertSame(['2026-10-09', '2026-10-23'], array_column($this->band($this->report($context, 'test'), 24)['samples'], 'date'));
    }

    public function test_latest_projection_filters_synthetic_deleted_mode_and_foreign_store_before_counting(): void
    {
        $context = $this->fixture();
        foreach ([['is_synthetic' => true], ['deleted_at' => now()], ['mode' => 'test']] as $override) {
            $this->order($context, [...$override, 'source_created_at' => '2026-10-23 08:30:00', 'transaction_ref' => null]);
        }
        $old = $this->order($context, ['external_id' => 'reconnect', 'source_created_at' => '2026-10-23 08:30:00', 'source_updated_at' => '2026-10-23 10:00:00', 'transaction_ref' => null]);
        $newSource = $context['source']->replicate();
        $context['source']->forceFill(['status' => Integration::STATUS_REVOKED])->save();
        $newSource->save();
        $new = $this->order($context, ['external_id' => $old->external_id, 'integration_id' => $newSource->id, 'source_created_at' => '2026-10-23 08:30:00', 'source_updated_at' => '2026-10-23 11:00:00', 'transaction_ref' => null]);
        $foreign = $this->fixture();
        $this->order($foreign, ['source_created_at' => '2026-10-23 08:30:00', 'transaction_ref' => null]);
        $sameTenant = $this->context(tenant: $context['tenant']);
        $this->order($sameTenant, ['source_created_at' => '2026-10-23 08:30:00', 'transaction_ref' => null]);
        $this->assertSame(1, $this->band($this->report($context), 1, 10)['sample_max']);
        $this->assertSame(1, $this->band($this->report($context, 'test'), 1, 10)['sample_max']);
        $new->forceFill(['deleted_at' => now()])->save();
        $this->assertSame(0, $this->band($this->report($context), 1, 10)['sample_max']);
    }

    public function test_dst_target_has_no_invented_hour_bounds_or_zero_expected_counts(): void
    {
        foreach (['2026-10-25 12:00:00 UTC', '2026-03-29 12:00:00 UTC'] as $now) {
            $this->clock($now);
            $report = $this->report($this->fixture());
            $band = $this->band($report, 1, 2);
            $this->assertNull($band['target_from']);
            $this->assertNull($band['target_to']);
            $this->assertNull($band['expected_orders']);
            $this->assertContains('target_dst_bands_unsupported', $band['reason_codes']);
            $this->assertNull($this->band($report, 24)['expected_orders']);
        }
    }

    public function test_manual_holiday_target_uses_sundays_but_is_not_itself_a_future_sample(): void
    {
        $context = $this->fixture();
        $this->scope($context, fn () => app(SalesCoverage::class)->excludeDay($context['store']->id, '2026-10-30', 'holiday'));
        $report = $this->report($context);
        $this->assertSame(7, $report['comparison_weekday']);
        $this->assertSame(['2026-10-04', '2026-10-11', '2026-10-18'], array_column($this->band($report, 24)['samples'], 'date'));
    }

    public function test_sunday_that_is_itself_a_holiday_does_not_enter_the_sunday_norm(): void
    {
        $this->clock('2026-11-22 12:00:00 UTC');
        $report = $this->report($this->fixture('DE-BY'));
        $this->assertSame(['2026-11-08', '2026-11-15'], array_column($this->band($report, 24)['samples'], 'date'));
    }

    public function test_local_bands_preserve_45_minute_offset_and_exclusive_end(): void
    {
        $context = $this->fixture(timezone: 'Asia/Kathmandu');
        foreach (['04:14:59', '04:15:00', '05:14:59', '05:15:00'] as $time) {
            $this->order($context, ['source_created_at' => "2026-10-23 $time", 'transaction_ref' => null]);
        }
        $this->order($context, ['source_created_at' => '2026-10-30 04:30:00', 'transaction_ref' => null]);
        $band = $this->band($this->report($context), 1, 10);
        $this->assertSame('2026-10-30T04:15:00+00:00', $band['target_from']);
        $this->assertSame('2026-10-30T05:15:00+00:00', $band['target_to']);
        $this->assertSame([0, 0, 0, 2], array_column($band['samples'], 'orders_created'));
        $this->assertSame('2026-10-23T04:15:00+00:00', $band['samples'][3]['from']);
    }

    public function test_history_v5_includes_profile_independent_of_requested_bucket_start_without_writes(): void
    {
        $context = $this->fixture();
        $before = $context['source']->fresh()->getAttributes();
        $report = $this->scope($context, fn () => app(SalesHistory::class)->report($context['store']->id, CarbonImmutable::now()->subHour(), CarbonImmutable::now()));
        $this->assertSame('sales-history-v5', $report['rule_version']);
        $this->assertSame($this->report($context), $report['order_baseline']);
        $this->assertSame($before, $context['source']->fresh()->getAttributes());
        $this->assertFalse($report['alerts_enabled']);
    }

    public function test_foreign_tenant_and_invalid_mode_fail_closed(): void
    {
        $context = $this->fixture();
        $other = $this->fixture();
        foreach ([[$other, 'live'], [$context, 'invalid']] as [$scope, $mode]) {
            try {
                $this->scope($scope, fn () => app(SalesBaseline::class)->report($context['store'], $mode, CarbonImmutable::now()));
                $this->fail('Scope must fail closed');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(MissingTenantContext::class);
        app(SalesBaseline::class)->report($context['store'], 'live', CarbonImmutable::now());
    }

    public function test_unverified_calendar_year_never_supplies_a_norm(): void
    {
        $this->clock('2028-02-15 12:00:00 UTC');
        $report = $this->report($this->fixture());
        $this->assertSame('out_of_range', $report['calendar_state']);
        $this->assertNull($report['comparison_weekday']);
        $this->assertNull($this->band($report, 24)['expected_orders']);
    }

    private function clock(string $now): void
    {
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function fixture(string $region = 'DE-BE', string $timezone = 'Europe/Berlin'): array
    {
        $context = $this->context();
        $context['store']->forceFill(['status' => 'active', 'timezone' => $timezone, 'settings' => ['sales_calendar' => ['region' => $region, 'local_holidays' => []]]])->save();
        $start = CarbonImmutable::now($timezone)->startOfDay()->subDays(28)->utc()->toIso8601String();
        $context['source'] = Integration::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'provider' => 'woocommerce_api',
            'mode' => 'live', 'source_authority' => 'store_reported', 'status' => 'active',
            'connector_version' => '1', 'capabilities' => [],
        ]);
        $context['source']->forceFill(['sales_coverage' => ['version' => 1, 'first_observed_at' => $start, 'intervals' => [['from' => $start, 'to' => now()->toIso8601String()]], 'pending' => []]])->save();

        return $context;
    }

    private function report(array $context, string $mode = 'live'): array
    {
        return $this->scope($context, fn () => app(SalesBaseline::class)->report($context['store'], $mode, CarbonImmutable::now()));
    }

    private function scope(array $context, callable $callback): mixed
    {
        return app(TenantContext::class)->scope($context['tenant']->id, 'test', $callback);
    }

    private function band(array $report, int $width, int $hour = 0): array
    {
        return array_values(array_filter($report['bands'], fn (array $band): bool => $band['width_hours'] === $width && $band['local_start_hour'] === $hour))[0];
    }
}
