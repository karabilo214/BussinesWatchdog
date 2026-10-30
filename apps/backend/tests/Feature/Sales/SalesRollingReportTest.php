<?php

namespace Tests\Feature\Sales;

use App\Exceptions\Tenancy\MissingTenantContext;
use App\Models\Incident;
use App\Models\Integration;
use App\Support\Sales\SalesCoverage;
use App\Support\Sales\SalesRollingReport;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class SalesRollingReportTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-08-30 12:34:56 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_rolling_windows_use_exact_lag_closed_counts_and_four_daily_references_without_writes(): void
    {
        $context = $this->fixture();
        foreach ([2, 9, 16, 23] as $day) {
            $this->observations($context, sprintf('2026-08-%02d 10:45:00 UTC', $day), 10);
            $this->observations($context, sprintf('2026-08-%02d 11:45:00 UTC', $day), 10);
        }
        foreach (['11:29:55', '11:29:56', '12:29:55', '12:29:56', '12:32:00'] as $time) {
            $this->observations($context, '2026-08-30 '.$time.' UTC');
        }
        $before = $context['source']->fresh()->getAttributes();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $report = $this->report($context);
        $this->assertSame('2026-08-30T12:29:56+00:00', $report['evaluation_time']);
        $this->assertSame('2026-08-30T11:29:56+00:00', $report['windows'][0]['target_from']);
        $this->assertSame(2, $report['windows'][0]['orders_created']);
        $this->assertSame(3, $report['windows'][1]['orders_created']);
        $this->assertSame(4, $report['windows'][3]['sample_count']);
        $this->assertSame(28, $report['order_history_warmup']['eligible_days']);
        $this->assertSame(2, $report['window_selection']['selections'][0]['selected_window']['width_hours']);
        $this->assertSame('trailing_elapsed_windows', $report['window_selection']['policy']['window_kind']);
        foreach ($report['windows'] as $window) {
            $this->assertLessThanOrEqual($report['evaluation_time'], $window['target_to']);
            foreach ($window['samples'] as $sample) {
                $this->assertLessThanOrEqual($report['training_cutoff'], $sample['to']);
            }
        }
        $this->assertSame($report, $this->report($context));
        $this->assertSame($before, $context['source']->fresh()->getAttributes());
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1)));
        $this->assertStringNotContainsString('external_id', json_encode($report));
        $this->assertStringNotContainsString('display_number', json_encode($report));
        $this->assertFalse($report['alerts_enabled']);
    }

    public function test_one_second_coverage_gap_keeps_actual_zero_unknown_despite_positive_history(): void
    {
        $context = $this->fixture();
        foreach ([2, 9, 16, 23] as $day) {
            $this->observations($context, sprintf('2026-08-%02d 11:45:00 UTC', $day), 20);
        }
        $journal = $context['source']->sales_coverage;
        $journal['intervals'] = [
            ['from' => $journal['intervals'][0]['from'], 'to' => '2026-08-30T11:59:59Z'],
            ['from' => '2026-08-30T12:00:00Z', 'to' => CarbonImmutable::now()->toIso8601String()],
        ];
        $context['source']->forceFill(['sales_coverage' => $journal])->save();
        $report = $this->report($context, alpha: '0.01');
        $window = $report['windows'][0];
        $this->assertSame(0, $window['orders_created_observed']);
        $this->assertNull($window['orders_created']);
        $this->assertSame('available', $window['historical_profile']['structural_state']);
        $this->assertContains('target_orders_coverage_unknown', $window['warmup_profile']['reason_codes']);
        $this->assertNull($report['window_selection']['selections'][0]['selected_window']);
        $this->assertSame('unknown', $report['baseline_state']);
        $this->assertSame('abstain', $report['statistical_assessment']['result']);
        $this->assertNull($report['statistical_assessment']['test']);
    }

    public function test_cross_midnight_matches_the_whole_weekday_sequence_and_exclusive_end(): void
    {
        $this->clock('2026-08-31 10:00:00 UTC');
        $context = $this->fixture();
        foreach ([2, 9, 16, 23, 30] as $day) {
            $this->observations($context, sprintf('2026-08-%02d 23:30:00 Europe/Berlin', $day));
            $next = CarbonImmutable::parse(sprintf('2026-08-%02d', $day), 'Europe/Berlin')->addDay()->setTime(0, 5);
            $this->observations($context, $next->utc()->toIso8601String());
        }
        $this->observations($context, '2026-08-31T00:20:00+02:00');
        $report = $this->report($context, lag: '0', at: CarbonImmutable::parse('2026-08-31T00:20:00+02:00'), minimum: '2');
        $this->assertSame([7, 1], $report['windows'][0]['day_pattern']);
        $this->assertSame(2, $report['windows'][0]['orders_created']);
        $this->assertSame(4, $report['windows'][0]['sample_count']);
        $this->assertSame(1, $report['window_selection']['selections'][0]['selected_window']['width_hours']);
    }

    public function test_holiday_target_uses_ordinary_sunday_windows(): void
    {
        $this->clock('2026-12-25 12:00:00 UTC');
        $context = $this->fixture();
        foreach (['2026-11-29', '2026-12-06', '2026-12-13', '2026-12-20'] as $date) {
            $this->observations($context, $date.' 09:30:00 UTC', 20);
        }
        $report = $this->report($context, lag: '0', at: CarbonImmutable::parse('2026-12-25T10:00:00Z'));
        $this->assertSame([7], $report['windows'][0]['day_pattern']);
        $this->assertSame(4, $report['windows'][0]['sample_count']);
        $this->assertContains('holiday', $report['windows'][0]['metric_coverage']['baseline_exclusions']);
        $this->assertSame(1, $report['window_selection']['selections'][0]['selected_window']['width_hours']);
    }

    public function test_modes_latest_deleted_synthetic_and_foreign_stores_are_scoped_before_counting(): void
    {
        $context = $this->fixture();
        $at = '2026-08-30 12:00:00 UTC';
        $this->observations($context, $at);
        $this->order($context, ['source_created_at' => $at, 'mode' => 'test', 'transaction_ref' => null]);
        $this->order($context, ['source_created_at' => $at, 'is_synthetic' => true, 'transaction_ref' => null]);
        $this->order($context, ['source_created_at' => $at, 'deleted_at' => now(), 'transaction_ref' => null]);
        $old = $this->order($context, ['external_id' => 'reconnect', 'source_created_at' => $at, 'source_updated_at' => '2026-08-30 12:00:00', 'transaction_ref' => null]);
        $this->order($context, ['external_id' => $old->external_id, 'integration_id' => $context['source']->id, 'mode' => 'test', 'source_created_at' => $at, 'source_updated_at' => '2026-08-30 12:01:00', 'transaction_ref' => null]);
        $foreign = $this->fixture();
        $this->observations($foreign, $at);
        $sameTenant = $this->context(tenant: $context['tenant']);
        $this->observations($sameTenant, $at);
        $this->assertSame(1, $this->report($context)['windows'][0]['orders_created']);
        $this->assertSame(2, $this->report($context, mode: 'test')['windows'][0]['orders_created']);
        try {
            $this->scope($foreign, fn () => app(SalesRollingReport::class)->report($context['store']->id, 'live', CarbonImmutable::now(), '300'));
            $this->fail('Foreign store must be hidden');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->expectException(MissingTenantContext::class);
        app(SalesRollingReport::class)->report($context['store']->id, 'live', CarbonImmutable::now(), '300');
    }

    public function test_context_retention_preserves_old_exclusions_without_granting_warmup_days(): void
    {
        $context = $this->fixture();
        $this->scope($context, fn () => app(SalesCoverage::class)->finalize($context['source']->id));
        $cutoff = CarbonImmutable::now('Europe/Berlin')->startOfDay()->subDays(30)->utc()->toIso8601String();
        $this->assertSame($cutoff, $context['source']->fresh()->sales_coverage['intervals'][0]['from']);
        $from = CarbonImmutable::parse('2026-08-01', 'Europe/Berlin');
        $context['store']->forceFill(['sales_baseline_exclusions' => [
            'live:2026-08-01:promotion' => ['date' => '2026-08-01', 'mode' => 'live', 'kind' => 'promotion', 'timezone' => 'Europe/Berlin', 'from' => $from->utc()->toIso8601String(), 'to' => $from->addDay()->utc()->toIso8601String()],
            'old' => ['date' => '2026-07-29', 'mode' => 'live', 'kind' => 'promotion', 'from' => '2026-07-29T00:00:00Z', 'to' => '2026-07-30T00:00:00Z'],
        ]])->save();
        $this->scope($context, fn () => app(SalesCoverage::class)->pruneExclusions($context['store']->id));
        $this->assertCount(1, $context['store']->fresh()->sales_baseline_exclusions);
        $report = $this->report($context);
        $this->assertSame(28, $report['order_history_warmup']['eligible_days']);
        $this->assertCount(28, $report['order_history_warmup']['days']);
        $this->assertSame(4, $report['windows'][0]['sample_count']);
        $this->assertSame(3, $report['windows'][3]['sample_count']);
        $this->assertContains(['date' => '2026-08-02', 'reason' => 'historical_day_not_eligible'], $report['windows'][3]['omitted_samples']);
    }

    public function test_sparse_covered_zero_abstains_and_does_not_claim_a_working_checkout(): void
    {
        $context = $this->fixture();
        foreach ([2, 9, 16, 23] as $day) {
            $this->observations($context, sprintf('2026-08-%02d 11:45:00 UTC', $day));
        }
        $report = $this->report($context);
        $this->assertSame(0, $report['windows'][0]['orders_created']);
        $this->assertNull($report['window_selection']['selections'][0]['selected_window']);
        $this->assertSame('uncalibrated', $report['window_selection']['statistical_state']);
        $this->assertSame('unknown', $report['baseline_state']);
        $this->assertFalse($report['alerts_enabled']);
    }

    public function test_early_rolling_profile_pairs_a_full_trailing_day_and_requires_observed_context(): void
    {
        $context = $this->fixture();
        $journal = $context['source']->sales_coverage;
        $journal['first_observed_at'] = CarbonImmutable::parse('2026-08-15', 'Europe/Berlin')->utc()->toIso8601String();
        $context['source']->forceFill(['sales_coverage' => $journal])->save();
        foreach ([16, 23] as $day) {
            $this->observations($context, sprintf('2026-08-%02d 11:45:00 UTC', $day), 2);
            $this->observations($context, sprintf('2026-08-%02d 13:00:00 UTC', $day - 1), 2);
        }
        $report = $this->report($context, minimum: '2', alpha: '0.01');
        $profile = $report['windows'][0]['historical_profile'];
        $this->assertSame('major_drop', $profile['stage']);
        $this->assertSame(2, $profile['daily_profile']['weekday_day_sample_count']);
        $this->assertSame(['same_weekday_band', 'weekday_daily_profile'], array_column($profile['candidates'], 'method'));
        $this->assertSame(1, $report['window_selection']['selections'][0]['selected_window']['width_hours']);
        $this->assertContains('profile_distribution_not_calibrated', $report['statistical_assessment']['reason_codes']);
        $journal['first_observed_at'] = CarbonImmutable::parse('2026-08-16', 'Europe/Berlin')->utc()->toIso8601String();
        $context['source']->forceFill(['sales_coverage' => $journal])->save();
        $report = $this->report($context, minimum: '2');
        $this->assertContains('weekday_daily_samples_missing', $report['windows'][0]['warmup_profile']['reason_codes']);
        $this->assertNull($report['window_selection']['selections'][0]['selected_window']);
    }

    public function test_unprojected_revision_blocks_known_actual_counts_and_window_selection(): void
    {
        $context = $this->fixture();
        DB::table('provider_object_states')->insert([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'integration_id' => $context['source']->id,
            'object_type' => 'order', 'object_id' => 'unprojected', 'content_hash' => hash('sha256', 'unprojected'), 'revision' => 1, 'observed_at' => now(),
        ]);
        $report = $this->report($context);
        $this->assertSame('awaiting_projection', $report['windows'][0]['metric_coverage']['orders_created']);
        $this->assertNull($report['windows'][0]['orders_created']);
        $this->assertNull($report['window_selection']['selections'][0]['selected_window']);
        $this->assertContains('target_orders_coverage_awaiting_projection', $report['windows'][0]['warmup_profile']['reason_codes']);
    }

    public function test_current_incident_does_not_block_observation_or_resolve_itself(): void
    {
        $context = $this->fixture();
        foreach ([2, 9, 16, 23] as $day) {
            $this->observations($context, sprintf('2026-08-%02d 11:45:00 UTC', $day), 20);
        }
        $incident = Incident::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'family' => 'checkout', 'component' => 'checkout',
            'fingerprint' => hash('sha256', 'current-window-test'), 'state' => 'open', 'severity' => 'warning', 'title_code' => 'CHECKOUT_FLOW_FAILED',
            'mode' => 'live', 'first_seen_at' => now(), 'last_seen_at' => now(), 'first_bad_at' => '2026-08-30 11:00:00',
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $report = $this->report($context);
        $this->assertContains('incident', $report['windows'][0]['metric_coverage']['baseline_exclusions']);
        $this->assertSame(1, $report['window_selection']['selections'][0]['selected_window']['width_hours']);
        $this->assertSame('open', $incident->fresh()->state);
        $this->assertFalse($report['alerts_enabled']);
    }

    public function test_current_promotion_blocks_comparison_but_preserves_covered_actual_counts(): void
    {
        $context = $this->fixture();
        foreach ([2, 9, 16, 23] as $day) {
            $this->observations($context, sprintf('2026-08-%02d 11:45:00 UTC', $day), 20);
        }
        $this->scope($context, fn () => app(SalesCoverage::class)->excludeDay($context['store']->id, '2026-08-30', 'promotion'));
        $report = $this->report($context);
        $this->assertSame(0, $report['windows'][0]['orders_created']);
        $this->assertSame('available', $report['windows'][0]['historical_profile']['structural_state']);
        $this->assertContains('promotion', $report['windows'][0]['warmup_profile']['reason_codes']);
        $this->assertNull($report['window_selection']['selections'][0]['selected_window']);
    }

    public function test_command_requires_explicit_lag_and_rejects_invalid_time_scope_and_policy(): void
    {
        $context = $this->fixture();
        $options = ['--tenant' => $context['tenant']->id, '--store' => $context['store']->id];
        $this->artisan('sales:window', $options)->expectsOutputToContain('sales_window_lag_invalid')->assertFailed();
        $this->artisan('sales:window', [...$options, '--lag-seconds' => '300'])->expectsOutputToContain('sales-rolling-v2')->assertSuccessful();
        $this->artisan('sales:window', [...$options, '--lag-seconds' => '300', '--alpha' => '0.01'])->expectsOutputToContain('"value": "0.01"')->assertSuccessful();
        foreach (['0', '1', '1e-3', ''] as $alpha) {
            $this->artisan('sales:window', [...$options, '--lag-seconds' => '300', '--alpha' => $alpha])->expectsOutputToContain('sales_count_alpha_invalid')->assertFailed();
        }
        foreach (['-1', '1.5', '2419201'] as $lag) {
            $this->artisan('sales:window', [...$options, '--lag-seconds' => $lag])->expectsOutputToContain('sales_window_lag_invalid')->assertFailed();
        }
        $this->artisan('sales:window', [...$options, '--lag-seconds' => '0', '--at' => '2026-02-30T12:00:00Z'])->expectsOutputToContain('sales_report_time_invalid')->assertFailed();
        $this->artisan('sales:window', [...$options, '--lag-seconds' => '0', '--at' => '2026-09-30T12:00:00Z'])->expectsOutputToContain('sales_window_range_invalid')->assertFailed();
        $this->artisan('sales:window', [...$options, '--lag-seconds' => '0', '--min-expected-orders' => '0'])->expectsOutputToContain('sales_window_min_expected_orders_invalid')->assertFailed();
        $this->artisan('sales:window', ['--store' => $context['store']->id, '--lag-seconds' => '0'])->expectsOutputToContain('sales_window_scope_invalid')->assertFailed();
        $this->assertNull(app(TenantContext::class)->tenantId());
    }

    public function test_opt_in_exact_test_distinguishes_lower_tail_from_normal_counts_without_incidents(): void
    {
        $context = $this->fixture();
        foreach ([2, 9, 16, 23] as $day) {
            $this->observations($context, sprintf('2026-08-%02d 11:45:00 UTC', $day), 20);
        }
        $this->assertSame('not_requested', $this->report($context)['statistical_assessment']['result']);
        $report = $this->report($context, alpha: '0.01');
        $this->assertSame('lower_tail_candidate', $report['statistical_assessment']['result']);
        $this->assertSame(4, $report['statistical_assessment']['test']['reference_windows']);
        $this->assertSame('80', $report['statistical_assessment']['test']['historical_total']);
        $this->assertSame(0, $report['statistical_assessment']['test']['observed']);
        $this->assertFalse($report['alerts_enabled']);
        $this->assertSame('unknown', $report['baseline_state']);
        $this->assertSame(0, Incident::query()->where('tenant_id', $context['tenant']->id)->count());
        $this->observations($context, '2026-08-30 12:00:00 UTC', 20);
        $normal = $this->report($context, alpha: '0.01');
        $this->assertSame('no_lower_tail_candidate', $normal['statistical_assessment']['result']);
        $this->assertSame('uncalibrated', $normal['statistical_assessment']['statistical_state']);
        $this->assertFalse($normal['alerts_enabled']);
    }

    public function test_variable_history_abstains_after_volume_selection(): void
    {
        $context = $this->fixture();
        foreach ([2 => 20, 9 => 20, 16 => 20, 23 => 100] as $day => $count) {
            $this->observations($context, sprintf('2026-08-%02d 11:45:00 UTC', $day), $count);
        }
        $report = $this->report($context, alpha: '0.01');
        $this->assertNotNull($report['window_selection']['selections'][0]['selected_window']);
        $this->assertSame('abstain', $report['statistical_assessment']['result']);
        $this->assertContains('historical_overdispersion_observed', $report['statistical_assessment']['reason_codes']);
        $this->assertNull($report['statistical_assessment']['test']['p_value']);
    }

    private function clock(string $now): void
    {
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function fixture(): array
    {
        $context = $this->context();
        $context['store']->forceFill(['status' => 'active', 'timezone' => 'Europe/Berlin', 'settings' => ['sales_calendar' => ['region' => 'DE-BE', 'local_holidays' => []]]])->save();
        $start = CarbonImmutable::now('Europe/Berlin')->startOfDay()->subDays(40)->utc()->toIso8601String();
        $context['source'] = Integration::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'provider' => 'woocommerce_api',
            'mode' => 'live', 'source_authority' => 'store_reported', 'status' => 'active', 'connector_version' => '1', 'capabilities' => [],
        ]);
        $context['source']->forceFill(['sales_coverage' => ['version' => 1, 'first_observed_at' => $start, 'intervals' => [['from' => $start, 'to' => CarbonImmutable::now()->toIso8601String()]], 'pending' => []]])->save();

        return $context;
    }

    private function observations(array $context, string $at, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->order($context, ['source_created_at' => CarbonImmutable::parse($at)->utc(), 'transaction_ref' => null]);
        }
    }

    private function report(array $context, string $lag = '300', ?CarbonImmutable $at = null, string $minimum = '20', string $mode = 'live', ?string $alpha = null): array
    {
        return $this->scope($context, fn () => app(SalesRollingReport::class)->report($context['store']->id, $mode, $at ?? CarbonImmutable::now(), $lag, $minimum, $alpha));
    }

    private function scope(array $context, callable $callback): mixed
    {
        return app(TenantContext::class)->scope($context['tenant']->id, 'test', $callback);
    }
}
