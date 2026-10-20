<?php

namespace Tests\Feature\Notifications;

use App\Models\Incident;
use App\Models\Integration;
use App\Models\MaintenanceNotice;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\PlatformStaff;
use App\Models\Store;
use App\Models\User;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Notifications\Channels\NotificationSenderRegistry;
use App\Support\Notifications\DailyDigestContentBuilder;
use App\Support\Notifications\DailyDigestPlanner;
use App\Support\Notifications\NotificationDeliveryWorker;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyDigestTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private FakeNotificationSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = new FakeNotificationSender;
        $this->app->instance(NotificationSenderRegistry::class, new NotificationSenderRegistry([$this->sender]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_digest_is_opt_in_and_planned_once_per_local_day_after_its_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 07:59:00', 'Europe/Kyiv'));
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant'], 'no-digest@example.test');
        $this->verifiedEmailChannel($context['tenant'], 'digest@example.test', ['daily_digest_at' => '08:00', 'timezone' => 'Europe/Kyiv', 'locale' => 'en']);
        $planner = app(DailyDigestPlanner::class);

        $this->assertSame(0, $planner->planDue());

        Carbon::setTestNow(Carbon::parse('2026-10-20 08:05:00', 'Europe/Kyiv'));
        $this->assertSame(1, $planner->planDue());
        $this->assertSame(0, $planner->planDue());
        $this->artisan('notifications:digest')->assertSuccessful();

        Carbon::setTestNow(Carbon::parse('2026-10-20 23:50:00', 'Europe/Kyiv'));
        $this->assertSame(0, $planner->planDue());

        Carbon::setTestNow(Carbon::parse('2026-10-21 08:00:00', 'Europe/Kyiv'));
        $this->assertSame(1, $planner->planDue());

        $digests = NotificationDelivery::query()->where('notification_kind', NotificationDelivery::KIND_DAILY_DIGEST)->orderBy('created_at')->get();
        $this->assertSame(['digest:2026-10-20', 'digest:2026-10-21'], $digests->pluck('dedupe_key')->all());
        $this->assertNull($digests->first()->incident_id);
        $this->assertSame(DailyDigestContentBuilder::TEMPLATE_VERSION, $digests->first()->template_version);
    }

    public function test_without_active_incidents_and_with_gaps_the_digest_says_data_is_incomplete_not_fine(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00', 'Europe/Berlin'));
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant'], 'digest@example.test', ['daily_digest_at' => '08:00', 'timezone' => 'Europe/Berlin', 'locale' => 'en']);

        $this->assertSame(1, app(DailyDigestPlanner::class)->planDue());
        app(NotificationDeliveryWorker::class)->deliverDue();

        $sent = $this->sender->sent[0];
        $this->assertSame('digest@example.test', $sent['destination']);
        $this->assertStringContainsString('data incomplete', $sent['message']->subject);
        $this->assertStringNotContainsString('No problems found:', $sent['message']->body);
        $this->assertStringContainsString('Main Shop', $sent['message']->body);
        $this->assertStringContainsString('Active incidents: none.', $sent['message']->body);
        $this->assertStringContainsString('Store data source is not connected.', $sent['message']->body);
        $this->assertStringContainsString('Checkout checks do not run: the store is not verified.', $sent['message']->body);
        $this->assertStringContainsString('2026-10-19 09:00 CEST – 2026-10-20 09:00 CEST', $sent['message']->body);
        $this->assertStringContainsString(rtrim((string) config('app.url'), '/').'/app', $sent['message']->body);
        $this->assertStringNotContainsString('digest.', $sent['message']->body);
    }

    public function test_digest_lists_active_incidents_and_verified_amounts_only_for_the_channel_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 06:00:00', 'UTC'));
        $context = $this->context();
        $other = Store::query()->create([
            'tenant_id' => $context['tenant']->id,
            'name' => 'Second Shop',
            'base_url' => 'https://second.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        $otherProvider = Integration::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $other->id,
            'provider' => 'stripe',
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);
        $foreign = $this->context();

        [$incident] = $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->evaluate($this->order(['tenant' => $context['tenant'], 'store' => $other, 'integration' => $otherProvider], ['display_number' => '#2', 'paid_marked_at' => now()->subMinutes(31)]));
        $this->evaluate($this->order($foreign, ['display_number' => '#3', 'total_minor' => 99900, 'paid_marked_at' => now()->subMinutes(31)]));

        $this->verifiedEmailChannel($context['tenant'], 'digest@example.test', [
            'daily_digest_at' => '07:00',
            'timezone' => 'Europe/Berlin',
            'locale' => 'ru',
            'min_severity' => 'critical',
            'store_ids' => [$context['store']->id],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-20 07:30:00', 'Europe/Berlin'));
        $this->assertSame(1, app(DailyDigestPlanner::class)->planDue());

        $content = NotificationDelivery::query()->where('notification_kind', NotificationDelivery::KIND_DAILY_DIGEST)->firstOrFail()->sanitized_content;
        $this->assertSame(DailyDigestContentBuilder::VERDICT_PROBLEMS, $content['verdict']);
        $this->assertSame(['Main Shop'], array_column($content['stores'], 'name'));
        $this->assertSame(1, $content['stores'][0]['opened']);
        $this->assertCount(1, $content['incidents']);
        $this->assertCount(1, $content['discrepancies']);
        $discrepancy = $content['discrepancies'][0];
        ksort($discrepancy);
        $this->assertSame([
            'component' => $incident->component,
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'incident_count' => 1,
            'mode' => 'live',
            'total_minor' => '18400',
        ], $discrepancy);

        app(NotificationDeliveryWorker::class)->deliverDue();
        $body = $this->sender->sent[0]['message']->body;

        $this->assertStringContainsString('есть активные инциденты', $this->sender->sent[0]['message']->subject);
        $this->assertStringContainsString('[Main Shop]', $body);
        $this->assertStringContainsString('/app/incidents/'.$incident->id, $body);
        $this->assertStringContainsString('режим боевой', $body);
        $this->assertStringContainsString('184.00 EUR', $body);
        $this->assertStringNotContainsString('Second Shop', $body);
        $this->assertStringNotContainsString('999.00', $body);
        $this->assertStringNotContainsString('#15238', $body);
    }

    public function test_quiet_hours_defer_the_digest_and_maintenance_holds_planning_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 06:30:00', 'Europe/Kyiv'));
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant'], 'digest@example.test', [
            'daily_digest_at' => '06:00',
            'timezone' => 'Europe/Kyiv',
            'quiet_hours' => ['start' => '22:00', 'end' => '08:00'],
            'critical_bypasses_quiet_hours' => true,
        ]);

        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password_hash' => 'x', 'locale' => 'ru']);
        $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
        $notice = MaintenanceNotice::query()->create(['version' => 1, 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addMinutes(10), 'messages' => ['ru' => 'Работы'], 'reason' => 'Upgrade', 'created_by' => $staff->id]);

        $this->assertSame(0, app(DailyDigestPlanner::class)->planDue());

        $notice->forceFill(['cancelled_at' => now()])->save();
        $this->assertSame(1, app(DailyDigestPlanner::class)->planDue());

        $digest = NotificationDelivery::query()->where('notification_kind', NotificationDelivery::KIND_DAILY_DIGEST)->firstOrFail();
        $this->assertTrue($digest->next_attempt_at->equalTo(Carbon::parse('2026-10-20 08:00:00', 'Europe/Kyiv')));
    }

    public function test_channels_without_stores_in_scope_or_disabled_get_no_digest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'UTC'));
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant'], 'off@example.test', ['daily_digest_at' => '08:00', 'timezone' => 'UTC'], false);
        $context['store']->forceFill(['status' => 'deleted'])->save();
        $this->verifiedEmailChannel($context['tenant'], 'empty@example.test', ['daily_digest_at' => '08:00', 'timezone' => 'UTC']);

        $this->assertSame(0, app(DailyDigestPlanner::class)->planDue());
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    /**
     * @return list<Incident>
     */
    private function evaluate(Order $order): array
    {
        $run = app(OrderReconciliationService::class)->evaluate($order);

        return app(MoneyIncidentCorrelator::class)->correlate($run);
    }
}
