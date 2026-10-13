<?php

namespace Tests\Feature\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingEventInbox;
use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Models\BillingSyncCursor;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\Paddle\PaddleEventProcessor;
use App\Support\Billing\Paddle\PaddleEventPuller;
use App\Support\Billing\Paddle\PaddleEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PaddleBillingTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private const API = 'https://sandbox-api.paddle.com';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.client_token', 'test_client_token');
        config()->set('watchdog.billing.paddle.webhook_secret', 'pdl_ntfset_secret');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
        Http::preventStrayRequests();
    }

    public function test_catalog_sync_creates_one_product_per_plan_version_and_stores_every_price_id(): void
    {
        $plan = $this->plan(withRefs: false);
        Http::fake([
            self::API.'/products' => Http::response(['data' => ['id' => 'pro_start']]),
            self::API.'/prices' => Http::sequence()->push(['data' => ['id' => 'pri_base']])->push(['data' => ['id' => 'pri_extra']]),
        ]);

        $this->artisan('billing:paddle-catalog')->assertSuccessful();
        $this->artisan('billing:paddle-catalog')->assertSuccessful();

        $this->assertSame('pri_base', $plan['base']->fresh()->provider_price_ref);
        $this->assertSame('pri_extra', $plan['extra']->fresh()->provider_price_ref);
        $this->assertSame('pro_start', $plan['extra']->fresh()->provider_product_ref);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'/prices'
            && $request['unit_price'] === ['amount' => '900', 'currency_code' => 'EUR']
            && $request['quantity'] === ['minimum' => 1, 'maximum' => 4]
            && $request->hasHeader('Paddle-Version', '1'));
    }

    public function test_owner_starts_a_checkout_with_base_and_extra_store_items(): void
    {
        $context = $this->context();
        $plan = $this->plan();
        Http::fake([self::API.'/transactions' => Http::response(['data' => ['id' => 'txn_1', 'status' => 'draft']], 201)]);

        $response = $this->checkout($context, ['price_id' => $plan['base']->id, 'extra_stores' => 2])
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('provider_checkout.transaction_id', 'txn_1')
            ->assertJsonPath('provider_checkout.environment', 'sandbox')
            ->assertJsonPath('provider_checkout.client_token', 'test_client_token');

        Http::assertSent(fn (Request $request): bool => $request['items'] === [['price_id' => 'pri_base', 'quantity' => 1], ['price_id' => 'pri_extra', 'quantity' => 2]]
            && $request['custom_data'] === ['tenant_id' => $context['tenant']->id, 'checkout_id' => $response->json('id')]);
        $this->asMember($context)->getJson('/api/v1/billing/checkouts/'.$response->json('id'))->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_checkout_adds_the_faster_checks_item_only_when_the_package_offers_it(): void
    {
        $context = $this->context();
        $plan = $this->plan();
        Http::fake([self::API.'/transactions' => Http::response(['data' => ['id' => 'txn_1', 'status' => 'draft']], 201)]);

        $this->checkout($context, ['price_id' => $plan['base']->id, 'faster_checks' => true])->assertStatus(422)->assertJsonPath('code', 'faster_checks_unavailable');

        $plan['version']->forceFill(['faster_check_interval_minutes' => 5])->save();
        BillingPrice::query()->create([
            'plan_version_id' => $plan['version']->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_price_ref' => 'pri_faster',
            'price_kind' => BillingPrice::KIND_FASTER_CHECKS, 'mode' => 'test', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => 500,
            'billing_interval' => 'month', 'tax_behavior' => 'provider_calculated', 'state' => BillingPlan::STATE_PUBLISHED,
        ]);

        $response = $this->checkout($context, ['price_id' => $plan['base']->id, 'extra_stores' => 1, 'faster_checks' => true])->assertStatus(202);

        Http::assertSent(fn (Request $request): bool => $request['items'] === [['price_id' => 'pri_base', 'quantity' => 1], ['price_id' => 'pri_extra', 'quantity' => 1], ['price_id' => 'pri_faster', 'quantity' => 1]]);
        $this->assertNotNull(BillingCheckoutSession::query()->find($response->json('id'))->faster_checks_price_id);
    }

    public function test_checkout_is_owner_only_idempotent_and_refuses_a_second_live_subscription(): void
    {
        $owner = $this->context();
        $admin = $this->context('admin', $owner['tenant'], $owner['store'], $owner['integration']);
        $plan = $this->plan();
        Http::fake([self::API.'/transactions' => Http::response(['data' => ['id' => 'txn_1']], 201)]);
        $key = (string) Str::uuid();

        $this->checkout($admin, ['price_id' => $plan['base']->id])->assertForbidden();
        $first = $this->checkout($owner, ['price_id' => $plan['base']->id], $key)->assertStatus(202);
        $this->checkout($owner, ['price_id' => $plan['base']->id], $key)->assertStatus(202)->assertJsonPath('id', $first->json('id'));
        $this->checkout($owner, ['price_id' => $plan['base']->id, 'extra_stores' => 1], $key)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->checkout($owner, ['price_id' => $plan['base']->id, 'extra_stores' => 5])->assertStatus(422)->assertJsonPath('code', 'extra_stores_out_of_range');
        $this->checkout($owner, ['price_id' => $plan['extra']->id])->assertStatus(422)->assertJsonPath('code', 'price_not_available');
        Http::assertSentCount(1);

        $this->contract($owner['tenant'], $plan, 'active');
        $this->checkout($owner, ['price_id' => $plan['base']->id])->assertStatus(409)->assertJsonPath('code', 'subscription_exists');
    }

    public function test_a_new_checkout_supersedes_the_pending_one_and_cancels_its_transaction(): void
    {
        $context = $this->context();
        $plan = $this->plan();
        Http::fake([
            self::API.'/transactions' => Http::sequence()->push(['data' => ['id' => 'txn_1']], 201)->push(['data' => ['id' => 'txn_2']], 201),
            self::API.'/transactions/txn_1' => Http::response(['data' => ['id' => 'txn_1', 'status' => 'canceled']]),
        ]);

        $first = $this->checkout($context, ['price_id' => $plan['base']->id])->json('id');
        $this->checkout($context, ['price_id' => $plan['base']->id])->assertJsonPath('provider_checkout.transaction_id', 'txn_2');

        $this->assertSame('cancelled', BillingCheckoutSession::query()->find($first)->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request->url() === self::API.'/transactions/txn_1' && $request['status'] === 'canceled');
    }

    public function test_lost_paddle_answer_leaves_the_checkout_unknown_not_failed(): void
    {
        $context = $this->context();
        $plan = $this->plan();
        Http::fake([self::API.'/transactions' => Http::response(['error' => ['code' => 'internal_error']], 502)]);

        $this->checkout($context, ['price_id' => $plan['base']->id])
            ->assertStatus(202)
            ->assertJsonPath('status', 'unknown')
            ->assertJsonPath('provider_checkout', null);
    }

    public function test_webhook_checks_the_signature_and_stores_only_sanitized_fields_once(): void
    {
        $event = $this->event('evt_1', 'subscription.updated', $this->subscriptionData('sub_1', 'active', 'tenant', 'checkout') + [
            'billing_details' => ['payment_terms' => ['interval' => 'day']],
            'address' => ['first_line' => 'Hauptstraße 1'],
        ]);
        $event['data']['customer'] = ['email' => 'buyer@example.com', 'name' => 'Max Mustermann'];

        $this->webhook($event, 'wrong')->assertStatus(400)->assertJsonPath('code', 'signature_invalid');
        $this->webhook($event, timestamp: time() - 600)->assertStatus(400);
        $this->webhook($event)->assertOk()->assertJsonPath('outcome', 'stored');
        $this->webhook($event)->assertOk()->assertJsonPath('outcome', 'duplicate');
        $changed = $event;
        $changed['data']['status'] = 'canceled';
        $this->webhook($changed)->assertOk()->assertJsonPath('outcome', 'conflict');

        $stored = BillingEventInbox::query()->sole();
        $this->assertSame(BillingEventInbox::STATUS_RECEIVED, $stored->status);
        $this->assertSame('webhook', $stored->source);
        $encoded = json_encode($stored->sanitized_payload);
        $this->assertStringNotContainsString('buyer@example.com', $encoded);
        $this->assertStringNotContainsString('Hauptstraße', $encoded);
        $this->assertStringNotContainsString('Mustermann', $encoded);
        $this->assertEquals([['price_id' => 'pri_base', 'quantity' => 1, 'status' => 'active'], ['price_id' => 'pri_extra', 'quantity' => 2, 'status' => 'active']], $stored->sanitized_payload['items']);
    }

    public function test_events_of_unused_types_are_kept_as_ignored_and_webhook_needs_a_secret(): void
    {
        $this->webhook($this->event('evt_tok', 'client_token.created', ['id' => 'ctkn_1', 'token' => 'test_secretish']))->assertOk();

        $stored = BillingEventInbox::query()->sole();
        $this->assertSame(BillingEventInbox::STATUS_IGNORED, $stored->status);
        $this->assertSame(['id' => 'ctkn_1'], $stored->sanitized_payload);

        config()->set('watchdog.billing.paddle.webhook_secret', null);
        $this->webhook($this->event('evt_2', 'subscription.updated', ['id' => 'sub_1']))->assertStatus(409);
    }

    public function test_paid_subscription_from_our_checkout_is_projected_with_its_stores(): void
    {
        $context = $this->context();
        $plan = $this->plan();
        $session = $this->checkoutSession($context, $plan, 'txn_1');
        $this->record('evt_1', 'transaction.completed', ['id' => 'txn_1', 'status' => 'completed', 'origin' => 'api', 'subscription_id' => 'sub_1', 'custom_data' => ['tenant_id' => $context['tenant']->id, 'checkout_id' => $session->id]]);
        $this->record('evt_2', 'subscription.activated', ['id' => 'sub_1', 'status' => 'active']);
        $this->fakeSubscription('sub_1', 'active', $context['tenant']->id, $session->id);

        $summary = app(PaddleEventProcessor::class)->processDue();

        $this->assertSame(['processed' => 2, 'retried' => 0, 'quarantined' => 0], $summary);
        Http::assertSentCount(2);
        $subscription = Subscription::query()->sole();
        $this->assertSame([Subscription::STATUS_ACTIVE, 'start', 2, 'paddle', 'ctm_1'], [$subscription->status, $subscription->plan_code, $subscription->extra_store_quantity, $subscription->provider, $subscription->provider_customer_ref]);
        $this->assertSame('2026-11-11T00:00:00+00:00', $subscription->period_end->toAtomString());
        $this->assertSame(BillingCheckoutSession::STATUS_CONFIRMED, $session->fresh()->status);
        $this->assertNotNull($session->fresh()->contract_id);

        config()->set('watchdog.billing.enforcement', 'enforce');
        $this->asMember($context)->getJson('/api/v1/billing/subscription')
            ->assertOk()
            ->assertJsonPath('access_state', 'active')
            ->assertJsonPath('entitlements.limits.max_active_stores', 3);
    }

    public function test_subscription_claimed_only_by_custom_data_is_quarantined(): void
    {
        $context = $this->context();
        $plan = $this->plan();
        $session = $this->checkoutSession($context, $plan, 'txn_ours');
        $this->record('evt_1', 'subscription.created', ['id' => 'sub_foreign', 'status' => 'active']);
        Http::fake([
            self::API.'/subscriptions/sub_foreign' => Http::response(['data' => $this->subscriptionData('sub_foreign', 'active', $context['tenant']->id, $session->id)]),
            self::API.'/transactions*' => Http::response(['data' => [['id' => 'txn_browser', 'origin' => 'web', 'custom_data' => ['tenant_id' => $context['tenant']->id, 'checkout_id' => $session->id]]]]),
        ]);

        app(PaddleEventProcessor::class)->processDue();

        $this->assertSame('subscription_not_linked', BillingEventInbox::query()->sole()->error_code);
        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, BillingSubscriptionContract::query()->count());
    }

    public function test_state_changes_follow_paddle_and_unknown_prices_change_nothing(): void
    {
        $context = $this->context();
        $plan = $this->plan();
        $session = $this->checkoutSession($context, $plan, 'txn_1');
        $tenantId = $context['tenant']->id;
        $current = $this->subscriptionData('sub_1', 'past_due', $tenantId, $session->id);
        Http::fake([
            self::API.'/subscriptions/sub_1' => function () use (&$current) {
                return Http::response(['data' => $current]);
            },
            self::API.'/transactions*' => Http::response(['data' => [['id' => 'txn_1', 'origin' => 'api', 'custom_data' => ['tenant_id' => $tenantId, 'checkout_id' => $session->id]]]]),
        ]);

        $this->record('evt_1', 'subscription.past_due', ['id' => 'sub_1']);
        app(PaddleEventProcessor::class)->processDue();
        $pastDue = Subscription::query()->sole();
        $this->assertSame(Subscription::STATUS_PAST_DUE, $pastDue->status);
        $this->assertNotNull($pastDue->past_due_since);

        $current = ['updated_at' => '2026-10-11T13:00:00Z'] + $this->subscriptionData('sub_1', 'active', $tenantId, $session->id, [['price' => ['id' => 'pri_unknown'], 'quantity' => 1, 'status' => 'active']]);
        $this->record('evt_2', 'subscription.updated', ['id' => 'sub_1', 'status' => 'active']);
        app(PaddleEventProcessor::class)->processDue();
        $this->assertSame('unknown_price', BillingSubscriptionContract::query()->sole()->problem_code);
        $this->assertSame(BillingSubscriptionContract::STATE_UNKNOWN, BillingSubscriptionContract::query()->sole()->normalized_state);
        $this->assertSame(Subscription::STATUS_PAST_DUE, Subscription::query()->sole()->status);

        $current = ['updated_at' => '2026-10-11T14:00:00Z'] + $this->subscriptionData('sub_1', 'canceled', $tenantId, $session->id);
        $this->record('evt_3', 'subscription.canceled', ['id' => 'sub_1', 'status' => 'canceled']);
        app(PaddleEventProcessor::class)->processDue();
        $this->assertSame(Subscription::STATUS_CANCELLED, Subscription::query()->sole()->status);
        $this->assertNull(Subscription::query()->sole()->past_due_since);
        $this->assertNull(BillingSubscriptionContract::query()->sole()->problem_code);
    }

    public function test_an_older_read_never_overwrites_a_newer_projection(): void
    {
        $context = $this->context();
        $plan = $this->plan();
        $this->contract($context['tenant'], $plan, 'cancelled', ['source_updated_at' => Carbon::parse('2026-10-11T15:00:00Z')]);
        $this->fakeSubscription('sub_1', 'active', $context['tenant']->id, 'none');
        $this->record('evt_1', 'subscription.updated', ['id' => 'sub_1']);

        app(PaddleEventProcessor::class)->processDue();

        $this->assertSame('cancelled', BillingSubscriptionContract::query()->sole()->normalized_state);
    }

    public function test_paddle_failures_retry_with_backoff_then_dead_letter(): void
    {
        config()->set('watchdog.billing.event_max_attempts', 2);
        Http::fake([self::API.'/subscriptions/*' => Http::response(['error' => ['code' => 'internal_error']], 500)]);
        $this->record('evt_1', 'subscription.updated', ['id' => 'sub_1']);

        $this->assertSame(['processed' => 0, 'retried' => 1, 'quarantined' => 0], app(PaddleEventProcessor::class)->processDue());
        $event = BillingEventInbox::query()->sole();
        $this->assertSame('paddle_unavailable', $event->error_code);
        $this->assertTrue($event->next_attempt_at->isFuture());

        Carbon::setTestNow(now()->addMinutes(3));
        app(PaddleEventProcessor::class)->processDue();
        $this->assertSame(BillingEventInbox::STATUS_DEAD_LETTER, BillingEventInbox::query()->sole()->status);
    }

    public function test_event_log_pull_records_events_and_keeps_the_cursor_behind_the_settle_window(): void
    {
        Carbon::setTestNow('2026-10-11T15:00:00Z');
        Http::fake([self::API.'/events*' => Http::sequence()
            ->push(['data' => [
                $this->event('evt_a', 'subscription.created', ['id' => 'sub_1', 'status' => 'active'], '2026-10-11T14:50:00Z'),
                $this->event('evt_b', 'transaction.paid', ['id' => 'txn_1', 'status' => 'paid'], '2026-10-11T14:59:30Z'),
            ], 'meta' => ['pagination' => ['has_more' => false]]])
            ->push(['data' => [
                $this->event('evt_b', 'transaction.paid', ['id' => 'txn_1', 'status' => 'paid'], '2026-10-11T14:59:30Z'),
            ], 'meta' => ['pagination' => ['has_more' => false]]])]);

        $this->assertSame(2, app(PaddleEventPuller::class)->pull()['stored']);
        $this->assertSame('evt_a', BillingSyncCursor::query()->sole()->cursor);

        Carbon::setTestNow('2026-10-11T15:05:00Z');
        $this->assertSame(0, app(PaddleEventPuller::class)->pull()['stored']);
        $this->assertSame('evt_b', BillingSyncCursor::query()->sole()->cursor);
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'order_by=id[ASC]') && str_contains($request->url(), 'after=evt_a'));
        $this->assertSame(['events_api'], BillingEventInbox::query()->distinct()->pluck('source')->all());
    }

    public function test_subscription_endpoint_is_empty_without_a_subscription(): void
    {
        $context = $this->context();

        $this->asMember($context)->getJson('/api/v1/billing/subscription')->assertNoContent();
    }

    /**
     * @return array{version: BillingPlanVersion, base: BillingPrice, extra: BillingPrice}
     */
    private function plan(bool $withRefs = true): array
    {
        $plan = BillingPlan::query()->create(['code' => 'start', 'state' => BillingPlan::STATE_PUBLISHED]);
        $version = BillingPlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version' => 1,
            'state' => BillingPlan::STATE_PUBLISHED,
            'localized_content' => ['name' => ['en' => 'Start']],
            'entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_EXTRA_STORES => 4]]),
        ]);
        $price = fn (string $kind, int $amount, string $ref): BillingPrice => BillingPrice::query()->create([
            'plan_version_id' => $version->id,
            'provider' => 'paddle',
            'provider_account_ref' => 'default',
            'provider_price_ref' => $withRefs ? $ref : null,
            'price_kind' => $kind,
            'mode' => 'test',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => $amount,
            'billing_interval' => 'month',
            'tax_behavior' => 'provider_calculated',
            'state' => BillingPlan::STATE_PUBLISHED,
        ]);

        return ['version' => $version, 'base' => $price(BillingPrice::KIND_BASE, 1900, 'pri_base'), 'extra' => $price(BillingPrice::KIND_EXTRA_STORE, 900, 'pri_extra')];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $plan
     */
    private function checkoutSession(array $context, array $plan, string $transactionRef): BillingCheckoutSession
    {
        return BillingCheckoutSession::query()->create([
            'tenant_id' => $context['tenant']->id,
            'actor_user_id' => $context['user']->id,
            'plan_version_id' => $plan['version']->id,
            'price_id' => $plan['base']->id,
            'extra_store_price_id' => $plan['extra']->id,
            'extra_store_quantity' => 2,
            'provider' => 'paddle',
            'provider_account_ref' => 'default',
            'provider_session_ref' => $transactionRef,
            'mode' => 'test',
            'status' => BillingCheckoutSession::STATUS_PENDING,
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $overrides
     */
    private function contract(Tenant $tenant, array $plan, string $state, array $overrides = []): BillingSubscriptionContract
    {
        return BillingSubscriptionContract::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $plan['version']->id,
            'provider' => 'paddle',
            'provider_account_ref' => 'default',
            'provider_subscription_ref' => 'sub_1',
            'mode' => 'test',
            'normalized_state' => $state,
            'entitlement_snapshot' => ['features' => [], 'limits' => []],
            'source_updated_at' => now(),
        ], $overrides));
    }

    private function fakeSubscription(string $ref, string $status, string $tenantId, string $checkoutId): void
    {
        Http::fake([
            self::API.'/subscriptions/'.$ref => Http::response(['data' => $this->subscriptionData($ref, $status, $tenantId, $checkoutId)]),
            self::API.'/transactions*' => Http::response(['data' => [['id' => 'txn_1', 'origin' => 'api', 'custom_data' => ['tenant_id' => $tenantId, 'checkout_id' => $checkoutId]]]]),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function subscriptionData(string $ref, string $status, string $tenantId, string $checkoutId, ?array $items = null): array
    {
        return [
            'id' => $ref,
            'status' => $status,
            'customer_id' => 'ctm_1',
            'currency_code' => 'EUR',
            'custom_data' => ['tenant_id' => $tenantId, 'checkout_id' => $checkoutId],
            'items' => $items ?? [
                ['price' => ['id' => 'pri_base'], 'quantity' => 1, 'status' => 'active'],
                ['price' => ['id' => 'pri_extra'], 'quantity' => 2, 'status' => 'active'],
            ],
            'current_billing_period' => ['starts_at' => '2026-10-11T00:00:00Z', 'ends_at' => '2026-11-11T00:00:00Z'],
            'next_billed_at' => '2026-11-11T00:00:00Z',
            'scheduled_change' => null,
            'updated_at' => '2026-10-11T12:00:00Z',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(string $eventRef, string $type, array $data): void
    {
        app(PaddleEventRecorder::class)->record($this->event($eventRef, $type, $data), BillingEventInbox::SOURCE_WEBHOOK);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function event(string $eventRef, string $type, array $data, string $occurredAt = '2026-10-11T12:00:00Z'): array
    {
        return ['event_id' => $eventRef, 'event_type' => $type, 'occurred_at' => $occurredAt, 'notification_id' => 'ntf_1', 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function webhook(array $event, string $secret = 'pdl_ntfset_secret', ?int $timestamp = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();

        return $this->call('POST', '/api/v1/webhooks/paddle', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PADDLE_SIGNATURE' => 'ts='.$timestamp.';h1='.hash_hmac('sha256', $timestamp.':'.$body, $secret),
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $body
     */
    private function checkout(array $context, array $body, ?string $key = null): TestResponse
    {
        return $this->asMember($context)
            ->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
            ->postJson('/api/v1/billing/checkout', $body);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function asMember(array $context): static
    {
        return $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
    }
}
