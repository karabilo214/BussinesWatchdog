<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Billing\FeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaunchPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_plans_are_created_as_drafts_with_their_limits_and_monthly_prices(): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password_hash' => 'x', 'locale' => 'en']);
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);

        $this->artisan('billing:launch-plans', ['staff' => 'nobody@platform.test'])->assertFailed();
        $this->assertSame(0, BillingPlan::query()->count());

        $this->artisan('billing:launch-plans', ['staff' => 'owner@platform.test'])->assertSuccessful();

        $versions = BillingPlanVersion::query()->with('plan')->get()->keyBy(fn (BillingPlanVersion $version): string => $version->plan->code);
        $this->assertSame(['business', 'enterprise', 'growth', 'start'], $versions->keys()->sort()->values()->all());
        $this->assertSame([BillingPlan::STATE_DRAFT], $versions->pluck('state')->unique()->values()->all());

        $start = $versions['start'];
        $this->assertSame(14, $start->trial_days);
        $this->assertSame(14, $start->order_volume_grace_days);
        $this->assertNull($start->faster_check_interval_minutes);
        $this->assertSame('Start', $start->localized_content['name']['de']);
        $this->assertStringContainsString('1 000', $start->localized_content['description']['ru']);
        $this->assertSame(1, $start->entitlements['limits'][FeatureCatalog::MAX_ACTIVE_STORES]);
        $this->assertSame(60, $start->entitlements['limits'][FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES]);
        $this->assertSame(1000, $start->entitlements['limits'][FeatureCatalog::MAX_ORDERS_PER_MONTH]);
        $this->assertNotContains(FeatureCatalog::PAYMENT_ATTEMPTS_SCRIPT, $start->entitlements['features']);
        $this->assertContains(FeatureCatalog::PAYMENT_ATTEMPTS_SCRIPT, $versions['business']->entitlements['features']);
        $this->assertSame(15, $versions['growth']->entitlements['limits'][FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES]);

        foreach ($versions as $version) {
            $this->assertNotContains(FeatureCatalog::ORDER_OUTCOMES, $version->entitlements['features']);
            $this->assertNotContains(FeatureCatalog::AGENCY_WORKSPACE, $version->entitlements['features']);
        }

        $prices = BillingPrice::query()->get()->mapWithKeys(fn (BillingPrice $price): array => [$versions->search(fn (BillingPlanVersion $version): bool => $version->id === $price->plan_version_id).':'.$price->price_kind => $price]);
        $this->assertSame(['business:base', 'business:extra_store', 'growth:base', 'growth:extra_store', 'start:base', 'start:extra_store'], $prices->keys()->sort()->values()->all());
        $this->assertSame('1900', (string) $prices['start:base']->amount_minor);
        $this->assertSame('800', (string) $prices['business:extra_store']->amount_minor);
        $this->assertSame('7900', (string) $prices['growth:base']->amount_minor);
        $this->assertSame(['EUR'], $prices->pluck('currency')->unique()->values()->all());
        $this->assertSame(['month'], $prices->pluck('billing_interval')->unique()->values()->all());
        $this->assertSame([BillingPlan::STATE_DRAFT], $prices->pluck('state')->unique()->values()->all());

        $this->assertTrue($versions['enterprise']->contact_sales);
        $this->assertSame(0, $versions['enterprise']->trial_days);

        $this->artisan('billing:launch-plans', ['staff' => 'owner@platform.test'])->assertSuccessful();
        $this->assertSame(4, BillingPlanVersion::query()->count());
        $this->assertSame(6, BillingPrice::query()->count());
    }
}
