<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\EntitlementOverride;
use App\Models\ReconciliationFinding;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\Entitlements;
use App\Support\Billing\FeatureCatalog;
use App\Support\Browser\CheckScheduler;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    public function test_pilot_mode_grants_everything_without_limits(): void
    {
        $context = $this->context();

        $entitlements = $this->calculate($context['tenant']);

        $this->assertSame(Entitlements::ACCESS_PILOT, $entitlements->access);
        $this->assertFalse($entitlements->enforced);
        $this->assertTrue($entitlements->allows(FeatureCatalog::AGENCY_WORKSPACE));
        $this->assertNull($entitlements->limit(FeatureCatalog::MAX_ACTIVE_STORES));
    }

    public function test_enforced_tenant_without_subscription_is_onboarding_with_nothing_included(): void
    {
        $this->enforce();
        $context = $this->context();

        $entitlements = $this->calculate($context['tenant']);

        $this->assertSame(Entitlements::ACCESS_ONBOARDING, $entitlements->access);
        $this->assertFalse($entitlements->allows(FeatureCatalog::PAYMENT_ATTEMPTS_SERVER));
        $this->assertSame(Entitlements::ACCESS_ONBOARDING, $entitlements->reasonFor(FeatureCatalog::PAYMENT_ATTEMPTS_SERVER));
    }

    public function test_paid_period_applies_the_plan_snapshot_and_lapses_after_period_end(): void
    {
        $this->enforce();
        $context = $this->context();
        $this->subscribe($context['tenant'], ['status' => Subscription::STATUS_ACTIVE, 'period_end' => now()->addDays(10)]);

        $paid = $this->calculate($context['tenant']);
        $this->assertSame(Entitlements::ACCESS_ACTIVE, $paid->access);
        $this->assertTrue($paid->allows(FeatureCatalog::MONEY_RECONCILIATION));
        $this->assertFalse($paid->allows(FeatureCatalog::NOTIFICATIONS_TELEGRAM));
        $this->assertSame('not_in_plan', $paid->reasonFor(FeatureCatalog::NOTIFICATIONS_TELEGRAM));
        $this->assertSame(15, $paid->limit(FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES));

        $lapsed = $this->calculate($context['tenant'], now()->addDays(11));
        $this->assertSame(Entitlements::ACCESS_PAUSED_BILLING, $lapsed->access);
        $this->assertFalse($lapsed->allows(FeatureCatalog::MONEY_RECONCILIATION));
    }

    public function test_trial_and_past_due_grace_follow_their_dates(): void
    {
        $this->enforce();
        $trialTenant = $this->context()['tenant'];
        $this->subscribe($trialTenant, ['status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->addDays(3)]);
        $graceTenant = $this->context()['tenant'];
        $this->subscribe($graceTenant, ['status' => Subscription::STATUS_PAST_DUE, 'past_due_since' => now()->subDays(2)]);

        $this->assertSame(Entitlements::ACCESS_TRIAL, $this->calculate($trialTenant)->access);
        $this->assertSame(Entitlements::ACCESS_PAUSED_BILLING, $this->calculate($trialTenant, now()->addDays(4))->access);
        $this->assertSame(Entitlements::ACCESS_GRACE, $this->calculate($graceTenant)->access);
        $this->assertTrue($this->calculate($graceTenant)->allows(FeatureCatalog::CHECKOUT_MONITORING));
        $this->assertSame(Entitlements::ACCESS_PAUSED_BILLING, $this->calculate($graceTenant, now()->addDays(6))->access);
    }

    public function test_override_adds_to_the_paid_plan_but_never_lifts_a_manual_suspension(): void
    {
        $this->enforce();
        $context = $this->context();
        $this->subscribe($context['tenant'], ['status' => Subscription::STATUS_ACTIVE, 'period_end' => now()->addDays(10)]);
        EntitlementOverride::query()->create([
            'tenant_id' => $context['tenant']->id,
            'entitlements' => ['features' => [FeatureCatalog::NOTIFICATIONS_TELEGRAM], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 5]],
            'reason' => 'support goodwill',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(2),
            'created_at' => now(),
        ]);

        $granted = $this->calculate($context['tenant']);
        $this->assertSame(Entitlements::ACCESS_OVERRIDE, $granted->access);
        $this->assertTrue($granted->allows(FeatureCatalog::NOTIFICATIONS_TELEGRAM));
        $this->assertTrue($granted->allows(FeatureCatalog::MONEY_RECONCILIATION));
        $this->assertSame(5, $granted->limit(FeatureCatalog::MAX_ACTIVE_STORES));
        $this->assertSame(Entitlements::ACCESS_ACTIVE, $this->calculate($context['tenant'], now()->addDays(3))->access);

        $context['tenant']->forceFill(['status' => 'suspended'])->save();
        $suspended = $this->calculate($context['tenant']);
        $this->assertSame(Entitlements::ACCESS_PAUSED_MANUAL, $suspended->access);
        $this->assertFalse($suspended->allows(FeatureCatalog::NOTIFICATIONS_TELEGRAM));
    }

    public function test_override_never_lowers_a_limit_of_the_paid_plan(): void
    {
        $this->enforce();
        $context = $this->context();
        $this->subscribe($context['tenant'], ['status' => Subscription::STATUS_ACTIVE, 'period_end' => now()->addDays(10)]);
        EntitlementOverride::query()->create([
            'tenant_id' => $context['tenant']->id,
            'entitlements' => ['features' => [], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 2, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 60, FeatureCatalog::RETENTION_DAYS => 30, FeatureCatalog::MAX_EXTRA_STORES => null]],
            'reason' => 'partner',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(2),
            'created_at' => now(),
        ]);

        $granted = $this->calculate($context['tenant']);

        $this->assertSame(Entitlements::ACCESS_OVERRIDE, $granted->access);
        $this->assertSame(3, $granted->limit(FeatureCatalog::MAX_ACTIVE_STORES));
        $this->assertSame(15, $granted->limit(FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES));
        $this->assertNull($granted->limit(FeatureCatalog::RETENTION_DAYS));
        $this->assertNull($granted->limit(FeatureCatalog::MAX_EXTRA_STORES));
        $this->assertSame(
            [FeatureCatalog::MAX_ACTIVE_STORES => 9, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 5, FeatureCatalog::RETENTION_DAYS => 90],
            EntitlementCalculator::widerLimits([FeatureCatalog::MAX_ACTIVE_STORES => 3, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 15, FeatureCatalog::RETENTION_DAYS => 90], [FeatureCatalog::MAX_ACTIVE_STORES => 9, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 5, FeatureCatalog::RETENTION_DAYS => 30]),
        );
    }

    public function test_suspended_or_deleting_tenant_is_paused_even_in_pilot_mode(): void
    {
        $context = $this->context();
        $context['tenant']->forceFill(['status' => 'deleting'])->save();

        $entitlements = $this->calculate($context['tenant']);

        $this->assertSame(Entitlements::ACCESS_NO_SERVICE, $entitlements->access);
        $this->assertFalse($entitlements->allows(FeatureCatalog::CHECKOUT_MONITORING));
    }

    public function test_bought_stores_raise_the_store_limit_up_to_the_cap_and_scale_per_store_limits(): void
    {
        $plan = ['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [
            FeatureCatalog::MAX_ACTIVE_STORES => 1,
            FeatureCatalog::MAX_EXTRA_STORES => 4,
            FeatureCatalog::MONTHLY_EVENT_BUDGET => 1000,
            FeatureCatalog::MANUAL_RUNS_PER_DAY => 10,
            FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 15,
        ]];

        $three = EntitlementCalculator::withStores($plan, 2)['limits'];
        $capped = EntitlementCalculator::withStores($plan, 9)['limits'];

        $this->assertSame(3, $three[FeatureCatalog::MAX_ACTIVE_STORES]);
        $this->assertSame(3000, $three[FeatureCatalog::MONTHLY_EVENT_BUDGET]);
        $this->assertSame(30, $three[FeatureCatalog::MANUAL_RUNS_PER_DAY]);
        $this->assertSame(15, $three[FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES]);
        $this->assertSame(5, $capped[FeatureCatalog::MAX_ACTIVE_STORES]);
        $this->assertSame(5000, $capped[FeatureCatalog::MONTHLY_EVENT_BUDGET]);
    }

    public function test_unknown_feature_or_invalid_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FeatureCatalog::normalize(['features' => ['everything'], 'limits' => []]);
    }

    public function test_api_reports_the_current_tenant_entitlements(): void
    {
        $context = $this->context('viewer');

        $this->asMember($context)->getJson('/api/v1/entitlements')
            ->assertOk()
            ->assertJsonPath('access_state', Entitlements::ACCESS_PILOT)
            ->assertJsonPath('enforced', false)
            ->assertJsonPath('features.0.included', true)
            ->assertJsonPath('features.0.reason', 'pilot');

        $this->enforce();
        $this->subscribe($context['tenant'], ['status' => Subscription::STATUS_ACTIVE, 'period_end' => now()->addDays(10)]);
        $features = collect($this->asMember($context)->getJson('/api/v1/entitlements')
            ->assertOk()
            ->assertJsonPath('access_state', Entitlements::ACCESS_ACTIVE)
            ->assertJsonPath('plan_code', 'start')
            ->assertJsonPath('limits.max_active_stores', 3)
            ->json('features'))->keyBy('code');

        $this->assertSame(['code' => 'notifications_telegram', 'included' => false, 'reason' => 'not_in_plan'], $features['notifications_telegram']);
        $this->assertTrue($features['money_reconciliation']['included']);
    }

    public function test_store_creation_stops_at_the_plan_store_limit(): void
    {
        $this->enforce();
        $context = $this->context();
        $this->subscribe($context['tenant'], ['status' => Subscription::STATUS_ACTIVE, 'period_end' => now()->addDays(10), 'extra_store_quantity' => 0]);

        $this->asMember($context)->postJson('/api/v1/stores', [
            'name' => 'Second Shop',
            'base_url' => 'https://second.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ])->assertForbidden()->assertJsonPath('code', 'plan_store_limit_reached')->assertJsonPath('errors.0.value', 1);

        Subscription::query()->update(['extra_store_quantity' => 1]);
        $this->asMember($context)->postJson('/api/v1/stores', [
            'name' => 'Second Shop',
            'base_url' => 'https://second.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ])->assertCreated();
    }

    public function test_telegram_channel_needs_the_feature(): void
    {
        $this->enforce();
        config()->set('services.telegram.bot_username', 'BusinessWatchdogBot');
        $context = $this->context();
        $this->subscribe($context['tenant'], ['status' => Subscription::STATUS_ACTIVE, 'period_end' => now()->addDays(10)]);

        $this->asMember($context)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/notification-channels', ['kind' => 'telegram', 'label' => 'Ops'])
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_not_in_plan');
    }

    public function test_checks_need_checkout_monitoring_and_respect_the_interval_floor(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $this->enforce();
        $context = $this->context();
        $context['store']->forceFill(['base_url' => 'https://shop.example.test', 'status' => 'active', 'browser_enabled' => true, 'verified_at' => now()])->save();
        $scenario = $this->scenario($context);
        $url = "/api/v1/stores/{$context['store']->id}/checks";

        $this->asMember($context)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['scenario_id' => $scenario->id])
            ->assertForbidden()->assertJsonPath('code', CheckScheduler::ERROR_NOT_IN_PLAN);
        $this->assertSame(['due' => 1, 'created' => 0], app(CheckScheduler::class)->scheduleDue());
        $this->assertSame(0, CheckRun::query()->count());

        $this->subscribe($context['tenant'], ['status' => Subscription::STATUS_ACTIVE, 'period_end' => now()->addDays(10)]);
        CheckScenario::query()->whereKey($scenario->id)->update(['next_due_at' => now()->subMinute()]);
        config()->set('watchdog.browser.schedule_jitter', 0);
        $this->assertSame(['due' => 1, 'created' => 1], app(CheckScheduler::class)->scheduleDue());
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), $scenario->fresh()->next_due_at->getTimestamp());
        Carbon::setTestNow();
    }

    public function test_reconciliation_without_the_feature_is_unknown_not_ok(): void
    {
        $this->enforce();
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subHour()]);

        $finding = app(OrderReconciliationService::class)->evaluate($order)->findings()->sole();

        $this->assertSame(ReconciliationFinding::RULE_UNSUPPORTED, $finding->rule_code);
        $this->assertSame(ReconciliationFinding::STATUS_UNKNOWN, $finding->status);
        $this->assertSame('not_entitled', $finding->reason_code);
    }

    public function test_placeholder_plans_are_seeded_as_drafts_once(): void
    {
        $this->artisan('billing:seed-placeholder-plans')->assertSuccessful();
        $this->artisan('billing:seed-placeholder-plans')->assertSuccessful();

        $this->assertSame(['free', 'pro', 'start'], BillingPlan::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(3, BillingPlanVersion::query()->count());
        $this->assertSame(0, BillingPlan::query()->where('state', '!=', BillingPlan::STATE_DRAFT)->count());
        $this->assertTrue(BillingPlanVersion::query()->first()->localized_content['placeholder']);
    }

    private function enforce(): void
    {
        config()->set('watchdog.billing.enforcement', EntitlementCalculator::MODE_ENFORCE);
    }

    private function calculate(Tenant $tenant, ?Carbon $at = null): Entitlements
    {
        return (new EntitlementCalculator)->forTenant($tenant->id, $at);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscribe(Tenant $tenant, array $attributes): Subscription
    {
        return Subscription::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'provider' => 'paddle',
            'plan_code' => 'start',
            'entitlements' => [
                'features' => [FeatureCatalog::CHECKOUT_MONITORING, FeatureCatalog::MONEY_RECONCILIATION, FeatureCatalog::PAYMENT_ATTEMPTS_SERVER],
                'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_EXTRA_STORES => 4, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 15],
            ],
            'extra_store_quantity' => 2,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scenario(array $context): CheckScenario
    {
        return CheckScenario::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'name' => 'Payment form',
            'mode' => CheckScenario::MODE_PAYMENT_FORM,
            'version' => 1,
            'enabled' => true,
            'adapter_version' => ScenarioDefinition::ADAPTER_VERSION,
            'definition' => ['product_url' => 'https://shop.example.test/product/x'],
            'interval_seconds' => 300,
            'next_due_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function asMember(array $context): static
    {
        return $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
    }
}
