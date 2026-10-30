<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Support\Sales\SalesCoverage;
use App\Support\Sales\SalesWarmup;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class SalesExclusionsApiTest extends TestCase
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

    public function test_manual_annotations_are_mode_scoped_idempotent_and_audited_and_affect_warmup(): void
    {
        $context = $this->context();
        $store = $context['store'];
        $store->forceFill(['status' => 'active', 'timezone' => 'Europe/Berlin'])->save();
        $source = Integration::query()->create([
            'tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'provider' => 'woocommerce_api', 'mode' => 'live',
            'source_authority' => 'store_reported', 'status' => 'active', 'connector_version' => '1', 'capabilities' => [],
        ]);
        $source->forceFill(['sales_coverage' => ['version' => 1, 'first_observed_at' => '2026-10-01T00:00:00Z', 'intervals' => [['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-30T12:00:00Z']]]])->save();
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $store->tenant_id]);
        $url = "/api/v1/stores/{$store->id}/sales-exclusions";
        $body = ['date' => '2026-10-25', 'kind' => 'promotion', 'mode' => 'live', 'excluded' => true];
        $this->getJson($url)->assertOk()->assertJsonPath('min_date', '2026-10-02')->assertJsonPath('entries', []);
        foreach ([1, 2] as $repeat) {
            $this->putJson($url, $body)->assertOk()->assertJsonPath('entries.0.from', '2026-10-24T22:00:00+00:00')->assertJsonPath('entries.0.to', '2026-10-25T23:00:00+00:00')->assertJsonPath('entries.0.editable', true);
        }
        $this->assertSame(1, AuditLog::query()->where('action', AuditLog::ACTION_STORE_SALES_EXCLUSION_SET)->count());
        $this->getJson($url.'?mode=test')->assertOk()->assertJsonPath('entries', []);
        $warmup = app(TenantContext::class)->scope($store->tenant_id, 'test', fn () => app(SalesWarmup::class)->report($store, 'live', CarbonImmutable::now()));
        $this->assertSame(27, $warmup['eligible_days']);
        $this->putJson($url, [...$body, 'excluded' => false])->assertOk()->assertJsonPath('entries', []);
        $this->putJson($url, [...$body, 'excluded' => false])->assertOk();
        $this->assertSame(2, AuditLog::query()->where('action', AuditLog::ACTION_STORE_SALES_EXCLUSION_SET)->count());
        $this->assertSame(28, app(TenantContext::class)->scope($store->tenant_id, 'test', fn () => app(SalesWarmup::class)->report($store, 'live', CarbonImmutable::now()))['eligible_days']);
    }

    public function test_automatic_and_other_annotations_are_preserved_and_stored_timezones_do_not_shift(): void
    {
        $context = $this->context();
        $store = $context['store'];
        $store->forceFill(['timezone' => 'Europe/Berlin'])->save();
        app(TenantContext::class)->scope($store->tenant_id, 'test', fn () => app(SalesCoverage::class)->excludeDay($store->id, '2026-10-29', 'pause'));
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $store->tenant_id]);
        $url = "/api/v1/stores/{$store->id}/sales-exclusions";
        $body = ['date' => '2026-10-29', 'kind' => 'holiday', 'mode' => 'live', 'excluded' => true];
        $this->putJson($url, $body)->assertOk()->assertJsonPath('entries.1.editable', false);
        $store->forceFill(['timezone' => 'America/New_York'])->save();
        $this->putJson($url, $body)->assertOk()->assertJsonPath('entries.0.timezone', 'Europe/Berlin');
        $this->putJson($url, [...$body, 'kind' => 'pause', 'excluded' => false])->assertUnprocessable();
        $this->putJson($url, [...$body, 'excluded' => false])->assertOk()->assertJsonPath('entries.0.kind', 'pause');
        $this->assertCount(1, $store->fresh()->sales_baseline_exclusions);
    }

    public function test_invalid_dates_modes_payloads_and_automatic_edits_cannot_write(): void
    {
        $context = $this->context();
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
        $url = "/api/v1/stores/{$context['store']->id}/sales-exclusions";
        $body = ['date' => '2026-10-29', 'kind' => 'promotion', 'mode' => 'live', 'excluded' => true];
        foreach ([['date' => '2026-02-30'], ['date' => '2026-10-31'], ['date' => '2026-10-01'], ['mode' => 'all'], ['kind' => 'pause'], ['kind' => 'data_gap'], ['excluded' => 'true'], ['from' => '2026-01-01'], ['note' => 'customer@example.test']] as $override) {
            $this->putJson($url, [...$body, ...$override])->assertUnprocessable();
        }
        $this->getJson($url.'?mode=all')->assertUnprocessable();
        $this->assertNull($context['store']->fresh()->sales_baseline_exclusions);
        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_STORE_SALES_EXCLUSION_SET)->count());
    }

    public function test_viewers_cannot_write_and_foreign_stores_are_hidden(): void
    {
        $context = $this->context();
        $viewer = $this->context('viewer', $context['tenant'], $context['store'], $context['integration']);
        $foreign = $this->context();
        $this->actingAs($viewer['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
        $url = "/api/v1/stores/{$context['store']->id}/sales-exclusions";
        $this->getJson($url)->assertOk();
        $this->putJson($url, ['date' => '2026-10-29', 'kind' => 'promotion', 'mode' => 'live', 'excluded' => true])->assertForbidden();
        $this->getJson("/api/v1/stores/{$foreign['store']->id}/sales-exclusions")->assertNotFound();
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->putJson("/api/v1/stores/{$foreign['store']->id}/sales-exclusions", ['date' => '2026-10-29', 'kind' => 'promotion', 'mode' => 'live', 'excluded' => true])->assertNotFound();
        $this->assertNull($foreign['store']->fresh()->sales_baseline_exclusions);
    }
}
