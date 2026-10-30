<?php

namespace Tests\Feature\Sales;

use App\Models\EventInbox;
use App\Models\Incident;
use App\Models\Integration;
use App\Support\Sales\SalesCoverage;
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

class SalesCoverageTest extends TestCase
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

    public function test_complete_read_proves_only_order_creation_not_money_or_a_baseline(): void
    {
        $context = $this->salesContext();
        $this->read($context, '08:00:00', '10:00:00');
        $this->read($context, '08:00:00', '10:00:00');
        $report = $this->history($context);
        $metric = $report['buckets'][0]['metric_coverage'];
        $this->assertSame('complete', $metric['orders_created']);
        $this->assertSame('unknown', $metric['store_paid_markers']);
        $this->assertSame('unknown', $metric['orders_with_capture']);
        $this->assertTrue($metric['baseline_eligible']);
        $this->assertSame('unknown', $report['baseline_state']);
        $this->assertFalse($report['alerts_enabled']);
        $this->assertCount(1, $context['source']->fresh()->sales_coverage['intervals']);
        $this->assertSame('2026-10-30T12:00:00+00:00', $context['source']->fresh()->sales_coverage['first_observed_at']);
    }

    public function test_inbox_projection_is_a_barrier_and_scheduler_can_finish_it_later(): void
    {
        $context = $this->salesContext();
        $event = $this->event($context, 'received');
        $this->read($context, '08:00:00', '10:00:00');
        $this->assertSame([], $context['source']->fresh()->sales_coverage['intervals']);
        $this->assertSame('awaiting_projection', $this->history($context)['buckets'][0]['metric_coverage']['orders_created']);
        $this->artisan('sales:finalize-coverage')->assertSuccessful();
        $this->assertSame([], $context['source']->fresh()->sales_coverage['intervals']);

        $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
        $this->artisan('sales:finalize-coverage')->assertSuccessful();
        $this->assertSame('complete', $this->history($context)['buckets'][0]['metric_coverage']['orders_created']);
        $this->assertNull(app(TenantContext::class)->tenantId());
    }

    public function test_quarantined_or_dead_letter_orders_never_confirm_coverage(): void
    {
        foreach (['quarantined', 'dead_letter'] as $state) {
            $context = $this->salesContext();
            $this->event($context, $state);
            $this->read($context, '08:00:00', '10:00:00');
            $this->artisan('sales:finalize-coverage')->assertSuccessful();
            $this->assertSame([], $context['source']->fresh()->sales_coverage['intervals']);
            $this->assertFalse($this->history($context)['buckets'][0]['metric_coverage']['baseline_eligible']);
        }
    }

    public function test_rejected_ingestion_without_an_inbox_row_cannot_prove_zero_orders(): void
    {
        $context = $this->salesContext();
        DB::table('provider_object_states')->insert([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'integration_id' => $context['source']->id,
            'object_type' => 'order', 'object_id' => 'unprojected-order', 'content_hash' => hash('sha256', 'rejected'), 'revision' => 1, 'observed_at' => now(),
        ]);
        $this->read($context, '08:00:00', '10:00:00');
        $this->assertSame([], $context['source']->fresh()->sales_coverage['intervals']);
        $this->assertSame('awaiting_projection', $this->history($context)['buckets'][0]['metric_coverage']['orders_created']);
    }

    public function test_partial_or_skipped_scans_do_not_prove_coverage(): void
    {
        $context = $this->salesContext();
        $this->read($context, '08:00:00', '10:00:00', complete: false);
        $this->read($context, '08:00:00', '10:00:00', withoutSkipped: false);
        $this->assertSame([], $context['source']->fresh()->sales_coverage['intervals']);
        $this->assertSame('unknown', $this->history($context)['buckets'][0]['metric_coverage']['orders_created']);
    }

    public function test_adjacent_intervals_merge_but_a_hole_does_not_disappear(): void
    {
        $context = $this->salesContext();
        $this->read($context, '08:00:00', '08:30:00');
        $this->read($context, '09:00:00', '10:00:00');
        $report = $this->history($context);
        $this->assertSame('unknown', $report['buckets'][0]['metric_coverage']['orders_created']);
        $this->assertSame('complete', $report['buckets'][1]['metric_coverage']['orders_created']);
        $this->read($context, '08:30:00', '09:00:00');
        $this->assertSame('complete', $this->history($context)['buckets'][0]['metric_coverage']['orders_created']);
        $this->assertCount(1, $context['source']->fresh()->sales_coverage['intervals']);
    }

    public function test_exclusions_are_mode_scoped_and_dst_days_use_real_boundaries(): void
    {
        $context = $this->salesContext();
        $context['store']->forceFill(['timezone' => 'Europe/Berlin'])->save();
        $this->scope($context, fn (SalesCoverage $coverage) => $coverage->excludeDay($context['store']->id, '2026-10-25', 'promotion'));
        $entries = $context['store']->fresh()->sales_baseline_exclusions;
        $entry = array_values($entries)[0];
        $this->assertSame('2026-10-24T22:00:00+00:00', $entry['from']);
        $this->assertSame('2026-10-25T23:00:00+00:00', $entry['to']);
        $this->scope($context, fn (SalesCoverage $coverage) => $coverage->recordRead($context['source']->id, CarbonImmutable::parse('2026-10-24T00:00:00Z'), CarbonImmutable::parse('2026-10-26T00:00:00Z'), 'audit', true, true));
        $live = $this->history($context, '2026-10-25T00:00:00Z', '2026-10-25T02:00:00Z');
        $this->assertSame(['promotion'], $live['buckets'][0]['metric_coverage']['baseline_exclusions']);
        $this->assertFalse($live['buckets'][0]['metric_coverage']['baseline_eligible']);
        $test = $this->history($context, '2026-10-25T00:00:00Z', '2026-10-25T02:00:00Z', 'test');
        $this->assertSame([], $test['buckets'][0]['metric_coverage']['baseline_exclusions']);
    }

    public function test_an_incident_excludes_the_whole_local_day_in_its_mode(): void
    {
        $context = $this->salesContext();
        $this->read($context, '08:00:00', '10:00:00');
        Incident::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'family' => 'checkout', 'component' => 'checkout',
            'fingerprint' => hash('sha256', (string) Str::uuid()), 'state' => 'resolved', 'severity' => 'warning', 'title_code' => 'CHECKOUT_FLOW_FAILED',
            'mode' => 'live', 'first_seen_at' => now(), 'last_seen_at' => now(), 'first_bad_at' => '2026-10-30 09:20:00', 'resolved_at' => '2026-10-30 09:30:00',
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $metric = $this->history($context)['buckets'][0]['metric_coverage'];
        $this->assertSame('complete', $metric['orders_created']);
        $this->assertFalse($metric['baseline_eligible']);
        $this->assertSame(['incident'], $metric['baseline_exclusions']);
    }

    public function test_scheduler_records_current_pauses_for_both_modes(): void
    {
        $context = $this->salesContext();
        $this->read($context, '08:00:00', '10:00:00');
        $context['store']->forceFill(['status' => 'paused'])->save();
        $this->artisan('sales:finalize-coverage')->assertSuccessful();
        $this->assertCount(2, $context['store']->fresh()->sales_baseline_exclusions);
        $context['store']->forceFill(['status' => 'active'])->save();
        $this->assertSame(['pause'], $this->history($context)['buckets'][0]['metric_coverage']['baseline_exclusions']);
    }

    public function test_organisation_pause_is_retained_and_old_exclusions_are_pruned(): void
    {
        $context = $this->salesContext();
        $this->read($context, '08:00:00', '10:00:00');
        $context['tenant']->forceFill(['status' => 'suspended'])->save();
        $this->artisan('sales:finalize-coverage')->assertSuccessful();
        $this->assertCount(2, $context['store']->fresh()->sales_baseline_exclusions);
        $context['tenant']->forceFill(['status' => 'active'])->save();
        $context['store']->forceFill(['sales_baseline_exclusions' => [...$context['store']->fresh()->sales_baseline_exclusions,
            'old' => ['from' => '2026-09-01T00:00:00Z', 'to' => '2026-09-02T00:00:00Z', 'mode' => 'live', 'kind' => 'promotion'],
        ]])->save();
        $this->artisan('sales:finalize-coverage')->assertSuccessful();
        $this->assertCount(2, $context['store']->fresh()->sales_baseline_exclusions);
    }

    public function test_foreign_tenant_cannot_record_or_exclude_another_store(): void
    {
        $context = $this->salesContext();
        $foreign = $this->salesContext();
        try {
            $this->scope($context, fn (SalesCoverage $coverage) => $coverage->recordRead($foreign['source']->id, CarbonImmutable::now()->subHour(), CarbonImmutable::now(), 'audit', true, true));
            $this->fail('Foreign source accepted');
        } catch (ModelNotFoundException) {
            $this->assertNull($foreign['source']->fresh()->sales_coverage);
        }
        $this->expectException(ModelNotFoundException::class);
        $this->scope($context, fn (SalesCoverage $coverage) => $coverage->excludeDay($foreign['store']->id, '2026-10-30', 'promotion'));
    }

    public function test_cannot_use_provider_reads_or_delta_watermarks_as_order_read_proof(): void
    {
        $context = $this->salesContext();
        foreach ([$context['integration']->id, $context['source']->id] as $id) {
            try {
                $this->scope($context, fn (SalesCoverage $coverage) => $coverage->recordRead($id, CarbonImmutable::now()->subHour(), CarbonImmutable::now(), $id === $context['source']->id ? 'delta' : 'audit', true, true));
                $this->fail('Invalid evidence accepted');
            } catch (\InvalidArgumentException) {
                $this->assertNull(Integration::query()->findOrFail($id)->sales_coverage);
            }
        }
    }

    private function salesContext(): array
    {
        $context = $this->context();
        $context['store']->forceFill(['status' => 'active'])->save();
        $context['source'] = Integration::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'provider' => 'woocommerce_api',
            'mode' => 'live', 'source_authority' => 'store_reported', 'status' => 'active',
            'connector_version' => '1', 'capabilities' => [], 'last_successful_sync_at' => now(),
        ]);

        return $context;
    }

    private function scope(array $context, callable $callback): mixed
    {
        return app(TenantContext::class)->scope($context['tenant']->id, 'test', fn () => $callback(app(SalesCoverage::class)));
    }

    private function read(array $context, string $from, string $to, bool $complete = true, bool $withoutSkipped = true): void
    {
        $this->scope($context, fn (SalesCoverage $coverage) => $coverage->recordRead($context['source']->id, CarbonImmutable::parse('2026-10-30 '.$from.' UTC'), CarbonImmutable::parse('2026-10-30 '.$to.' UTC'), 'recent', $complete, $withoutSkipped));
    }

    private function history(array $context, string $from = '2026-10-30T08:00:00Z', string $to = '2026-10-30T10:00:00Z', string $mode = 'live'): array
    {
        return $this->scope($context, fn () => app(SalesHistory::class)->report($context['store']->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to), $mode));
    }

    private function event(array $context, string $status): EventInbox
    {
        return EventInbox::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'integration_id' => $context['source']->id,
            'provider_event_id' => (string) Str::uuid(), 'schema_version' => '1.0', 'event_type' => 'order.snapshot', 'aggregate_type' => 'order',
            'aggregate_external_id' => '42', 'aggregate_revision' => 1, 'occurred_at' => now()->subHour(), 'observed_at' => now(), 'received_at' => now(),
            'is_synthetic' => false, 'payload' => [], 'payload_hash' => hash('sha256', 'test'), 'status' => $status, 'attempt_count' => 0,
            'next_attempt_at' => now(), 'request_id' => (string) Str::uuid(),
        ]);
    }
}
