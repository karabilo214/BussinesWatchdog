<?php

namespace Tests\Feature\Browser;

use App\Models\AuditLog;
use App\Models\BrowserWorker;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\Incident;
use App\Models\Store;
use App\Support\Browser\CheckOutcomeEvaluator;
use App\Support\Browser\CheckScheduler;
use App\Support\Browser\ScenarioDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class ShopwareBrowserChecksTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private const PASSWORD = 'Test-customer-pass-42';

    private string $workerToken;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
        $this->workerToken = 'bwwk_'.bin2hex(random_bytes(32));
        BrowserWorker::query()->create(['name' => 'worker-1', 'token_hash' => hash('sha256', $this->workerToken), 'status' => BrowserWorker::STATUS_ACTIVE, 'created_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_shopware_scenarios_choose_how_to_reach_payment_methods(): void
    {
        $context = $this->checkContext('shopware');
        $url = "/api/v1/stores/{$context['store']->id}/scenarios";

        $created = $this->asOwner($context)->postJson($url, ['product_url' => 'https://shop.example.test/detail/1'])
            ->assertCreated()
            ->assertJsonPath('adapter_version', ScenarioDefinition::SHOPWARE_ADAPTER_VERSION)
            ->assertJsonPath('platform', 'shopware')
            ->assertJsonPath('checkout_access', CheckScenario::ACCESS_STOP_BEFORE_SIGN_IN)
            ->assertJsonPath('sign_in_configured', false)
            ->assertJsonPath('supported_steps', ['product', 'add_to_cart', 'cart', 'checkout']);
        $this->assertSame(['payment_methods_stop_before_sign_in', 'redirect_gateways'], $created->json('untested_components'));
        $scenarioUrl = '/api/v1/scenarios/'.$created->json('id');

        $this->asOwner($context)->withHeader('If-Match', '"1"')->patchJson($scenarioUrl, ['checkout_access' => 'test_customer'])
            ->assertStatus(422)->assertJsonPath('code', 'sign_in_required');
        $this->asOwner($context)->withHeader('If-Match', '"1"')->patchJson($scenarioUrl, ['checkout_access' => 'guest', 'sign_in' => ['email' => 'check@shop.example.test', 'password' => self::PASSWORD]])
            ->assertStatus(422)->assertJsonPath('code', 'sign_in_not_applicable');
        $this->asOwner($context)->withHeader('If-Match', '"1"')->patchJson($scenarioUrl, ['checkout_access' => 'everyone'])->assertStatus(422);

        $saved = $this->asOwner($context)->withHeader('If-Match', '"1"')
            ->patchJson($scenarioUrl, ['checkout_access' => 'test_customer', 'sign_in' => ['email' => 'check@shop.example.test', 'password' => self::PASSWORD]])
            ->assertOk()
            ->assertJsonPath('checkout_access', 'test_customer')
            ->assertJsonPath('sign_in_configured', true)
            ->assertJsonPath('supported_steps', ['product', 'add_to_cart', 'cart', 'checkout', 'sign_in', 'shipping', 'payment_form']);
        $this->assertStringNotContainsString(self::PASSWORD, $saved->getContent());
        $this->assertStringNotContainsString('check@shop.example.test', $saved->getContent());
        $this->assertSame(['provider_forms_not_loaded', 'redirect_gateways'], $saved->json('untested_components'));
        $stored = CheckScenario::query()->sole();
        $this->assertNotNull($stored->sign_in_ciphertext);
        $this->assertStringNotContainsString(self::PASSWORD, $stored->sign_in_ciphertext);
        $this->assertArrayNotHasKey('sign_in_ciphertext', $stored->toArray());
        $audit = AuditLog::query()->where('action', AuditLog::ACTION_CHECK_SCENARIO_SAVED)->latest('created_at')->get()->last();
        $this->assertEquals(['version' => 2, 'enabled' => false, 'checkout_access' => 'test_customer', 'sign_in_changed' => true], $audit->changes);
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(AuditLog::query()->pluck('changes')));

        $this->asOwner($context)->withHeader('If-Match', '"2"')->patchJson($scenarioUrl, ['interval_seconds' => 3600])
            ->assertOk()->assertJsonPath('sign_in_configured', true);

        $this->asOwner($context)->withHeader('If-Match', '"3"')->patchJson($scenarioUrl, ['checkout_access' => 'guest'])
            ->assertOk()
            ->assertJsonPath('checkout_access', 'guest')
            ->assertJsonPath('sign_in_configured', false)
            ->assertJsonPath('supported_steps', ['product', 'add_to_cart', 'cart', 'checkout', 'guest_details', 'shipping', 'payment_form']);
        $this->assertNull(CheckScenario::query()->sole()->sign_in_ciphertext);

        $this->asOwner($context)->withHeader('If-Match', '"4"')->patchJson($scenarioUrl, ['checkout_access' => 'test_customer'])
            ->assertStatus(422)->assertJsonPath('code', 'sign_in_required');

        $this->asOwner($context)->withHeader('If-Match', '"4"')->patchJson($scenarioUrl, ['load_payment_providers' => true])
            ->assertOk()->assertJsonPath('untested_components', ['provider_form_fields_shopware', 'redirect_gateways']);
    }

    public function test_only_platforms_with_an_adapter_can_have_a_check_and_only_shopware_takes_a_way_to_payment(): void
    {
        $woo = $this->checkContext('woocommerce');
        $this->asOwner($woo)->postJson("/api/v1/stores/{$woo['store']->id}/scenarios", ['product_url' => 'https://shop.example.test/product/x', 'checkout_access' => 'guest'])
            ->assertStatus(422)->assertJsonPath('code', 'checkout_access_not_applicable');
        $this->asOwner($woo)->postJson("/api/v1/stores/{$woo['store']->id}/scenarios", ['product_url' => 'https://shop.example.test/product/x'])
            ->assertCreated()
            ->assertJsonPath('adapter_version', ScenarioDefinition::ADAPTER_VERSION)
            ->assertJsonPath('checkout_access', null)
            ->assertJsonPath('supported_steps', ['product', 'add_to_cart', 'cart', 'checkout', 'shipping', 'payment_form']);

        $other = $this->checkContext('magento');
        $this->asOwner($other)->postJson("/api/v1/stores/{$other['store']->id}/scenarios", ['product_url' => 'https://shop.example.test/product/x'])
            ->assertStatus(422)->assertJsonPath('code', 'platform_not_supported');
    }

    public function test_the_test_customer_account_reaches_only_the_worker_lease(): void
    {
        $context = $this->checkContext('shopware');
        $created = $this->asOwner($context)->postJson("/api/v1/stores/{$context['store']->id}/scenarios", [
            'product_url' => 'https://shop.example.test/detail/1',
            'enabled' => true,
            'checkout_access' => 'test_customer',
            'sign_in' => ['email' => 'check@shop.example.test', 'password' => self::PASSWORD],
        ])->assertCreated();

        app(CheckScheduler::class)->scheduleDue();
        $run = CheckRun::query()->sole();
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($run->config_snapshot));
        $this->assertArrayNotHasKey('sign_in', $run->config_snapshot);
        $this->assertSame('test_customer', $run->config_snapshot['checkout_access']);

        $lease = $this->lease()->assertOk();
        $this->assertSame(['email' => 'check@shop.example.test', 'password' => self::PASSWORD], $lease->json('scenario.sign_in'));
        $this->assertSame('shopware', $lease->json('scenario.platform'));
        $this->assertSame(ScenarioDefinition::SHOPWARE_ADAPTER_VERSION, $lease->json('scenario.adapter_version'));
        $this->assertSame('https://shop.example.test/checkout/cart', $lease->json('scenario.cart_url'));
        $this->assertSame('https://shop.example.test/checkout/confirm', $lease->json('scenario.checkout_url'));
        $this->assertSame(['product', 'add_to_cart', 'cart', 'checkout', 'sign_in', 'shipping', 'payment_form'], array_column($lease->json('scenario.steps'), 'code'));
        $this->assertContains('place_order', $lease->json('network_policy.blocked_operations'));
        $this->assertContains('customer_account_creation', $lease->json('network_policy.blocked_operations'));

        $this->asOwner($context)->withHeader('If-Match', '"1"')->patchJson('/api/v1/scenarios/'.$created->json('id'), ['checkout_access' => 'guest'])->assertOk();
        CheckRun::query()->update(['status' => CheckRun::STATUS_CANCELLED, 'finished_at' => now()]);
        Carbon::setTestNow(now()->addHour());
        CheckScenario::query()->update(['next_due_at' => now()]);
        app(CheckScheduler::class)->scheduleDue();
        $guest = $this->lease()->assertOk();
        $this->assertNull($guest->json('scenario.sign_in'));
        $this->assertSame('guest', $guest->json('scenario.checkout_access'));
        $this->assertSame(['product', 'add_to_cart', 'cart', 'checkout', 'guest_details', 'shipping', 'payment_form'], array_column($guest->json('scenario.steps'), 'code'));
    }

    public function test_a_rejected_test_account_is_an_info_incident_not_a_broken_shop(): void
    {
        $context = $this->checkContext('shopware');
        $this->asOwner($context)->postJson("/api/v1/stores/{$context['store']->id}/scenarios", [
            'product_url' => 'https://shop.example.test/detail/1',
            'enabled' => true,
            'checkout_access' => 'test_customer',
            'sign_in' => ['email' => 'check@shop.example.test', 'password' => self::PASSWORD],
        ])->assertCreated();
        $scenario = CheckScenario::query()->sole();

        $this->runWith($scenario, $this->resultBody(1, 'blocked', 'test_account_rejected', 'sign_in'));
        $run = CheckRun::query()->sole();
        $this->assertSame([CheckRun::STATUS_BLOCKED, 'test_account_rejected'], [$run->status, $run->error_code]);
        $incident = Incident::query()->sole();
        $this->assertSame(CheckOutcomeEvaluator::TITLE_TEST_ACCOUNT_REJECTED, $incident->title_code);
        $this->assertSame(CheckOutcomeEvaluator::COMPONENT_TEST_ACCOUNT, $incident->component);
        $this->assertSame(Incident::SEVERITY_INFO, $incident->severity);

        $this->runWith($scenario, $this->resultBody(1, 'passed'));
        $this->assertSame(0, Incident::query()->whereIn('state', Incident::ACTIVE_STATES)->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function checkContext(string $platform): array
    {
        $context = $this->context();
        $context['store']->forceFill([
            'platform' => $platform,
            'base_url' => 'https://shop.example.test',
            'status' => 'active',
            'browser_enabled' => true,
            'verified_at' => now(),
        ])->save();

        return $context;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function runWith(CheckScenario $scenario, array $result): void
    {
        CheckRun::query()->create([
            'tenant_id' => $scenario->tenant_id,
            'store_id' => $scenario->store_id,
            'scenario_id' => $scenario->id,
            'scenario_version' => $scenario->version,
            'trigger' => CheckRun::TRIGGER_MANUAL,
            'dedupe_key' => 'manual:'.Str::uuid(),
            'status' => CheckRun::STATUS_QUEUED,
            'config_snapshot' => app(ScenarioDefinition::class)->snapshot($scenario, Store::query()->findOrFail($scenario->store_id)),
            'scheduled_at' => now(),
            'next_attempt_at' => now(),
            'created_at' => now(),
        ]);

        $lease = $this->lease()->assertOk()->json();
        $this->worker()->withHeader('X-BW-Lease-Token', $lease['lease_token'])
            ->postJson('/internal/v1/browser/attempts/'.$lease['attempt_id'].'/result', $result)
            ->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function resultBody(int $fencing, string $status, ?string $errorCode = null, ?string $failedStep = null): array
    {
        $steps = [];

        foreach (['product', 'add_to_cart', 'cart', 'checkout', 'sign_in', 'shipping', 'payment_form'] as $index => $code) {
            $steps[] = [
                'index' => $index,
                'code' => $code,
                'status' => $failedStep === $code ? $status : ($failedStep !== null && $index > 4 ? 'skipped' : 'passed'),
                'started_at' => now()->toJSON(),
                'finished_at' => now()->toJSON(),
                'assertions' => [['code' => $code.'_check', 'passed' => $failedStep !== $code]],
                'network_summary' => [],
                'error_code' => $failedStep === $code ? $errorCode : null,
            ];
        }

        return [
            'fencing_token' => $fencing,
            'status' => $status,
            'finished_at' => now()->toJSON(),
            'error_code' => $errorCode,
            'steps' => $steps,
            'diagnostics' => ['redaction_version' => 'r1', 'relevant_errors' => []],
        ];
    }

    private function lease(): TestResponse
    {
        return $this->worker()->postJson('/internal/v1/browser/leases', ['browser_version' => 'chromium-140', 'location' => 'eu-central', 'browser_versions' => ['chromium' => 'chromium-140', 'firefox' => 'firefox-141', 'webkit' => 'webkit-26']]);
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
