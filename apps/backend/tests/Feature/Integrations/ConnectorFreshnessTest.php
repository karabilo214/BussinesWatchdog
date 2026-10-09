<?php

namespace Tests\Feature\Integrations;

use App\Models\DomainOutbox;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\NotificationDelivery;
use App\Models\ReconciliationDirtySubject;
use App\Models\ReconciliationFinding;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Notifications\Channels\NotificationSenderRegistry;
use App\Support\Notifications\NotificationDeliveryWorker;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Reconciliation\OrderReconciliationService;
use App\Support\Reconciliation\UnmatchedPaymentScanner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\FakeNotificationSender;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class ConnectorFreshnessTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_heartbeat_records_the_plugin_self_report(): void
    {
        $context = $this->context();
        [$connector, $secret] = $this->connector($context, now());

        $this->heartbeat($secret, ['backlog_count' => 12, 'oldest_pending_at' => '2026-10-09T11:50:00+00:00', 'plugin_version' => '0.4.0'])->assertOk();

        $connector->refresh();
        $this->assertTrue($connector->last_heartbeat_at->equalTo(now()));
        $this->assertSame(12, $connector->health['heartbeat']['backlog_count']);
        $this->assertSame('2026-10-09T11:50:00.000000Z', $connector->health['heartbeat']['oldest_pending_at']);
        $this->assertSame('0.4.0', $connector->health['heartbeat']['plugin_version']);
    }

    public function test_three_missed_heartbeats_mark_the_connector_stale_and_open_one_incident(): void
    {
        $context = $this->context();
        [$connector, $secret] = $this->connector($context, now());
        $this->heartbeat($secret, ['backlog_count' => 0, 'plugin_version' => '0.4.0']);
        $this->check();
        $this->assertSame(ConnectorFreshness::STATE_FRESH, $connector->fresh()->health['freshness']['state']);

        Carbon::setTestNow(now()->addMinutes(14));
        $this->check();
        $this->assertSame(0, Incident::query()->count());

        Carbon::setTestNow(now()->addMinute());
        $this->check();
        $this->check();

        $connector->refresh();
        $this->assertSame(Integration::STATUS_DEGRADED, $connector->status);
        $this->assertSame(ConnectorFreshness::STATE_STALE, $connector->health['freshness']['state']);
        $incident = Incident::query()->sole();
        $this->assertSame(ConnectorFreshness::FAMILY, $incident->family);
        $this->assertSame(ConnectorFreshness::TITLE_STALE, $incident->title_code);
        $this->assertSame(1, DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->count());
    }

    public function test_a_returning_heartbeat_resolves_the_incident_and_requeues_the_store(): void
    {
        $context = $this->context();
        [$connector, $secret] = $this->connector($context, now()->subHour());
        $order = $this->order($context, ['integration_id' => $connector->id]);
        $this->check();
        $this->assertSame(Incident::STATE_OPEN, Incident::query()->sole()->state);

        $this->heartbeat($secret, ['backlog_count' => 0, 'plugin_version' => '0.4.0']);
        $this->check();

        $connector->refresh();
        $this->assertSame(Integration::STATUS_ACTIVE, $connector->status);
        $incident = Incident::query()->sole();
        $this->assertSame(Incident::STATE_RESOLVED, $incident->state);
        $this->assertSame('auto_resolved_connector_fresh', $incident->resolution_reason);
        $this->assertSame(ReconciliationDirtySubject::REASON_CONNECTOR_RECOVERED, ReconciliationDirtySubject::query()->where('subject_id', $order->id)->sole()->reason);
        $this->assertSame(2, DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->count());
    }

    public function test_a_new_connector_warms_up_before_it_can_be_stale(): void
    {
        $context = $this->context();
        [$connector] = $this->connector($context, null, newlyPaired: true);
        $this->check();
        $this->assertSame(ConnectorFreshness::STATE_WARMING_UP, $connector->fresh()->health['freshness']['state']);

        Carbon::setTestNow(now()->addMinutes(15));
        $this->check();
        $this->assertSame('no_heartbeat_since_pairing', $connector->fresh()->health['freshness']['reason']);
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_a_delivery_backlog_is_partial_and_shares_the_incident_with_staleness(): void
    {
        $context = $this->context();
        [$connector, $secret] = $this->connector($context, now());
        $this->heartbeat($secret, ['backlog_count' => 4000, 'oldest_pending_at' => now()->subMinutes(61)->toJSON(), 'plugin_version' => '0.4.0']);
        $this->check();

        $this->assertSame(ConnectorFreshness::STATE_PARTIAL, $connector->fresh()->health['freshness']['state']);
        $this->assertSame(ConnectorFreshness::TITLE_DELIVERY_DELAYED, Incident::query()->sole()->title_code);

        Carbon::setTestNow(now()->addMinutes(20));
        $this->check();

        $incident = Incident::query()->sole();
        $this->assertSame(Incident::STATE_OPEN, $incident->state);
        $this->assertSame(ConnectorFreshness::TITLE_STALE, $incident->title_code);
        $this->assertSame(1, DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->count());
    }

    public function test_money_checks_are_unknown_while_store_data_is_stale(): void
    {
        $context = $this->context();
        [$connector] = $this->connector($context, now()->subHour());
        $order = $this->order($context, ['integration_id' => $connector->id, 'paid_marked_at' => now()->subHours(2)]);
        $this->check();

        $run = app(OrderReconciliationService::class)->evaluate($order);
        $finding = $run->findings()->sole();
        $this->assertSame(ReconciliationFinding::STATUS_UNKNOWN, $finding->status);
        $this->assertSame(ConnectorFreshness::REASON_STORE_DATA_STALE, $finding->reason_code);
        $this->assertTrue($run->coverage_snapshot['store_data_stale']);

        $scan = app(UnmatchedPaymentScanner::class)->scan($context['store']);
        $this->assertSame(0, $scan->findings()->count());
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_provider_integrations_are_not_heartbeat_checked(): void
    {
        $context = $this->context();
        $context['integration']->forceFill(['created_at' => now()->subDay()])->save();

        $this->assertSame(['checked' => 0, 'changed' => 0], app(ConnectorFreshness::class)->checkAll());
        $this->assertSame(Integration::STATUS_ACTIVE, $context['integration']->fresh()->status);
    }

    public function test_the_notification_says_data_is_missing_not_that_sales_stopped(): void
    {
        $sender = new FakeNotificationSender;
        $this->app->instance(NotificationSenderRegistry::class, new NotificationSenderRegistry([$sender]));
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant'], 'alerts@example.test', ['locale' => 'ru']);
        $this->connector($context, Carbon::parse('2026-10-09 11:00:00', 'UTC'));

        $this->check();
        app(DomainOutboxDispatcher::class)->dispatchDue();
        app(NotificationDeliveryWorker::class)->deliverDue();

        $message = $sender->sent[0]['message'];
        $this->assertStringContainsString('нет данных от плагина магазина', $message->subject);
        $this->assertStringContainsString('последний сигнал 2026-10-09 14:00', $message->body);
        $this->assertStringContainsString('не означает, что продажи остановились', $message->body);
        $this->assertStringContainsString('WP-Cron', $message->body);
        $this->assertSame(NotificationDelivery::STATUS_SENT, NotificationDelivery::query()->sole()->status);
    }

    public function test_the_check_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'integrations:check-freshness'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->artisan('integrations:check-freshness')->assertSuccessful();
    }

    private function check(): void
    {
        app(ConnectorFreshness::class)->checkAll();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{Integration, string}
     */
    private function connector(array $context, ?Carbon $lastHeartbeat, bool $newlyPaired = false): array
    {
        $connector = Integration::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'provider' => 'woocommerce',
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '0.4.0',
            'health' => [],
        ]);
        $connector->forceFill([
            'created_at' => $newlyPaired ? now() : now()->subDays(2),
            'last_heartbeat_at' => $lastHeartbeat,
        ])->save();
        $secret = random_bytes(32);
        IntegrationCredential::query()->create([
            'tenant_id' => $connector->tenant_id,
            'store_id' => $connector->store_id,
            'integration_id' => $connector->id,
            'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
            'key_id' => 'bwk_freshness_test',
            'ciphertext' => Crypt::encryptString(base64_encode($secret)),
            'key_version' => 1,
            'fingerprint' => hash('sha256', $secret),
            'status' => IntegrationCredential::STATUS_ACTIVE,
            'created_at' => now(),
        ]);

        return [$connector->fresh(), $secret];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function heartbeat(string $secret, array $report): TestResponse
    {
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $body = json_encode($report, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/ingest/heartbeat', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BW_KEY_ID' => 'bwk_freshness_test',
            'HTTP_X_BW_TIMESTAMP' => $timestamp,
            'HTTP_X_BW_NONCE' => $nonce,
            'HTTP_X_BW_SIGNATURE' => hash_hmac('sha256', implode("\n", ['v1', $timestamp, $nonce, 'POST', '/api/v1/ingest/heartbeat', hash('sha256', $body)]), $secret),
            'HTTP_X_BW_SIGNATURE_VERSION' => '1',
        ], $body);
    }
}
