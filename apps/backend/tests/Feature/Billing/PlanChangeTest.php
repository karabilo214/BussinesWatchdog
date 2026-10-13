<?php

namespace Tests\Feature\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingDiscount;
use App\Models\BillingPlan;
use App\Models\BillingPlanChange;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\Store;
use App\Models\Subscription;
use App\Support\Billing\DiscountLedger;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\Paddle\PaddleSubscriptionActions;
use App\Support\Billing\Paddle\PaddleSubscriptionSync;
use App\Support\Browser\CheckScheduler;
use App\Support\Browser\ScenarioDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PlanChangeTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private const API = 'https://sandbox-api.paddle.com';

    /** @var array<string, mixed> */
    private array $paddle = [];

    /** @var array<string, array{plan: BillingPlan, version: BillingPlanVersion, base: BillingPrice, extra: BillingPrice}> */
    private array $plans = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.client_token', 'test_client_token');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
        config()->set('watchdog.billing.enforcement', 'enforce');
        Carbon::setTestNow('2026-10-20T12:00:00Z');
        Http::preventStrayRequests();

        $this->plans['start'] = $this->plan('start', 1900, 900, 1, [FeatureCatalog::CHECKOUT_MONITORING]);
        $this->plans['pro'] = $this->plan('pro', 4900, 700, 3, [FeatureCatalog::CHECKOUT_MONITORING, FeatureCatalog::MONEY_RECONCILIATION]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_upgrade_is_previewed_charged_at_once_and_followed_from_paddle(): void
    {
        $context = $this->subscribed('start', 0);

        $preview = $this->asMember($context)->postJson('/api/v1/billing/plan-change-preview', ['price_id' => $this->plans['pro']['base']->id, 'extra_stores' => 1])
            ->assertOk()
            ->assertJsonPath('kind', BillingPlanChange::KIND_IMMEDIATE)
            ->assertJsonPath('status', BillingPlanChange::STATUS_PREVIEWED)
            ->assertJsonPath('due_now_minor', '3341')
            ->assertJsonPath('next_amount_before_minor', '5600')
            ->assertJsonPath('next_amount_minor', '5600')
            ->assertJsonPath('from.plan_code', 'start')
            ->assertJsonPath('to.plan_code', 'pro')
            ->json();
        $this->assertSame([], $this->paddleWrites(), 'A preview changes nothing at Paddle.');

        $key = (string) Str::uuid();
        $this->confirm($context, $preview['preview_token'], $key)
            ->assertStatus(202)
            ->assertJsonPath('change.status', BillingPlanChange::STATUS_APPLIED)
            ->assertJsonPath('subscription.plan_code', 'pro')
            ->assertJsonPath('subscription.extra_store_quantity', 1)
            ->assertJsonPath('subscription.price.amount_minor', '4900');

        $patch = collect($this->paddleWrites())->firstWhere('url', self::API.'/subscriptions/sub_1');
        $this->assertSame('prorated_immediately', $patch['body']['proration_billing_mode']);
        $this->assertSame('prevent_change', $patch['body']['on_payment_failure']);
        $this->assertSame([['price_id' => 'pri_pro', 'quantity' => 1], ['price_id' => 'pri_pro_extra', 'quantity' => 1]], $patch['body']['items']);

        $this->confirm($context, $preview['preview_token'], $key)->assertStatus(202)->assertJsonPath('change.id', $preview['id']);
        $this->assertCount(1, collect($this->paddleWrites())->where('url', self::API.'/subscriptions/sub_1'), 'A replay does not change the subscription twice.');
        $this->confirm($context, (string) Str::uuid(), $key)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->confirm($context, $preview['preview_token'])->assertStatus(409)->assertJsonPath('code', 'preview_used');
        $this->assertDatabaseHas('audit_log', ['tenant_id' => $context['tenant']->id, 'action' => 'billing.plan_change_applied']);
    }

    public function test_faster_checks_are_added_at_once_and_removed_at_the_end_of_the_period(): void
    {
        $version = $this->plans['start']['version'];
        $version->forceFill(['faster_check_interval_minutes' => 5, 'entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_EXTRA_STORES => 4, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 15]])])->save();
        $this->price($version, BillingPrice::KIND_FASTER_CHECKS, 500, 'pri_start_faster');
        $context = $this->subscribed('start', 0);
        $interval = fn (): ?int => app(EntitlementCalculator::class)->forTenant($context['tenant']->id, now())->limit(FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES);
        $this->assertSame(15, $interval());

        $this->asMember($context)->postJson('/api/v1/billing/plan-change-preview', ['price_id' => $this->plans['pro']['base']->id, 'faster_checks' => true])
            ->assertStatus(422)->assertJsonPath('code', 'faster_checks_unavailable');
        $add = $this->asMember($context)->postJson('/api/v1/billing/plan-change-preview', ['price_id' => $this->plans['start']['base']->id, 'faster_checks' => true])
            ->assertOk()->assertJsonPath('kind', BillingPlanChange::KIND_IMMEDIATE)->assertJsonPath('from.faster_checks', false)->assertJsonPath('to.faster_checks', true)->json();
        $this->confirm($context, $add['preview_token'])->assertStatus(202)->assertJsonPath('subscription.faster_check_interval_minutes', 5);

        $patch = collect($this->paddleWrites())->where('url', self::API.'/subscriptions/sub_1')->last();
        $this->assertSame([['price_id' => 'pri_start', 'quantity' => 1], ['price_id' => 'pri_start_faster', 'quantity' => 1]], $patch['body']['items']);
        $this->assertTrue((bool) BillingSubscriptionContract::query()->where('provider_subscription_ref', 'sub_1')->value('faster_checks'));
        $this->assertSame(5, $interval());

        $remove = $this->asMember($context)->postJson('/api/v1/billing/plan-change-preview', ['price_id' => $this->plans['start']['base']->id])
            ->assertOk()->assertJsonPath('kind', BillingPlanChange::KIND_NEXT_PERIOD)->json();
        $this->confirm($context, $remove['preview_token'])->assertStatus(202)->assertJsonPath('change.status', BillingPlanChange::STATUS_SCHEDULED);
        $this->assertSame(5, Subscription::query()->where('tenant_id', $context['tenant']->id)->value('faster_check_interval_minutes'));

        Carbon::setTestNow('2026-11-11T00:00:01Z');
        app(PaddleSubscriptionActions::class)->settle();
        $this->assertNull(Subscription::query()->where('tenant_id', $context['tenant']->id)->value('faster_check_interval_minutes'));
        $this->assertSame(15, $interval());
    }

    public function test_preview_expires_and_goes_stale_when_the_subscription_changes(): void
    {
        $context = $this->subscribed('start', 0);
        $token = $this->preview($context, 'pro')->json('preview_token');

        Carbon::setTestNow(now()->addMinutes(11));
        $this->confirm($context, $token)->assertStatus(409)->assertJsonPath('code', 'preview_expired');

        $token = $this->preview($context, 'pro')->json('preview_token');
        BillingSubscriptionContract::query()->where('provider_subscription_ref', 'sub_1')->increment('version');
        $this->confirm($context, $token)->assertStatus(409)->assertJsonPath('code', 'preview_stale');
        $this->assertSame([], $this->paddleWrites());

        $first = $this->preview($context, 'pro')->json('preview_token');
        $this->preview($context, 'pro', 2);
        $this->confirm($context, $first)->assertStatus(409)->assertJsonPath('code', 'preview_expired');
    }

    public function test_declined_payment_leaves_the_subscription_as_it_was(): void
    {
        $context = $this->subscribed('start', 0);
        $this->paddle['decline'] = true;
        $token = $this->preview($context, 'pro')->json('preview_token');

        $this->confirm($context, $token)->assertStatus(402)->assertJsonPath('code', 'payment_failed');

        $this->assertDatabaseHas('billing_plan_changes', ['tenant_id' => $context['tenant']->id, 'status' => BillingPlanChange::STATUS_FAILED, 'error_code' => 'subscription_payment_declined']);
        $this->assertSame('start', Subscription::query()->where('tenant_id', $context['tenant']->id)->value('plan_code'));
    }

    public function test_lost_answer_is_unknown_until_the_subscription_is_read_again(): void
    {
        $context = $this->subscribed('start', 0);
        $this->paddle['lose_answer'] = true;
        $token = $this->preview($context, 'pro')->json('preview_token');

        $this->confirm($context, $token)->assertStatus(502)->assertJsonPath('code', 'provider_outcome_unknown');
        $this->assertDatabaseHas('billing_plan_changes', ['status' => BillingPlanChange::STATUS_UNKNOWN]);

        Carbon::setTestNow(now()->addMinutes(2));
        $result = app(PaddleSubscriptionActions::class)->settle();

        $this->assertSame(1, $result['resolved']);
        $this->assertDatabaseHas('billing_plan_changes', ['status' => BillingPlanChange::STATUS_APPLIED]);
        $this->assertSame('pro', Subscription::query()->where('tenant_id', $context['tenant']->id)->value('plan_code'));
    }

    public function test_cheaper_plan_waits_for_the_next_period_and_keeps_the_paid_plan_until_then(): void
    {
        $context = $this->subscribed('pro', 1);

        $preview = $this->preview($context, 'start')
            ->assertOk()
            ->assertJsonPath('kind', BillingPlanChange::KIND_NEXT_PERIOD)
            ->assertJsonPath('due_now_minor', '0')
            ->assertJsonPath('next_amount_minor', '1900')
            ->assertJsonPath('effective_at', '2026-11-11T00:00:00.000000Z')
            ->json();

        $this->confirm($context, $preview['preview_token'])
            ->assertStatus(202)
            ->assertJsonPath('change.status', BillingPlanChange::STATUS_SCHEDULED)
            ->assertJsonPath('subscription.plan_code', 'pro')
            ->assertJsonPath('subscription.extra_store_quantity', 1)
            ->assertJsonPath('subscription.price.amount_minor', '4900')
            ->assertJsonPath('subscription.scheduled_change.to.plan_code', 'start');

        $patch = collect($this->paddleWrites())->firstWhere('url', self::API.'/subscriptions/sub_1');
        $this->assertSame('do_not_bill', $patch['body']['proration_billing_mode']);
        $this->assertSame([['price_id' => 'pri_start', 'quantity' => 1]], $patch['body']['items']);
        $this->assertSame($this->plans['start']['base']->id, BillingSubscriptionContract::query()->value('price_id'), 'Paddle already holds the next period price.');

        $this->preview($context, 'pro', 2)->assertStatus(409)->assertJsonPath('code', 'scheduled_change_pending');
        $this->preview($context, 'pro', 1)->assertStatus(409)->assertJsonPath('code', 'scheduled_change_pending');

        app(PaddleSubscriptionActions::class)->settle();
        $this->assertSame('pro', Subscription::query()->where('tenant_id', $context['tenant']->id)->value('plan_code'));

        Carbon::setTestNow('2026-11-11T00:00:30Z');
        $this->assertSame(1, app(PaddleSubscriptionActions::class)->settle()['applied']);
        $this->asMember($context)->getJson('/api/v1/billing/subscription')
            ->assertJsonPath('plan_code', 'start')
            ->assertJsonPath('extra_store_quantity', 0)
            ->assertJsonPath('scheduled_change', null);
    }

    public function test_scheduled_cheaper_plan_can_be_withdrawn(): void
    {
        $context = $this->subscribed('pro', 1);
        $this->confirm($context, $this->preview($context, 'start')->json('preview_token'))->assertStatus(202);

        $this->asMember($context)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/billing/plan-change/cancel')
            ->assertOk()
            ->assertJsonPath('change.status', BillingPlanChange::STATUS_CANCELLED)
            ->assertJsonPath('subscription.plan_code', 'pro')
            ->assertJsonPath('subscription.scheduled_change', null);

        $patch = collect($this->paddleWrites())->where('url', self::API.'/subscriptions/sub_1')->last();
        $this->assertSame('do_not_bill', $patch['body']['proration_billing_mode']);
        $this->assertSame([['price_id' => 'pri_pro', 'quantity' => 1], ['price_id' => 'pri_pro_extra', 'quantity' => 1]], $patch['body']['items']);

        $this->asMember($context)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/billing/plan-change/cancel')
            ->assertStatus(409)->assertJsonPath('code', 'no_scheduled_change');
    }

    public function test_fewer_stores_need_the_owners_choice_and_pause_the_rest_without_deleting(): void
    {
        $context = $this->subscribed('pro', 0);
        $second = $this->store($context, 'Second Shop');
        $this->store($context, 'Third Shop');

        $this->preview($context, 'start')
            ->assertStatus(422)
            ->assertJsonPath('code', 'retained_stores_required')
            ->assertJsonPath('details.limit', 1)
            ->assertJsonPath('details.stores', 3);
        $this->preview($context, 'start', 0, [$context['store']->id, $second->id])->assertStatus(422)->assertJsonPath('code', 'retained_stores_invalid');
        $this->preview($context, 'start', 0, [(string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'retained_stores_invalid');

        $token = $this->preview($context, 'start', 0, [$second->id])->assertOk()->assertJsonPath('retained_store_ids', [$second->id])->json('preview_token');
        $this->confirm($context, $token)->assertStatus(202);
        $this->assertSame(0, Store::query()->whereNotNull('capacity_paused_at')->count(), 'Nothing pauses before the period ends.');

        Carbon::setTestNow('2026-11-11T00:01:00Z');
        app(PaddleSubscriptionActions::class)->settle();

        $this->assertSame([$second->id], Store::query()->whereNull('capacity_paused_at')->pluck('id')->all());
        $this->assertSame(3, Store::query()->where('tenant_id', $context['tenant']->id)->count());
        $this->asMember($context)->getJson('/api/v1/billing/subscription')
            ->assertJsonPath('capacity', ['limit' => 1, 'stores' => 3, 'paused' => 2, 'choice_required' => false]);

        $this->asMember($context)->putJson('/api/v1/billing/retained-stores', ['store_ids' => [$context['store']->id]])
            ->assertOk()->assertJsonPath('paused', 2);
        $this->assertSame([$context['store']->id], Store::query()->whereNull('capacity_paused_at')->pluck('id')->all());
        $this->asMember($context)->putJson('/api/v1/billing/retained-stores', ['store_ids' => [$context['store']->id, $second->id]])
            ->assertStatus(422)->assertJsonPath('code', 'retained_stores_invalid');
    }

    public function test_browser_checks_skip_paused_stores_and_wait_for_a_choice(): void
    {
        $context = $this->subscribed('start', 0);
        $second = $this->store($context, 'Second Shop');
        $mainScenario = $this->scenario($context['store']);
        $secondScenario = $this->scenario($second);
        $scheduler = app(CheckScheduler::class);

        $this->assertSame(CheckScheduler::ERROR_CAPACITY, $scheduler->runNow($mainScenario));
        $scheduler->scheduleDue();
        $this->assertSame(0, CheckRun::query()->count(), 'Without a choice no store is picked for the owner.');

        $this->asMember($context)->putJson('/api/v1/billing/retained-stores', ['store_ids' => [$second->id]])->assertOk();
        CheckScenario::query()->update(['next_due_at' => now()]);
        $scheduler->scheduleDue();

        $this->assertSame([$second->id], CheckRun::query()->pluck('store_id')->all());
        $this->assertSame(CheckScheduler::ERROR_CAPACITY, $scheduler->runNow($mainScenario));
        $this->asMember($context)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/stores/'.$context['store']->id.'/checks', ['scenario_id' => $mainScenario->id])->assertStatus(403)->assertJsonPath('code', 'store_capacity_paused');
        $this->assertNotSame(CheckScheduler::ERROR_CAPACITY, $scheduler->runNow($secondScenario));
    }

    public function test_cancel_at_period_end_and_resume_follow_paddle(): void
    {
        $context = $this->subscribed('start', 0);

        $this->cancelRenewal($context, ['at_period_end' => true, 'reason' => 'Слишком дорого'])
            ->assertOk()
            ->assertJsonPath('cancel_at_period_end', true)
            ->assertJsonPath('status', Subscription::STATUS_ACTIVE)
            ->assertJsonPath('access_state', 'active')
            ->assertJsonPath('period_end', '2026-11-11T00:00:00.000000Z');
        $this->assertSame(['effective_from' => 'next_billing_period'], collect($this->paddleWrites())->firstWhere('url', self::API.'/subscriptions/sub_1/cancel')['body']);
        $this->assertSame('Слишком дорого', json_decode((string) DB::table('audit_log')->where('action', 'billing.subscription_cancel_scheduled')->value('changes'), true)['reason']);

        $this->cancelRenewal($context, ['at_period_end' => true])->assertOk();
        $this->assertCount(1, collect($this->paddleWrites())->where('url', self::API.'/subscriptions/sub_1/cancel'), 'A second cancel changes nothing.');

        $version = Subscription::query()->where('tenant_id', $context['tenant']->id)->value('version');
        $this->resume($context, ['expected_version' => $version + 5])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
        $this->resume($context, ['expected_version' => $version])
            ->assertOk()
            ->assertJsonPath('cancel_at_period_end', false);
        $this->assertSame(['scheduled_change' => null], collect($this->paddleWrites())->where('url', self::API.'/subscriptions/sub_1')->last()['body']);
        $this->resume($context, [])->assertStatus(409)->assertJsonPath('code', 'cancel_not_scheduled');
    }

    public function test_only_the_owner_changes_and_unsupported_changes_are_refused(): void
    {
        $context = $this->subscribed('start', 0);
        $admin = $this->context('admin', $context['tenant'], $context['store'], $context['integration']);

        $this->asMember($admin)->postJson('/api/v1/billing/plan-change-preview', ['price_id' => $this->plans['pro']['base']->id])->assertForbidden();
        $this->asMember($admin)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/billing/cancel', ['at_period_end' => true])->assertForbidden();

        $this->preview($context, 'start')->assertStatus(422)->assertJsonPath('code', 'plan_unchanged');
        $yearly = $this->price($this->plans['pro']['version'], BillingPrice::KIND_BASE, 49000, 'pri_pro_year', 'year');
        $this->asMember($context)->postJson('/api/v1/billing/plan-change-preview', ['price_id' => $yearly->id])->assertStatus(422)->assertJsonPath('code', 'interval_change_unsupported');
        $this->preview($context, 'start', 9)->assertStatus(422)->assertJsonPath('code', 'extra_stores_out_of_range');

        BillingSubscriptionContract::query()->update(['normalized_state' => BillingSubscriptionContract::STATE_PAST_DUE]);
        $this->preview($context, 'pro')->assertStatus(409)->assertJsonPath('code', 'subscription_past_due');
    }

    public function test_next_period_discount_is_planned_again_on_the_new_price(): void
    {
        $context = $this->subscribed('start', 0);
        $contract = BillingSubscriptionContract::query()->firstOrFail();
        $discount = BillingDiscount::query()->create([
            'name' => 'Launch', 'label' => ['en' => 'Launch'], 'kind' => BillingDiscount::KIND_PERCENTAGE, 'percent_bps' => 1000, 'audience' => BillingDiscount::AUDIENCE_AUTOMATIC,
            'duration' => 'forever', 'plan_ids' => [], 'state' => BillingDiscount::STATE_ACTIVE, 'provider_discount_ref' => 'dsc_launch',
            'mode' => 'test', 'provider' => 'paddle', 'provider_account_ref' => 'default',
        ]);
        $starter = BillingDiscount::query()->create([
            'name' => 'Start only', 'label' => ['en' => 'Start only'], 'kind' => BillingDiscount::KIND_FLAT, 'amount_minor' => 300, 'currency' => 'EUR', 'currency_exponent' => 2, 'audience' => BillingDiscount::AUDIENCE_CODE, 'code' => 'STARTER',
            'duration' => 'forever', 'plan_ids' => [$this->plans['start']['plan']->id], 'state' => BillingDiscount::STATE_ACTIVE, 'provider_discount_ref' => 'dsc_starter',
            'mode' => 'test', 'provider' => 'paddle', 'provider_account_ref' => 'default',
        ]);
        $session = $this->checkoutSession($context, $contract);
        foreach ([[$discount, 1], [$starter, 3]] as [$granted, $position]) {
            DB::table('billing_discount_grants')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $context['tenant']->id, 'checkout_session_id' => $session->id, 'contract_id' => $contract->id, 'discount_id' => $granted->id, 'position' => $position, 'periods_total' => null, 'periods_used' => 1, 'created_at' => now()]);
        }
        app(DiscountLedger::class)->planNextPeriod($contract);
        $this->assertDatabaseHas('billing_discount_applications', ['contract_id' => $contract->id, 'amount_before_minor' => 1900, 'saving_minor' => 490, 'status' => 'applied']);

        $preview = $this->preview($context, 'pro')->assertJsonPath('next_amount_before_minor', '4900')->assertJsonPath('next_amount_minor', '4410')->json();
        $this->confirm($context, $preview['preview_token'])->assertStatus(202);

        $application = DB::table('billing_discount_applications')->where('contract_id', $contract->id)->where('period_start', $contract->period_end)->first();
        $this->assertSame([4900, 490, 4410, 'applied'], [(int) $application->amount_before_minor, (int) $application->saving_minor, (int) $application->amount_after_minor, $application->status]);
        $this->assertSame(1, DB::table('billing_discount_application_lines')->where('application_id', $application->id)->count(), 'The Start-only code no longer applies.');
        $this->assertSame(2, (int) DB::table('billing_discount_grants')->where('discount_id', $discount->id)->value('periods_used'));
        $this->assertSame(1, (int) DB::table('billing_discount_grants')->where('discount_id', $starter->id)->value('periods_used'));
        $writes = collect($this->paddleWrites());
        $this->assertTrue($writes->contains(fn (array $write): bool => $write['url'] === self::API.'/subscriptions/sub_1' && array_key_exists('discount', $write['body']) && $write['body']['discount'] === null), 'The old combined discount is removed.');
        $this->assertSame('490', $writes->where('url', self::API.'/discounts')->last()['body']['amount']);
        $this->assertNotNull(BillingPlanChange::query()->value('discounts_settled_at'));
    }

    /**
     * @return array<string, mixed>
     */
    private function subscribed(string $plan, int $extra): array
    {
        $context = $this->context();
        Store::query()->whereKey($context['store']->id)->update(['status' => 'active', 'browser_enabled' => true, 'verified_at' => now()]);
        BillingSubscriptionContract::query()->create([
            'tenant_id' => $context['tenant']->id,
            'plan_version_id' => $this->plans[$plan]['version']->id,
            'price_id' => $this->plans[$plan]['base']->id,
            'extra_store_quantity' => $extra,
            'provider' => 'paddle',
            'provider_account_ref' => 'default',
            'provider_subscription_ref' => 'sub_1',
            'mode' => 'test',
            'normalized_state' => BillingSubscriptionContract::STATE_ACTIVE,
            'currency' => 'EUR',
            'period_start' => '2026-10-11T00:00:00Z',
            'period_end' => '2026-11-11T00:00:00Z',
            'paid_until' => '2026-11-11T00:00:00Z',
            'entitlement_snapshot' => ['features' => [], 'limits' => []],
            'source_updated_at' => '2026-10-11T12:00:00Z',
        ]);
        $this->paddle = [
            'items' => array_values(array_filter([
                ['price' => ['id' => 'pri_'.$plan], 'quantity' => 1, 'status' => 'active'],
                $extra > 0 ? ['price' => ['id' => 'pri_'.$plan.'_extra'], 'quantity' => $extra, 'status' => 'active'] : null,
            ])),
            'scheduled_change' => null,
            'tick' => 0,
            'writes' => [],
        ];
        $this->fakePaddle();
        app(PaddleSubscriptionSync::class)->sync('sub_1');

        return $context;
    }

    private function fakePaddle(): void
    {
        Http::fake(function (Request $request) {
            $path = substr($request->url(), strlen(self::API));
            $body = $request->data();

            if ($request->method() !== 'GET' && ! str_ends_with($path, '/preview')) {
                $this->paddle['writes'][] = ['url' => $request->url(), 'body' => $body];
            }

            if ($request->method() === 'PATCH' && $path === '/subscriptions/sub_1/preview') {
                $immediate = $body['proration_billing_mode'] === 'prorated_immediately';

                return Http::response(['data' => [
                    'immediate_transaction' => $immediate ? ['details' => ['totals' => ['grand_total' => '3341', 'currency_code' => 'EUR']]] : null,
                    'next_transaction' => ['details' => ['totals' => ['grand_total' => '9999']]],
                ]]);
            }

            if ($request->method() === 'PATCH' && $path === '/subscriptions/sub_1') {
                if (! empty($this->paddle['decline']) && isset($body['items'])) {
                    return Http::response(['error' => ['code' => 'subscription_payment_declined']], 400);
                }

                if (array_key_exists('items', $body)) {
                    $this->paddle['items'] = array_map(fn (array $item): array => ['price' => ['id' => $item['price_id']], 'quantity' => $item['quantity'], 'status' => 'active'], $body['items']);
                }

                if (array_key_exists('scheduled_change', $body)) {
                    $this->paddle['scheduled_change'] = $body['scheduled_change'];
                }

                $this->paddle['tick']++;

                if (! empty($this->paddle['lose_answer'])) {
                    $this->paddle['lose_answer'] = false;

                    return Http::response(['error' => ['code' => 'internal_error']], 502);
                }

                return Http::response(['data' => $this->subscription()]);
            }

            if ($request->method() === 'POST' && $path === '/subscriptions/sub_1/cancel') {
                $this->paddle['scheduled_change'] = ['action' => 'cancel', 'effective_at' => '2026-11-11T00:00:00Z'];
                $this->paddle['tick']++;

                return Http::response(['data' => $this->subscription()]);
            }

            if ($request->method() === 'POST' && $path === '/discounts') {
                return Http::response(['data' => ['id' => 'dsc_'.Str::lower(Str::random(8))]], 201);
            }

            if ($request->method() === 'GET' && $path === '/subscriptions/sub_1') {
                return Http::response(['data' => $this->subscription()]);
            }

            return Http::response(['error' => ['code' => 'not_found']], 404);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(): array
    {
        return [
            'id' => 'sub_1',
            'status' => 'active',
            'customer_id' => 'ctm_1',
            'currency_code' => 'EUR',
            'items' => $this->paddle['items'],
            'current_billing_period' => ['starts_at' => '2026-10-11T00:00:00Z', 'ends_at' => '2026-11-11T00:00:00Z'],
            'next_billed_at' => $this->paddle['scheduled_change'] === null ? '2026-11-11T00:00:00Z' : null,
            'scheduled_change' => $this->paddle['scheduled_change'],
            'updated_at' => Carbon::parse('2026-10-11T12:00:00Z')->addSeconds($this->paddle['tick'])->toIso8601ZuluString(),
        ];
    }

    /**
     * @return list<array{url: string, body: array<string, mixed>}>
     */
    private function paddleWrites(): array
    {
        return $this->paddle['writes'] ?? [];
    }

    /**
     * @param  list<string>  $features
     * @return array{plan: BillingPlan, version: BillingPlanVersion, base: BillingPrice, extra: BillingPrice}
     */
    private function plan(string $code, int $base, int $extra, int $stores, array $features): array
    {
        $plan = BillingPlan::query()->create(['code' => $code, 'state' => BillingPlan::STATE_PUBLISHED]);
        $version = BillingPlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version' => 1,
            'state' => BillingPlan::STATE_PUBLISHED,
            'localized_content' => ['name' => ['en' => ucfirst($code)]],
            'entitlements' => FeatureCatalog::normalize(['features' => $features, 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => $stores, FeatureCatalog::MAX_EXTRA_STORES => 4]]),
        ]);

        return [
            'plan' => $plan,
            'version' => $version,
            'base' => $this->price($version, BillingPrice::KIND_BASE, $base, 'pri_'.$code),
            'extra' => $this->price($version, BillingPrice::KIND_EXTRA_STORE, $extra, 'pri_'.$code.'_extra'),
        ];
    }

    private function price(BillingPlanVersion $version, string $kind, int $amount, string $ref, string $interval = 'month'): BillingPrice
    {
        return BillingPrice::query()->create([
            'plan_version_id' => $version->id,
            'provider' => 'paddle',
            'provider_account_ref' => 'default',
            'provider_price_ref' => $ref,
            'price_kind' => $kind,
            'mode' => 'test',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => $amount,
            'billing_interval' => $interval,
            'tax_behavior' => 'provider_calculated',
            'state' => BillingPlan::STATE_PUBLISHED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function store(array $context, string $name): Store
    {
        return Store::query()->create([
            'tenant_id' => $context['tenant']->id,
            'name' => $name,
            'base_url' => 'https://'.Str::lower(Str::random(10)).'.example',
            'timezone' => 'Europe/Berlin',
            'default_currency' => 'EUR',
            'status' => 'active',
            'browser_enabled' => true,
            'verified_at' => now(),
        ]);
    }

    private function scenario(Store $store): CheckScenario
    {
        return CheckScenario::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'name' => 'Payment form',
            'mode' => CheckScenario::MODE_PAYMENT_FORM,
            'version' => 1,
            'enabled' => true,
            'adapter_version' => ScenarioDefinition::ADAPTER_VERSION,
            'definition' => ['product_url' => $store->base_url.'/product/x'],
            'interval_seconds' => 900,
            'next_due_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function checkoutSession(array $context, BillingSubscriptionContract $contract): BillingCheckoutSession
    {
        return BillingCheckoutSession::query()->create([
            'tenant_id' => $context['tenant']->id,
            'actor_user_id' => $context['user']->id,
            'plan_version_id' => $contract->plan_version_id,
            'price_id' => $contract->price_id,
            'extra_store_quantity' => 0,
            'provider' => 'paddle',
            'provider_account_ref' => 'default',
            'provider_session_ref' => 'txn_1',
            'contract_id' => $contract->id,
            'mode' => 'test',
            'status' => BillingCheckoutSession::STATUS_CONFIRMED,
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>|null  $retained
     */
    private function preview(array $context, string $plan, int $extra = 0, ?array $retained = null): TestResponse
    {
        return $this->asMember($context)->postJson('/api/v1/billing/plan-change-preview', array_filter([
            'price_id' => $this->plans[$plan]['base']->id,
            'extra_stores' => $extra,
            'retained_store_ids' => $retained,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function confirm(array $context, string $token, ?string $key = null): TestResponse
    {
        return $this->asMember($context)->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson('/api/v1/billing/plan-change', ['preview_token' => $token]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $body
     */
    private function cancelRenewal(array $context, array $body): TestResponse
    {
        return $this->asMember($context)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/billing/cancel', $body);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $body
     */
    private function resume(array $context, array $body): TestResponse
    {
        return $this->asMember($context)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/billing/resume', $body);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function asMember(array $context): static
    {
        return $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
    }
}
