<?php

namespace Tests\Feature\Sales;

use App\Exceptions\Tenancy\MissingTenantContext;
use App\Models\Integration;
use App\Support\Sales\SalesCoverage;
use App\Support\Sales\SalesHistory;
use App\Support\Sales\SalesWarmup;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class SalesWarmupTest extends TestCase
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

    public static function stages(): array
    {
        return [[0, 'collecting', 7], [6, 'collecting', 1], [7, 'stoppage_only', 7], [13, 'stoppage_only', 1], [14, 'major_drop', 14], [27, 'major_drop', 1], [28, 'full', 0]];
    }

    public function test_closed_observed_days_determine_stage_without_enabling_alerts(): void
    {
        foreach (self::stages() as [$days, $stage, $remaining]) {
            $report = $this->report($this->fixture($days));
            $this->assertSame($days, $report['eligible_days']);
            $this->assertSame($stage, $report['stage']);
            $this->assertSame($remaining, $report['additional_eligible_days_required']);
            $this->assertFalse($report['alerts_enabled']);
            $this->assertSame('unknown', $report['baseline_state']);
            $this->assertCount(28, $report['days']);
        }
    }

    public function test_backfill_and_current_day_do_not_grant_progress(): void
    {
        $context = $this->fixture(28);
        $journal = $context['source']->sales_coverage;
        $journal['first_observed_at'] = now()->toIso8601String();
        $context['source']->forceFill(['sales_coverage' => $journal])->save();
        $report = $this->report($context);
        $this->assertSame(0, $report['eligible_days']);
        $this->assertSame('2026-10-29', $report['days'][27]['date']);
    }

    public function test_partial_days_and_mode_scoped_exclusions_reduce_stage(): void
    {
        $context = $this->fixture(14);
        $journal = $context['source']->sales_coverage;
        $start = CarbonImmutable::parse($journal['intervals'][0]['from']);
        $journal['intervals'] = [
            ['from' => $start->toIso8601String(), 'to' => $start->addHour()->toIso8601String()],
            ['from' => $start->addHours(2)->toIso8601String(), 'to' => CarbonImmutable::now()->toIso8601String()],
        ];
        $context['source']->forceFill(['sales_coverage' => $journal])->save();
        $this->scope($context, fn () => app(SalesCoverage::class)->excludeDay($context['store']->id, '2026-10-29', 'promotion'));
        $this->assertSame(12, $this->report($context)['eligible_days']);
        $this->assertSame(13, $this->report($context, 'test')['eligible_days']);
        $this->assertSame('stoppage_only', $this->report($context)['stage']);
    }

    public function test_dst_days_count_once_and_finalizer_retains_28_closed_days(): void
    {
        foreach (['2026-10-30 12:00:00 UTC' => 25, '2026-04-01 12:00:00 UTC' => 23] as $now => $hours) {
            Carbon::setTestNow($now);
            CarbonImmutable::setTestNow($now);
            $context = $this->fixture(28);
            $this->scope($context, fn () => app(SalesCoverage::class)->finalize($context['source']->id));
            $report = $this->report($context);
            $this->assertSame(28, $report['eligible_days']);
            $lengths = array_map(fn (array $day): int => (int) CarbonImmutable::parse($day['from'])->diffInHours(CarbonImmutable::parse($day['to'])), $report['days']);
            $this->assertContains($hours, $lengths);
        }
    }

    public function test_report_is_read_only_and_does_not_depend_on_history_start(): void
    {
        $context = $this->fixture(7);
        $before = $context['source']->fresh()->getAttributes();
        $report = $this->scope($context, fn () => app(SalesHistory::class)->report($context['store']->id, CarbonImmutable::now()->subHour(), CarbonImmutable::now()));
        $this->assertSame(7, $report['order_history_warmup']['eligible_days']);
        $this->assertSame('unverified_history', $report['warmup_stage']);
        $this->assertFalse($report['alerts_enabled']);
        $this->assertSame($before, $context['source']->fresh()->getAttributes());
    }

    public function test_missing_journal_grants_no_progress_and_missing_context_fails_closed(): void
    {
        $context = $this->fixture(28);
        $context['source']->forceFill(['sales_coverage' => null])->save();
        $this->assertSame(0, $this->report($context)['eligible_days']);
        $this->expectException(MissingTenantContext::class);
        app(SalesWarmup::class)->report($context['store'], 'live', CarbonImmutable::now());
    }

    public function test_current_pause_does_not_erase_earlier_days_but_retained_pauses_do(): void
    {
        $context = $this->fixture(14);
        $context['store']->forceFill(['status' => 'paused'])->save();
        $this->assertSame(14, $this->report($context)['eligible_days']);
        $this->scope($context, fn () => app(SalesCoverage::class)->excludeDay($context['store']->id, '2026-10-29', 'pause'));
        $this->assertSame(13, $this->report($context)['eligible_days']);
    }

    public function test_foreign_store_is_rejected(): void
    {
        $context = $this->fixture(28);
        $other = $this->fixture(28);
        $this->expectException(\InvalidArgumentException::class);
        $this->scope($other, fn () => app(SalesWarmup::class)->report($context['store'], 'live', CarbonImmutable::now()));
    }

    private function fixture(int $days): array
    {
        $context = $this->context();
        $context['store']->forceFill(['status' => 'active', 'timezone' => 'Europe/Berlin'])->save();
        $start = CarbonImmutable::now('Europe/Berlin')->startOfDay()->subDays($days)->utc()->toIso8601String();
        $context['source'] = Integration::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'provider' => 'woocommerce_api',
            'mode' => 'live', 'source_authority' => 'store_reported', 'status' => 'active',
            'connector_version' => '1', 'capabilities' => [],
        ]);
        $context['source']->forceFill([
            'sales_coverage' => ['version' => 1, 'first_observed_at' => $start, 'intervals' => [['from' => $start, 'to' => CarbonImmutable::now()->toIso8601String()]], 'pending' => []],
        ])->save();

        return $context;
    }

    private function report(array $context, string $mode = 'live'): array
    {
        return $this->scope($context, fn () => app(SalesWarmup::class)->report($context['store'], $mode, CarbonImmutable::now()));
    }

    private function scope(array $context, callable $callback): mixed
    {
        return app(TenantContext::class)->scope($context['tenant']->id, 'test', $callback);
    }
}
