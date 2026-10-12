<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingSubscriptionContract;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\Subscription;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\SubscriptionProjector;
use App\Support\Billing\TrialStoreHosts;
use App\Support\Stores\StoreVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function test_store_domain_with_and_without_www_is_one_domain(): void
    {
        $this->assertSame('shop.de', TrialStoreHosts::host('https://www.Shop.DE.'));
        $this->assertSame('shop.de', TrialStoreHosts::host('https://shop.de:8443/eu'));
        $this->assertSame('eu.shop.de', TrialStoreHosts::host('https://eu.shop.de'));
        $this->assertSame('xn--mller-kva.de', TrialStoreHosts::host('https://www.müller.de'));
        $this->assertSame('www.de', TrialStoreHosts::host('https://www.de'));
        $this->assertNull(TrialStoreHosts::host('not a url'));
    }

    public function test_a_verified_store_domain_gets_one_trial_across_organisations(): void
    {
        Carbon::setTestNow('2026-10-12T12:00:00Z');
        $version = $this->version();
        $first = $this->verified($this->context());
        $this->storeAt($first, 'https://www.shop.de', true);

        $this->trial($first, $version)->assertCreated();
        $this->assertSame([['host' => 'shop.de', 'tenant_id' => $first['tenant']->id]], DB::table('trial_store_hosts')->get(['host', 'tenant_id'])->map(fn ($row) => (array) $row)->all());

        $second = $this->verified($this->context());
        $this->storeAt($second, 'https://shop.de', true);
        $this->trial($second, $version)->assertStatus(409)->assertJsonPath('code', 'trial_store_already_used');
        $this->assertNull($second['tenant']->fresh()->trial_consumed_at);
        $this->assertFalse(Subscription::query()->where('tenant_id', $second['tenant']->id)->exists());

        $subdomain = $this->verified($this->context());
        $this->storeAt($subdomain, 'https://eu.shop.de', true);
        $this->trial($subdomain, $version)->assertCreated();
    }

    public function test_an_unverified_store_does_not_block_a_trial_but_cannot_be_verified_during_it(): void
    {
        Carbon::setTestNow('2026-10-12T12:00:00Z');
        $version = $this->version();
        $first = $this->verified($this->context());
        $this->storeAt($first, 'https://shop.de', true);
        $this->trial($first, $version)->assertCreated();

        $second = $this->verified($this->context());
        $store = $this->storeAt($second, 'https://www.shop.de', false);
        $this->trial($second, $version)->assertCreated();

        $verification = app(StoreVerificationService::class)->confirmByApiKey($store);

        $this->assertSame([StoreVerification::STATUS_FAILED, 'trial_store_already_used'], [$verification->status, $verification->last_error_code]);
        $this->assertNull($store->fresh()->verified_at);

        Subscription::query()->where('tenant_id', $second['tenant']->id)->update(['status' => Subscription::STATUS_ACTIVE, 'provider' => 'paddle', 'trial_ends_at' => null]);
        $this->assertSame(StoreVerification::STATUS_VERIFIED, app(StoreVerificationService::class)->confirmByApiKey($store)->status);
        $this->assertSame($first['tenant']->id, DB::table('trial_store_hosts')->where('host', 'shop.de')->value('tenant_id'));
    }

    public function test_a_store_verified_during_the_trial_claims_its_domain(): void
    {
        Carbon::setTestNow('2026-10-12T12:00:00Z');
        $version = $this->version();
        $first = $this->verified($this->context());
        $store = $this->storeAt($first, 'https://later.de', false);
        $this->trial($first, $version)->assertCreated();
        $this->assertSame(0, DB::table('trial_store_hosts')->count());

        $this->assertSame(StoreVerification::STATUS_VERIFIED, app(StoreVerificationService::class)->confirmByApiKey($store)->status);
        $this->assertSame($first['tenant']->id, DB::table('trial_store_hosts')->where('host', 'later.de')->value('tenant_id'));

        $second = $this->verified($this->context());
        $this->storeAt($second, 'https://www.later.de', true);
        $this->trial($second, $version)->assertStatus(409)->assertJsonPath('code', 'trial_store_already_used');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function storeAt(array $context, string $baseUrl, bool $verified): Store
    {
        return Store::query()->create([
            'tenant_id' => $context['tenant']->id,
            'name' => 'Shop',
            'base_url' => $baseUrl,
            'timezone' => 'Europe/Berlin',
            'default_currency' => 'EUR',
            'verified_at' => $verified ? now() : null,
        ]);
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
