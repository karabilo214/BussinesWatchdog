<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Support\Sales\GermanSalesCalendar;
use App\Support\Sales\SalesCoverage;
use App\Support\Sales\SalesWarmup;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class GermanSalesCalendarTest extends TestCase
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

    public function test_verified_national_and_regional_dates_for_every_state(): void
    {
        $calendar = app(GermanSalesCalendar::class);
        $national = ['2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-05-14', '2026-05-25', '2026-10-03', '2026-12-25', '2026-12-26'];
        $regional = [
            '2026-01-06' => ['DE-BW', 'DE-BY', 'DE-ST'], '2026-03-08' => ['DE-BE', 'DE-MV'],
            '2026-04-05' => ['DE-BB'], '2026-05-24' => ['DE-BB'],
            '2026-06-04' => ['DE-BW', 'DE-BY', 'DE-HE', 'DE-NW', 'DE-RP', 'DE-SL'],
            '2026-08-15' => ['DE-SL'], '2026-09-20' => ['DE-TH'],
            '2026-10-31' => ['DE-BB', 'DE-HB', 'DE-HH', 'DE-MV', 'DE-NI', 'DE-SN', 'DE-ST', 'DE-SH', 'DE-TH'],
            '2026-11-01' => ['DE-BW', 'DE-BY', 'DE-NW', 'DE-RP', 'DE-SL'], '2026-11-18' => ['DE-SN'],
        ];
        foreach (GermanSalesCalendar::REGIONS as $region) {
            $config = ['region' => $region, 'local_holidays' => []];
            foreach ($national as $date) {
                $this->assertNotEmpty($calendar->classify($date, $config)['codes'], $region.':'.$date);
            }
            foreach ($regional as $date => $states) {
                $this->assertSame(in_array($region, $states, true), $calendar->classify($date, $config)['codes'] !== [], $region.':'.$date);
            }
            foreach (['2026-08-08', '2026-12-24', '2026-12-31'] as $ordinary) {
                $this->assertSame([], $calendar->classify($ordinary, $config)['codes']);
            }
        }
    }

    public function test_2027_movable_dates_municipal_flags_and_unknown_years(): void
    {
        $calendar = app(GermanSalesCalendar::class);
        foreach (['2027-03-26' => 'good_friday', '2027-03-29' => 'easter_monday', '2027-05-06' => 'ascension', '2027-05-17' => 'whit_monday', '2027-05-27' => 'corpus_christi'] as $date => $code) {
            $this->assertSame([$code], $calendar->classify($date, ['region' => 'DE-BY', 'local_holidays' => []])['codes']);
        }
        $this->assertSame(['repentance'], $calendar->classify('2027-11-17', ['region' => 'DE-SN', 'local_holidays' => []])['codes']);
        foreach (['2026-08-15' => 'assumption', '2026-08-08' => 'augsburg_peace'] as $date => $flag) {
            $this->assertSame([$flag], $calendar->classify($date, ['region' => 'DE-BY', 'local_holidays' => [$flag]])['codes']);
        }
        foreach (['DE-SN', 'DE-TH'] as $region) {
            $this->assertSame(['corpus_christi'], $calendar->classify('2026-06-04', ['region' => $region, 'local_holidays' => ['corpus_christi']])['codes']);
        }
        $this->assertSame('out_of_range', $calendar->classify('2028-01-01', ['region' => 'DE-BE', 'local_holidays' => []])['state']);
        $this->assertSame('not_configured', $calendar->classify('2026-10-03', ['region' => null, 'local_holidays' => []])['state']);
    }

    public function test_configuration_is_versioned_audited_and_preserves_other_settings_and_annotations(): void
    {
        $context = $this->context();
        $store = $context['store'];
        $store->forceFill(['settings' => ['checkout_layout' => ['submit_button' => '#pay']], 'sales_baseline_exclusions' => ['kept' => ['mode' => 'test', 'date' => '2026-10-20', 'kind' => 'promotion', 'timezone' => 'UTC', 'from' => '2026-10-20T00:00:00Z', 'to' => '2026-10-21T00:00:00Z']]])->save();
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $store->tenant_id]);
        $url = "/api/v1/stores/{$store->id}/sales-calendar";
        $body = ['region' => 'DE-BY', 'local_holidays' => ['assumption']];
        $this->putJson($url, $body)->assertStatus(428);
        $this->putJson($url, $body, ['If-Match' => '1'])->assertOk()->assertJsonPath('version', 2);
        $this->putJson($url, ['region' => 'DE-BE', 'local_holidays' => []], ['If-Match' => '1'])->assertConflict();
        $this->putJson($url, $body, ['If-Match' => '2'])->assertOk()->assertJsonPath('version', 2);
        $this->assertSame(1, AuditLog::query()->where('action', AuditLog::ACTION_STORE_SALES_CALENDAR_SET)->count());
        $this->assertSame('#pay', $store->fresh()->settings['checkout_layout']['submit_button']);
        $this->assertArrayHasKey('kept', $store->fresh()->sales_baseline_exclusions);
        $this->getJson("/api/v1/stores/{$store->id}/sales-exclusions")->assertOk()->assertJsonPath('calendar.region', 'DE-BY')->assertJsonPath('calendar_state', 'ready')->assertJsonPath('holidays.0.date', '2026-10-03');
        $this->putJson($url, ['region' => null, 'local_holidays' => []], ['If-Match' => '2'])->assertOk()->assertJsonPath('version', 3);
        $this->getJson("/api/v1/stores/{$store->id}/sales-exclusions")->assertJsonPath('calendar_state', 'not_configured')->assertJsonPath('holidays', []);
    }

    public function test_invalid_regions_flags_payloads_permissions_and_foreign_tenants_cannot_write(): void
    {
        $context = $this->context();
        $foreign = $this->context();
        $url = "/api/v1/stores/{$context['store']->id}/sales-calendar";
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
        foreach ([['region' => 'DE-XX', 'local_holidays' => []], ['region' => 'DE-BE', 'local_holidays' => ['assumption']], ['region' => null, 'local_holidays' => ['corpus_christi']], ['region' => 'DE-BY', 'local_holidays' => ['assumption', 'assumption']], ['region' => 'DE-BY', 'local_holidays' => [], 'date' => '2026-10-30']] as $body) {
            $this->putJson($url, $body, ['If-Match' => '1'])->assertUnprocessable();
        }
        $this->putJson("/api/v1/stores/{$foreign['store']->id}/sales-calendar", ['region' => 'DE-BE', 'local_holidays' => []], ['If-Match' => '1'])->assertNotFound();
        $viewer = $this->context('viewer', $context['tenant'], $context['store'], $context['integration']);
        $this->actingAs($viewer['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->putJson($url, ['region' => 'DE-BE', 'local_holidays' => []], ['If-Match' => '1'])->assertForbidden();
        $this->assertArrayNotHasKey('sales_calendar', $context['store']->fresh()->settings ?? []);
        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_STORE_SALES_CALENDAR_SET)->count());
    }

    public function test_holidays_exclude_normal_samples_use_sunday_metadata_and_never_enable_alerts(): void
    {
        $context = $this->context();
        $store = $context['store'];
        $store->forceFill(['status' => 'active', 'timezone' => 'Europe/Berlin', 'settings' => ['sales_calendar' => ['region' => 'DE-BE', 'local_holidays' => [], 'version' => 2]]])->save();
        $source = Integration::query()->create(['tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'provider' => 'woocommerce_api', 'mode' => 'live', 'source_authority' => 'store_reported', 'status' => 'active', 'connector_version' => '1', 'capabilities' => []]);
        $source->forceFill(['sales_coverage' => ['version' => 1, 'first_observed_at' => '2026-10-01T00:00:00Z', 'intervals' => [['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-30T12:00:00Z']]]])->save();
        app(TenantContext::class)->scope($store->tenant_id, 'test', function () use ($store): void {
            foreach (['live', 'test'] as $mode) {
                $warmup = app(SalesWarmup::class)->report($store, $mode, CarbonImmutable::now());
                $this->assertSame(27, $warmup['eligible_days']);
                $this->assertFalse($warmup['alerts_enabled']);
                $this->assertNotContains('holiday_calendar_not_configured', $warmup['reason_codes']);
                $holiday = $warmup['days'][1];
                $this->assertSame('2026-10-03', $holiday['date']);
                $this->assertSame(['unity'], $holiday['holiday_codes']);
                $this->assertSame(7, $holiday['comparison_weekday']);
                $metric = app(SalesCoverage::class)->forBuckets($store, $mode, [['from' => '2026-10-02T23:00:00Z', 'to' => '2026-10-03T00:00:00Z']])[0];
                $this->assertSame('complete', $metric['orders_created']);
                $this->assertFalse($metric['baseline_eligible']);
            }
        });
    }

    public function test_utc_bucket_crossing_local_midnight_cannot_hide_a_holiday(): void
    {
        $context = $this->context();
        $store = $context['store'];
        $store->forceFill(['status' => 'active', 'timezone' => 'Asia/Kathmandu', 'settings' => ['sales_calendar' => ['region' => 'DE-BE', 'local_holidays' => [], 'version' => 2]]])->save();
        $source = Integration::query()->create(['tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'provider' => 'woocommerce_api', 'mode' => 'live', 'source_authority' => 'store_reported', 'status' => 'active', 'connector_version' => '1', 'capabilities' => []]);
        $source->forceFill(['sales_coverage' => ['version' => 1, 'first_observed_at' => '2026-10-01T00:00:00Z', 'intervals' => [['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-30T12:00:00Z']]]])->save();
        app(TenantContext::class)->scope($store->tenant_id, 'test', function () use ($store): void {
            $metrics = app(SalesCoverage::class)->forBuckets($store, 'live', [
                ['from' => '2026-10-02T18:00:00Z', 'to' => '2026-10-02T19:00:00Z'],
                ['from' => '2026-10-02T18:00:00Z', 'to' => '2026-10-02T18:15:00Z'],
                ['from' => '2026-10-02T18:15:00Z', 'to' => '2026-10-02T19:00:00Z'],
            ]);
            $this->assertSame(['unity'], $metrics[0]['holiday_codes']);
            $this->assertFalse($metrics[0]['baseline_eligible']);
            $this->assertContains('local_day_boundary', $metrics[0]['baseline_exclusions']);
            $this->assertNull($metrics[0]['comparison_weekday']);
            $this->assertSame([], $metrics[1]['holiday_codes']);
            $this->assertTrue($metrics[1]['baseline_eligible']);
            $this->assertSame(7, $metrics[2]['comparison_weekday']);
        });
    }

    public function test_unknown_year_is_not_a_zero_holiday_calendar(): void
    {
        $context = $this->context();
        $store = $context['store'];
        $store->forceFill(['settings' => ['sales_calendar' => ['region' => 'DE-BE', 'local_holidays' => [], 'version' => 2]]])->save();
        app(TenantContext::class)->scope($store->tenant_id, 'test', function () use ($store): void {
            $metric = app(SalesCoverage::class)->forBuckets($store, 'live', [['from' => '2028-01-01T00:00:00Z', 'to' => '2028-01-02T00:00:00Z']])[0];
            $this->assertSame('out_of_range', $metric['calendar_state']);
            $this->assertNull($metric['comparison_weekday']);
            $this->assertContains('holiday_calendar_out_of_range', $metric['baseline_exclusions']);
            $this->assertFalse($metric['baseline_eligible']);
        });
    }
}
