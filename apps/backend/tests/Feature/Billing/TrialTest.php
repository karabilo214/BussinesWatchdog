<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingSubscriptionContract;
use App\Models\Subscription;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\SubscriptionProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class TrialTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.enforcement', EntitlementCalculator::MODE_ENFORCE);
    }

    public function test_owner_starts_one_trial_without_payment_and_it_ends_paused(): void
    {
        Carbon::setTestNow('2026-10-11T12:00:00Z');
        $context = $this->verified($this->context());
        $version = $this->version();

        $this->trial($context, $version)
            ->assertCreated()
            ->assertJsonPath('status', 'trial')
            ->assertJsonPath('provider', 'internal')
            ->assertJsonPath('plan_code', 'pro')
            ->assertJsonPath('access_state', 'trial')
            ->assertJsonPath('trial_ends_at', '2026-10-25T12:00:00.000000Z');
        $this->assertTrue((new EntitlementCalculator)->forTenant($context['tenant']->id)->allows(FeatureCatalog::CHECKOUT_MONITORING));

        $this->trial($context, $version)->assertStatus(409)->assertJsonPath('code', 'trial_not_available');

        Subscription::query()->delete();
        $this->trial($context, $version)->assertStatus(409)->assertJsonPath('code', 'trial_not_available');

        $ended = (new EntitlementCalculator)->forTenant($context['tenant']->id, now()->addDays(15));
        $this->assertFalse($ended->allows(FeatureCatalog::CHECKOUT_MONITORING));
    }

    public function test_trial_needs_the_owner_with_a_verified_email_and_a_plan_with_trial_days(): void
    {
        $owner = $this->context();
        $admin = $this->verified($this->context('admin', $owner['tenant'], $owner['store'], $owner['integration']));
        $version = $this->version();

        $this->trial($admin, $version)->assertForbidden();
        $this->trial($owner, $version)->assertForbidden()->assertJsonPath('code', 'email_not_verified');

        $this->verified($owner);
        $version->forceFill(['trial_days' => 0])->save();
        $this->trial($owner, $version)->assertStatus(422)->assertJsonPath('code', 'plan_not_available');
        $this->assertNull($owner['tenant']->fresh()->trial_consumed_at);
    }

    public function test_a_purchase_during_the_trial_replaces_it(): void
    {
        Carbon::setTestNow('2026-10-11T12:00:00Z');
        $context = $this->verified($this->context());
        $version = $this->version();
        $this->trial($context, $version)->assertCreated();
        BillingSubscriptionContract::query()->create([
            'tenant_id' => $context['tenant']->id,
            'plan_version_id' => $version->id,
            'provider' => 'paddle',
            'provider_account_ref' => 'default',
            'provider_subscription_ref' => 'sub_1',
            'mode' => 'test',
            'normalized_state' => 'active',
            'entitlement_snapshot' => ['features' => [], 'limits' => []],
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'paid_until' => now()->addMonth(),
            'source_updated_at' => now(),
        ]);

        app(SubscriptionProjector::class)->project($context['tenant']->id, 'test');

        $subscription = Subscription::query()->sole();
        $this->assertSame([Subscription::STATUS_ACTIVE, 'paddle', null, 2], [$subscription->status, $subscription->provider, $subscription->trial_ends_at, $subscription->version]);
        $this->assertSame('2026-11-11T12:00:00+00:00', $subscription->period_end->toAtomString());
    }

    private function version(): BillingPlanVersion
    {
        $plan = BillingPlan::query()->create(['code' => 'pro', 'state' => BillingPlan::STATE_DRAFT]);

        return BillingPlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version' => 1,
            'state' => BillingPlan::STATE_DRAFT,
            'localized_content' => ['name' => ['en' => 'Pro']],
            'entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 3]]),
            'trial_days' => 14,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function verified(array $context): array
    {
        $context['user']->forceFill(['email_verified_at' => now()])->save();

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function trial(array $context, BillingPlanVersion $version): TestResponse
    {
        return $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/billing/trial', ['plan_version_id' => $version->id]);
    }
}
