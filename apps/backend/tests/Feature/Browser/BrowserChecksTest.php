<?php

namespace Tests\Feature\Browser;

use App\Models\BrowserWorker;
use App\Models\CheckAttempt;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\DomainOutbox;
use App\Models\Incident;
use App\Models\NotificationDelivery;
use App\Models\Store;
use App\Support\Browser\BrowserLeaseService;
use App\Support\Browser\CheckOutcomeEvaluator;
use App\Support\Browser\CheckScheduler;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Notifications\Channels\NotificationSenderRegistry;
use App\Support\Notifications\NotificationDeliveryWorker;
use App\Support\Outbox\DomainOutboxDispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\FakeNotificationSender;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class BrowserChecksTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private string $workerToken;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
        $this->workerToken = 'bwwk_'.bin2hex(random_bytes(32));
        BrowserWorker::query()->create(['name' => 'worker-1', 'token_hash' => hash('sha256', $this->workerToken), 'status' => BrowserWorker::STATUS_ACTIVE, 'created_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scenario_urls_must_stay_on_the_store_origin_and_enabling_needs_verification(): void
    {
        $context = $this->checkContext(verified: false);
        $url = "/api/v1/stores/{$context['store']->id}/check-scenario";

        $this->asOwner($context)->putJson($url, ['product_url' => 'https://evil.example.test/product/x'])
            ->assertStatus(422)->assertJsonPath('code', 'url_outside_store_origin');
        $this->asOwner($context)->putJson($url, ['product_url' => 'https://shop.example.test/product/x', 'enabled' => true])
            ->assertStatus(422)->assertJsonPath('code', 'store_not_verified');

        $created = $this->asOwner($context)->putJson($url, ['product_url' => 'https://shop.example.test/product/x', 'extra_allowed_origins' => ['https://js.stripe.com/v3/']])
            ->assertOk()->assertJsonPath('version', 1)->assertJsonPath('enabled', false);
        $this->assertSame(['https://js.stripe.com'], $created->json('definition.extra_allowed_origins'));

        $this->asOwner($context)->putJson($url, ['product_url' => 'https://shop.example.test/product/y', 'interval_seconds' => 600])
            ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('interval_seconds', 600);
        $this->asOwner($context)->getJson($url)->assertOk()->assertJsonPath('definition.product_url', 'https://shop.example.test/product/y');
    }

    public function test_due_scenarios_on_eligible_stores_get_exactly_one_active_run(): void
    {
        $context = $this->checkContext();
        $scenario = $this->scenario($context);

        $this->assertSame(['due' => 1, 'created' => 1], app(CheckScheduler::class)->scheduleDue());
        $run = CheckRun::query()->sole();
        $this->assertSame(CheckRun::TRIGGER_SCHEDULED, $run->trigger);
        $this->assertSame('https://shop.example.test/checkout/', $run->config_snapshot['checkout_url']);
        $this->assertSame(ScenarioDefinition::BLOCKED_OPERATIONS, $run->config_snapshot['network_policy']['blocked_operations']);

        $scenario->refresh();
        $this->assertTrue($scenario->next_due_at->between(now()->addSeconds(810), now()->addSeconds(990)));

        $scenario->forceFill(['next_due_at' => now()])->save();
        $this->assertSame(0, app(CheckScheduler::class)->scheduleDue()['created']);

        CheckRun::query()->update(['status' => CheckRun::STATUS_PASSED, 'finished_at' => now()]);
        $context['store']->forceFill(['browser_enabled' => false])->save();
        $this->assertSame(0, app(CheckScheduler::class)->scheduleDue()['created']);
    }

    public function test_workers_need_a_valid_token_and_get_204_without_work(): void
    {
        $this->postJson('/internal/v1/browser/leases', ['browser_version' => 'chromium-140', 'location' => 'eu-central'])
            ->assertStatus(401)->assertJsonPath('code', 'worker_unauthorized');
        $this->lease()->assertNoContent();
    }

    public function test_a_lease_hands_out_the_scenario_with_a_fenced_one_time_token(): void
    {
        $context = $this->checkContext();
        $this->scenario($context);
        app(CheckScheduler::class)->scheduleDue();

        $lease = $this->lease()->assertOk();

        $this->assertSame(1, $lease->json('fencing_token'));
        $this->assertSame($context['store']->id, $lease->json('store_id'));
        $this->assertSame('payment_form', $lease->json('scenario.mode'));
        $this->assertSame(['product', 'add_to_cart', 'cart', 'checkout', 'shipping', 'payment_form'], array_column($lease->json('scenario.steps'), 'code'));
        $this->assertSame(['https://shop.example.test'], $lease->json('network_policy.allowed_origins'));
        $attempt = CheckAttempt::query()->sole();
        $this->assertSame(hash('sha256', $lease->json('lease_token')), $attempt->lease_token_hash);
        $this->assertStringNotContainsString($lease->json('lease_token'), json_encode($attempt->toArray()));
        $this->assertSame(CheckRun::STATUS_RUNNING, CheckRun::query()->sole()->status);
        $this->lease()->assertNoContent();
    }

    public function test_heartbeats_extend_the_lease_but_never_past_the_absolute_deadline(): void
    {
        $lease = $this->leasedAttempt();
        $url = '/internal/v1/browser/attempts/'.$lease['attempt_id'].'/heartbeat';

        Carbon::setTestNow(now()->addSeconds(100));
        $beat = $this->worker()->withHeader('X-BW-Lease-Token', $lease['lease_token'])->postJson($url, ['fencing_token' => 1])->assertOk();
        $this->assertSame($lease['absolute_deadline_at'], $beat->json('absolute_deadline_at'));
        $this->assertTrue(Carbon::parse($beat->json('lease_until'))->equalTo(Carbon::parse($lease['absolute_deadline_at'])->addSeconds(30)));

        $this->worker()->withHeader('X-BW-Lease-Token', str_repeat('a', 64))->postJson($url, ['fencing_token' => 1])
            ->assertStatus(409)->assertJsonPath('code', BrowserLeaseService::ERROR_LEASE_TOKEN_INVALID);
        $this->worker()->withHeader('X-BW-Lease-Token', $lease['lease_token'])->postJson($url, ['fencing_token' => 2])
            ->assertStatus(409)->assertJsonPath('code', BrowserLeaseService::ERROR_STALE_FENCING_TOKEN);
    }

    public function test_a_passed_result_finishes_the_run_and_replays_are_idempotent(): void
    {
        $lease = $this->leasedAttempt();
        $body = $this->resultBody(1, 'passed');

        $this->submit($lease, $body)->assertOk()->assertJsonPath('accepted', true);
        $this->submit($lease, $body)->assertOk();
        $this->submit($lease, array_merge($body, ['status' => 'failed', 'error_code' => 'site_failure']))
            ->assertStatus(409)->assertJsonPath('code', BrowserLeaseService::ERROR_RESULT_CONFLICT);

        $run = CheckRun::query()->sole();
        $this->assertSame(CheckRun::STATUS_PASSED, $run->status);
        $this->assertSame(6, CheckAttempt::query()->sole()->steps()->count());
    }

    public function test_two_site_failures_confirm_one_checkout_incident_and_one_is_retried(): void
    {
        $lease = $this->leasedAttempt();
        $this->submit($lease, $this->resultBody(1, 'failed', 'site_failure', failedStep: 'payment_form'))->assertOk();

        $run = CheckRun::query()->sole();
        $this->assertSame(CheckRun::STATUS_QUEUED, $run->status);
        $this->assertTrue($run->next_attempt_at->equalTo(now()->addSeconds(60)));
        $this->assertSame(0, Incident::query()->count());
        $this->lease()->assertNoContent();

        Carbon::setTestNow(now()->addSeconds(61));
        $second = $this->lease()->assertOk()->json();
        $this->assertSame(2, $second['fencing_token']);
        $this->submit($second, $this->resultBody(2, 'failed', 'site_failure', failedStep: 'payment_form'))->assertOk();

        $this->assertSame(CheckRun::STATUS_FAILED, CheckRun::query()->sole()->status);
        $incident = Incident::query()->sole();
        $this->assertSame(CheckOutcomeEvaluator::FAMILY, $incident->family);
        $this->assertSame(CheckOutcomeEvaluator::TITLE_FLOW_FAILED, $incident->title_code);
        $this->assertSame(Incident::SEVERITY_WARNING, $incident->severity);
        $this->assertSame(1, DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->count());
    }

    public function test_worker_problems_never_confirm_a_site_failure(): void
    {
        $lease = $this->leasedAttempt();
        $this->submit($lease, $this->resultBody(1, 'failed', 'site_failure'))->assertOk();

        foreach ([2, 3] as $fencing) {
            Carbon::setTestNow(now()->addSeconds(61));
            $next = $this->lease()->assertOk()->json();
            $this->submit($next, $this->resultBody($fencing, 'inconclusive', 'worker_capacity'))->assertOk();
        }

        $run = CheckRun::query()->sole();
        $this->assertSame(CheckRun::STATUS_INCONCLUSIVE, $run->status);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_an_expired_lease_is_recovered_and_the_old_worker_is_fenced_out(): void
    {
        $old = $this->leasedAttempt();

        Carbon::setTestNow(now()->addSeconds(151));
        $this->assertSame(1, app(BrowserLeaseService::class)->recoverExpired());
        $new = $this->lease()->assertOk()->json();
        $this->assertSame(2, $new['fencing_token']);

        $this->submit($old, $this->resultBody(1, 'passed'))->assertStatus(409)->assertJsonPath('code', BrowserLeaseService::ERROR_LEASE_EXPIRED);
        $this->submit($new, $this->resultBody(2, 'passed'))->assertOk();
        $this->assertSame([CheckAttempt::STATUS_EXPIRED, 'passed'], CheckAttempt::query()->orderBy('attempt_number')->pluck('status')->all());
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_attempts_that_keep_expiring_end_inconclusive(): void
    {
        $this->leasedAttempt();

        for ($i = 0; $i < 3; $i++) {
            Carbon::setTestNow(now()->addSeconds(151));
            app(BrowserLeaseService::class)->recoverExpired();

            if ($i < 2) {
                $this->lease()->assertOk();
            }
        }

        $run = CheckRun::query()->sole();
        $this->assertSame(CheckRun::STATUS_INCONCLUSIVE, $run->status);
        $this->assertSame(BrowserLeaseService::ERROR_INFRA_TIMEOUT, $run->error_code);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_two_spaced_scheduled_passes_resolve_the_checkout_incident(): void
    {
        $context = $this->checkContext();
        $scenario = $this->scenario($context);
        $this->failConfirmed($scenario);
        $this->assertSame(Incident::STATE_OPEN, Incident::query()->sole()->state);

        $this->passRun($scenario, CheckRun::TRIGGER_MANUAL);
        $this->passRun($scenario, CheckRun::TRIGGER_SCHEDULED);
        $this->assertSame(Incident::STATE_OPEN, Incident::query()->sole()->state);

        Carbon::setTestNow(now()->addMinutes(5));
        $this->passRun($scenario, CheckRun::TRIGGER_SCHEDULED);

        $incident = Incident::query()->sole();
        $this->assertSame(Incident::STATE_RESOLVED, $incident->state);
        $this->assertSame('auto_resolved_scheduled_checks_passed', $incident->resolution_reason);
    }

    public function test_coverage_problems_are_info_incidents_resolved_by_the_next_pass(): void
    {
        $context = $this->checkContext();
        $scenario = $this->scenario($context);

        $this->runWith($scenario, CheckRun::TRIGGER_SCHEDULED, $this->resultBody(1, 'blocked', 'waf_challenge', failedStep: 'product'));
        $incident = Incident::query()->sole();
        $this->assertSame(CheckOutcomeEvaluator::TITLE_MONITORING_BLOCKED, $incident->title_code);
        $this->assertSame(Incident::SEVERITY_INFO, $incident->severity);

        $this->runWith($scenario, CheckRun::TRIGGER_SCHEDULED, $this->resultBody(1, 'failed', 'product_unavailable', failedStep: 'product'));
        $this->assertSame(2, Incident::query()->count());

        $this->passRun($scenario, CheckRun::TRIGGER_MANUAL);
        $this->assertSame(0, Incident::query()->whereIn('state', Incident::ACTIVE_STATES)->count());
    }

    public function test_results_must_be_sanitized_metadata(): void
    {
        $lease = $this->leasedAttempt();
        $body = $this->resultBody(1, 'failed', 'site_failure');

        $withHeaders = $body;
        $withHeaders['diagnostics']['relevant_errors'] = [['type' => 'response', 'status' => 500, 'headers' => 'cookie: x']];
        $withQuery = $body;
        $withQuery['steps'][0]['network_summary'] = [['method' => 'GET', 'origin' => 'https://shop.example.test', 'path' => '/checkout/?email=jane@example.test', 'status' => 500]];
        $unknownCode = array_merge($body, ['error_code' => 'something_else']);
        $extraField = array_merge($body, ['html' => '<html>']);

        foreach ([$withHeaders, $withQuery, $unknownCode, $extraField] as $invalid) {
            $this->submit($lease, $invalid)->assertStatus(422);
        }

        $this->assertSame(CheckRun::STATUS_RUNNING, CheckRun::query()->sole()->status);
    }

    public function test_manual_runs_are_rate_limited_and_require_an_idempotency_key(): void
    {
        $context = $this->checkContext();
        $scenario = $this->scenario($context);
        $url = "/api/v1/stores/{$context['store']->id}/checks";
        $body = ['scenario_id' => $scenario->id];

        $this->asOwner($context)->postJson($url, $body)->assertStatus(400);
        $this->asOwner($context)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['scenario_id' => (string) Str::uuid()])->assertNotFound();
        $created = $this->asOwner($context)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $body)
            ->assertStatus(202)->assertJsonPath('status', CheckRun::STATUS_QUEUED)->assertJsonPath('trigger', CheckRun::TRIGGER_MANUAL);
        $this->assertSame(CheckRun::query()->sole()->id, $created->json('run_id'));
        $this->asOwner($context)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $body)
            ->assertStatus(429)->assertJsonPath('code', CheckScheduler::ERROR_RATE_LIMITED);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->asOwner($context)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $body)
            ->assertStatus(409)->assertJsonPath('code', CheckScheduler::ERROR_RUN_ACTIVE);

        $run = CheckRun::query()->sole();
        $this->asOwner($context)->getJson("/api/v1/check-runs/{$run->id}")->assertOk()->assertJsonPath('status', 'queued');
        $this->asOwner($context)->getJson("/api/v1/stores/{$context['store']->id}/check-runs")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_flow_failure_notification_names_the_step_and_says_no_order_was_created(): void
    {
        $sender = new FakeNotificationSender;
        $this->app->instance(NotificationSenderRegistry::class, new NotificationSenderRegistry([$sender]));
        $context = $this->checkContext();
        $this->verifiedEmailChannel($context['tenant'], 'alerts@example.test', ['locale' => 'ru']);
        $this->failConfirmed($this->scenario($context));

        app(DomainOutboxDispatcher::class)->dispatchDue();
        app(NotificationDeliveryWorker::class)->deliverDue();

        $message = $sender->sent[0]['message'];
        $this->assertStringContainsString('проверка оформления заказа не проходит', $message->subject);
        $this->assertStringContainsString('не пройден шаг «форма оплаты»', $message->body);
        $this->assertStringContainsString('не создаёт заказов', $message->body);
        $this->assertSame(NotificationDelivery::STATUS_SENT, NotificationDelivery::query()->sole()->status);
    }

    public function test_commands_are_registered_and_scheduled(): void
    {
        $this->artisan('browser:schedule')->assertSuccessful();
        $this->artisan('browser:worker-create', ['name' => 'worker-2'])->assertSuccessful();
        $this->assertSame(2, BrowserWorker::query()->count());

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'browser:schedule'));
        $this->assertSame('* * * * *', $event?->expression);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkContext(bool $verified = true): array
    {
        $context = $this->context();
        $context['store']->forceFill([
            'base_url' => 'https://shop.example.test',
            'status' => 'active',
            'browser_enabled' => $verified,
            'verified_at' => $verified ? now() : null,
        ])->save();

        return $context;
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
            'interval_seconds' => 900,
            'next_due_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function leasedAttempt(): array
    {
        $this->scenario($this->checkContext());
        app(CheckScheduler::class)->scheduleDue();

        return $this->lease()->assertOk()->json();
    }

    private function failConfirmed(CheckScenario $scenario): void
    {
        $this->runWith($scenario, CheckRun::TRIGGER_SCHEDULED, $this->resultBody(1, 'failed', 'site_failure', failedStep: 'payment_form'));
        Carbon::setTestNow(now()->addSeconds(61));
        $this->submit($this->lease()->assertOk()->json(), $this->resultBody(2, 'failed', 'site_failure', failedStep: 'payment_form'))->assertOk();
    }

    private function passRun(CheckScenario $scenario, string $trigger): void
    {
        $this->runWith($scenario, $trigger, $this->resultBody(1, 'passed'));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function runWith(CheckScenario $scenario, string $trigger, array $result): void
    {
        CheckRun::query()->create([
            'tenant_id' => $scenario->tenant_id,
            'store_id' => $scenario->store_id,
            'scenario_id' => $scenario->id,
            'scenario_version' => $scenario->version,
            'trigger' => $trigger,
            'dedupe_key' => $trigger.':'.Str::uuid(),
            'status' => CheckRun::STATUS_QUEUED,
            'config_snapshot' => app(ScenarioDefinition::class)->snapshot($scenario, Store::query()->findOrFail($scenario->store_id)),
            'scheduled_at' => now(),
            'next_attempt_at' => now(),
            'created_at' => now(),
        ]);

        $this->submit($this->lease()->assertOk()->json(), $result)->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function resultBody(int $fencing, string $status, ?string $errorCode = null, ?string $failedStep = null): array
    {
        $steps = [];

        foreach (['product', 'add_to_cart', 'cart', 'checkout', 'shipping', 'payment_form'] as $index => $code) {
            $steps[] = [
                'index' => $index,
                'code' => $code,
                'status' => $failedStep === $code ? ($status === 'blocked' ? 'blocked' : 'failed') : 'passed',
                'started_at' => now()->toJSON(),
                'finished_at' => now()->toJSON(),
                'assertions' => [['code' => $code.'_ready', 'passed' => $failedStep !== $code]],
                'network_summary' => [['method' => 'GET', 'origin' => 'https://shop.example.test', 'path' => '/'.$code.'/', 'status' => 200, 'duration_ms' => 120, 'party' => 'first']],
                'error_code' => $failedStep === $code ? $errorCode : null,
            ];
        }

        return [
            'fencing_token' => $fencing,
            'status' => $status,
            'finished_at' => now()->toJSON(),
            'error_code' => $errorCode,
            'steps' => $steps,
            'diagnostics' => [
                'redaction_version' => 'r1',
                'relevant_errors' => $errorCode === null ? [] : [['type' => 'response', 'party' => 'first', 'origin' => 'https://shop.example.test', 'path' => '/checkout/', 'status' => 500, 'step_code' => $failedStep ?? 'checkout']],
            ],
        ];
    }

    private function lease(): TestResponse
    {
        return $this->worker()->postJson('/internal/v1/browser/leases', ['browser_version' => 'chromium-140', 'location' => 'eu-central']);
    }

    /**
     * @param  array<string, mixed>  $lease
     * @param  array<string, mixed>  $result
     */
    private function submit(array $lease, array $result): TestResponse
    {
        return $this->worker()
            ->withHeader('X-BW-Lease-Token', $lease['lease_token'])
            ->postJson('/internal/v1/browser/attempts/'.$lease['attempt_id'].'/result', $result);
    }

    private function worker(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->workerToken);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function asOwner(array $context): static
    {
        return $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
    }
}
