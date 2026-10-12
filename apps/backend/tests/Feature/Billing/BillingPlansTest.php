<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Support\Billing\FeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class BillingPlansTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.client_token', 'test_client_token');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
    }

    public function test_members_see_the_latest_sellable_version_of_each_plan_with_its_prices(): void
    {
        $context = $this->context('viewer');
        $pro = $this->plan('pro', 1, ['base' => 4900, 'extra_store' => 700]);
        $this->plan('pro', 2, ['base' => 5900], $pro);
        $this->plan('start', 1, ['base' => 1900]);
        $this->plan('free', 1, []);
        $this->plan('old', 1, ['base' => 100], null, BillingPlan::STATE_RETIRED);
        BillingPrice::query()->where('amount_minor', 1900)->update(['provider_price_ref' => null]);

        $response = $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson('/api/v1/billing/plans')
            ->assertOk()
            ->assertJsonPath('mode', 'test')
            ->assertJsonPath('checkout_available', true)
            ->assertJsonPath('trial_available', true);

        $plans = collect($response->json('data'))->keyBy('plan_code');
        $this->assertSame(['pro', 'free', 'start'], $plans->keys()->all());
        $this->assertSame([['id' => $plans['pro']['prices'][0]['id'], 'kind' => 'base', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => '5900', 'interval' => 'month', 'tax_behavior' => 'provider_calculated']], $plans['pro']['prices']);
        $this->assertSame(2, BillingPlanVersion::query()->whereKey($plans['pro']['plan_version_id'])->value('version'));
        $this->assertSame([], $plans['start']['prices']);
        $this->assertSame(['ru' => 'Pro'], $plans['pro']['name']);
        $this->assertSame(14, $plans['pro']['trial_days']);

        $context['tenant']->forceFill(['trial_consumed_at' => now()])->save();
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson('/api/v1/billing/plans')->assertJsonPath('trial_available', false);

        config()->set('watchdog.billing.paddle.environment', 'production');
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson('/api/v1/billing/plans')->assertJsonPath('data', []);
    }

    /**
     * @param  array<string, int>  $prices
     */
    private function plan(string $code, int $version, array $prices, ?BillingPlanVersion $existing = null, string $state = BillingPlan::STATE_DRAFT): BillingPlanVersion
    {
        $plan = $existing?->plan ?? BillingPlan::query()->create(['code' => $code, 'state' => $state]);
        $model = BillingPlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version' => $version,
            'state' => $state,
            'localized_content' => ['name' => ['ru' => ucfirst($code)]],
            'entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1]]),
            'trial_days' => 14,
        ]);

        foreach ($prices as $kind => $amount) {
            BillingPrice::query()->create([
                'plan_version_id' => $model->id,
                'provider' => 'paddle',
                'provider_account_ref' => 'default',
                'provider_price_ref' => 'pri_'.$code.$version.$kind,
                'price_kind' => $kind,
                'mode' => 'test',
                'currency' => 'EUR',
                'currency_exponent' => 2,
                'amount_minor' => $amount,
                'billing_interval' => 'month',
                'tax_behavior' => 'provider_calculated',
                'state' => $state,
            ]);
        }

        return $model;
    }
}
