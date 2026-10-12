<?php

namespace Tests\Feature\Billing;

use App\Mail\NotificationMessageMail;
use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\EntitlementOverride;
use App\Models\ReconciliationDirtySubject;
use App\Models\Store;
use App\Models\Subscription;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\Entitlements;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\OrderVolume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class OrderVolumeTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 04:10:00');
        config()->set('watchdog.billing.enforcement', 'enforce');
        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_average_uses_whole_months_since_the_store_was_added(): void
    {
        $context = $this->context();
        $context['store']->forceFill(['created_at' => Carbon::parse('2026-08-15')])->save();
        $this->volumes($context['store'], ['2026-07-01' => 9000, '2026-08-01' => 100, '2026-09-01' => 1200, '2026-10-01' => 400]);

        $figures = app(OrderVolume::class)->figures($context['store']->fresh());

        $this->assertSame(400, $figures['this_month']);
        $this->assertSame(1200, $figures['average']);
        $this->assertSame([['month' => '2026-09-01', 'orders' => 1200]], $figures['months']);
    }

    public function test_a_store_over_its_package_is_marked_and_its_owner_told_once_with_the_grace_period(): void
    {
        Mail::fake();
        $context = $this->context();
        $start = $this->package('start', 1000, 19, graceDays: 14);
        $this->package('business', 5000, 39);
        $this->package('enterprise', null, null, contactSales: true);
        $this->subscribe($context['tenant']->id, $start);
        $context['store']->forceFill(['created_at' => Carbon::parse('2026-01-01')])->save();
        $this->volumes($context['store'], ['2026-07-01' => 1100, '2026-08-01' => 1300, '2026-09-01' => 1400]);

        $result = app(OrderVolume::class)->evaluate();

        $this->assertSame(['stores' => 1, 'over' => 1, 'notified' => 1, 'paused' => 0, 'resumed' => 0], $result);
        $store = $context['store']->fresh();
        $this->assertSame(1267, $store->order_volume_average);
        $this->assertTrue($store->order_volume_over_since->equalTo(now()));
        $this->assertSame('onboarding', $store->status);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo($context['user']->email) && str_contains($mail->bodyText, '1267') && str_contains($mail->bodyText, '1000') && str_contains($mail->bodyText, '2026-11-03'));

        Carbon::setTestNow(now()->addDay());
        $this->assertSame(['stores' => 1, 'over' => 1, 'notified' => 0, 'paused' => 0, 'resumed' => 0], app(OrderVolume::class)->evaluate());
        $this->assertTrue($context['store']->fresh()->order_volume_over_since->equalTo(Carbon::parse('2026-10-20 04:10:00')));
        Mail::assertSentCount(1);

        $summary = app(OrderVolume::class)->summary($context['tenant']->id, 'start');
        $this->assertSame(1000, $summary['limit']);
        $this->assertSame(14, $summary['grace_days']);
        $this->assertSame('2026-11-03T04:10:00.000000Z', $summary['stores'][0]['grace_ends_at']);
        $this->assertSame('business', $summary['recommended']['plan_code']);

        DB::table('store_order_volumes')->where('store_id', $context['store']->id)->update(['orders' => 500]);
        $this->assertSame(['stores' => 1, 'over' => 0, 'notified' => 0, 'paused' => 0, 'resumed' => 0], app(OrderVolume::class)->evaluate());
        $this->assertNull($context['store']->fresh()->order_volume_over_since);
        $this->assertNull(app(OrderVolume::class)->summary($context['tenant']->id, 'start')['recommended']);
    }

    public function test_after_the_grace_period_the_organisation_is_paused_until_a_package_covers_the_volume(): void
    {
        Mail::fake();
        $context = $this->context();
        $other = $this->context(tenant: $context['tenant']);
        $start = $this->package('start', 1000, 19, graceDays: 14);
        $business = $this->package('business', 5000, 39);
        $this->subscribe($context['tenant']->id, $start);
        $context['store']->forceFill(['created_at' => Carbon::parse('2026-01-01')])->save();
        $this->volumes($context['store'], ['2026-07-01' => 1100, '2026-08-01' => 1300, '2026-09-01' => 1400, '2026-10-01' => 1500]);
        $calculator = app(EntitlementCalculator::class);

        app(OrderVolume::class)->evaluate();
        $this->assertNull($context['tenant']->fresh()->order_volume_paused_at);

        Carbon::setTestNow(now()->addDays(14));
        $calculator->forget();
        $this->assertSame(1, app(OrderVolume::class)->evaluate()['paused']);

        $this->assertTrue($context['tenant']->fresh()->order_volume_paused_at->equalTo(now()));
        $calculator->forget();
        $access = $calculator->forTenant($context['tenant']->id);
        $this->assertSame([Entitlements::ACCESS_PAUSED_BILLING, [EntitlementCalculator::REASON_ORDER_VOLUME]], [$access->access, $access->reasons]);
        $this->assertFalse($access->allows(FeatureCatalog::CHECKOUT_MONITORING));
        $this->assertSame(1000, $access->limit(FeatureCatalog::MAX_ORDERS_PER_MONTH));
        $this->assertNotNull(app(OrderVolume::class)->summary($context['tenant']->id, 'start')['paused_at']);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => str_contains($mail->subjectLine, 'приостановлен') && str_contains($mail->bodyText, '«'.$context['store']->name.'»'));

        Carbon::setTestNow(now()->addDay());
        $calculator->forget();
        $this->assertSame(0, app(OrderVolume::class)->evaluate()['resumed']);
        $this->assertNotNull($context['tenant']->fresh()->order_volume_paused_at);

        Subscription::query()->where('tenant_id', $context['tenant']->id)->update(['plan_code' => 'business', 'plan_version_id' => $business->id, 'entitlements' => json_encode($business->entitlements)]);
        $this->assertSame(1, app(OrderVolume::class)->refreshTenant($context['tenant']->id)['resumed']);

        $this->assertNull($context['tenant']->fresh()->order_volume_paused_at);
        $this->assertSame(Entitlements::ACCESS_ACTIVE, app(EntitlementCalculator::class)->forTenant($context['tenant']->id)->access);
        $this->assertSame(2, ReconciliationDirtySubject::query()->where('subject_type', ReconciliationDirtySubject::TYPE_STORE_UNMATCHED_PAYMENTS)->where('reason', ReconciliationDirtySubject::REASON_ORDER_VOLUME_RESUMED)->count());
        $this->assertNotSame($context['store']->id, $other['store']->id);
    }

    public function test_temporary_access_with_a_higher_limit_also_resumes_a_paused_organisation(): void
    {
        $context = $this->context();
        $start = $this->package('start', 1000, 19, graceDays: 0);
        $this->subscribe($context['tenant']->id, $start);
        $context['store']->forceFill(['created_at' => Carbon::parse('2026-01-01')])->save();
        $this->volumes($context['store'], ['2026-09-01' => 6000]);
        app(OrderVolume::class)->evaluate();
        $this->assertNotNull($context['tenant']->fresh()->order_volume_paused_at);

        EntitlementOverride::query()->create([
            'tenant_id' => $context['tenant']->id,
            'entitlements' => ['features' => [], 'limits' => [FeatureCatalog::MAX_ORDERS_PER_MONTH => 5000]],
            'reason' => 'busy season',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(30),
            'created_at' => now(),
        ]);

        $this->assertSame(1, app(OrderVolume::class)->refreshTenant($context['tenant']->id)['resumed']);
        $this->assertSame(Entitlements::ACCESS_OVERRIDE, app(EntitlementCalculator::class)->forTenant($context['tenant']->id)->access);
    }

    public function test_beyond_every_priced_package_the_individual_one_is_recommended_and_without_a_limit_nothing_is_over(): void
    {
        $this->package('start', 1000, 19);
        $this->package('growth', 20000, 79);
        $this->package('enterprise', null, null, contactSales: true);

        $this->assertSame('growth', app(OrderVolume::class)->recommended(15000, 'start')['plan_code']);
        $individual = app(OrderVolume::class)->recommended(50000, 'growth');
        $this->assertSame(['enterprise', true, 'enterprise'], [$individual['plan_code'], $individual['contact_sales'], $individual['name']['ru']]);

        $context = $this->context();
        $this->volumes($context['store'], ['2026-09-01' => 100000]);
        $context['store']->forceFill(['created_at' => Carbon::parse('2026-01-01')])->save();
        config()->set('watchdog.billing.enforcement', 'pilot');
        $this->assertSame(0, app(OrderVolume::class)->evaluate()['over']);
        $this->assertNull($context['tenant']->fresh()->order_volume_paused_at);
    }

    public function test_the_pricing_page_shows_the_order_limit_and_the_individual_package(): void
    {
        $this->package('start', 1000, 19);
        $this->package('enterprise', null, null, contactSales: true);

        $this->get('/ru/pricing')->assertOk()
            ->assertSee('Заказов в месяц на магазин')
            ->assertSee('до 1 000')
            ->assertSee('data-plan="enterprise"', false)
            ->assertSee('Индивидуально')
            ->assertSee('href="/ru/contact">Обсудить условия</a>', false);
    }

    /**
     * @param  array<string, int>  $months
     */
    private function volumes(Store $store, array $months): void
    {
        foreach ($months as $month => $orders) {
            DB::table('store_order_volumes')->insert(['tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'month' => $month, 'orders' => $orders, 'updated_at' => now()]);
        }
    }

    private function package(string $code, ?int $orders, ?int $euros, int $graceDays = 30, bool $contactSales = false): BillingPlanVersion
    {
        $plan = BillingPlan::query()->create(['code' => $code, 'state' => BillingPlan::STATE_PUBLISHED]);
        $version = BillingPlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version' => 1,
            'state' => BillingPlan::STATE_PUBLISHED,
            'trial_days' => 0,
            'order_volume_grace_days' => $graceDays,
            'contact_sales' => $contactSales,
            'localized_content' => ['name' => ['ru' => $code, 'en' => $code, 'de' => $code]],
            'entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_ORDERS_PER_MONTH => $orders]]),
        ]);

        if ($euros !== null) {
            BillingPrice::query()->create([
                'plan_version_id' => $version->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_price_ref' => "pri_{$code}",
                'price_kind' => BillingPrice::KIND_BASE, 'mode' => 'test', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => $euros * 100,
                'billing_interval' => 'month', 'tax_behavior' => 'provider_calculated', 'state' => BillingPlan::STATE_PUBLISHED,
            ]);
        }

        return $version;
    }

    private function subscribe(string $tenantId, BillingPlanVersion $version): void
    {
        Subscription::query()->create([
            'tenant_id' => $tenantId, 'provider' => 'paddle', 'plan_code' => 'start', 'plan_version_id' => $version->id,
            'entitlements' => $version->entitlements, 'extra_store_quantity' => 0, 'status' => Subscription::STATUS_ACTIVE,
            'period_start' => now()->subDays(5), 'period_end' => now()->addDays(25), 'version' => 1,
        ]);
    }
}
