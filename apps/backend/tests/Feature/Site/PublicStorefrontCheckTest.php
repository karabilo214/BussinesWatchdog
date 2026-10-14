<?php

namespace Tests\Feature\Site;

use App\Models\BrowserWorker;
use App\Models\PublicStorefrontCheck;
use App\Models\PublicStorefrontCheckArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStorefrontCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $workerToken = 'public-check-worker-token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20T10:00:00Z');
        config()->set('watchdog.public_check.verify_dns', false);
        config()->set('watchdog.artifacts.disk', 'artifacts');
        Storage::fake('artifacts');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_public_check_form_requires_captcha_and_records_cooldowns(): void
    {
        $form = $this->get('/ru/check-store')
            ->assertOk()
            ->assertSee('Публичная диагностика')
            ->assertSee('name="captcha_answer"', false)
            ->assertSee('/ru/check-store/captcha', false)
            ->assertDontSee('name="captcha_token"', false)
            ->assertSee('Запускаем проверку...', false)
            ->assertSee('noindex', false);
        $answer = $this->captchaAnswer('ru');

        $this->post('/ru/check-store', ['url' => 'https://shop.example.com'])
            ->assertSessionHasErrors('captcha_answer');

        $wrongAnswer = $this->captchaAnswer('ru') + 1;
        $this->from('/ru/check-store')->post('/ru/check-store', [
            'url' => 'https://shop.example.com',
            'captcha_answer' => $wrongAnswer,
        ])->assertRedirect('/ru/check-store')->assertSessionHasErrors('captcha_answer');
        $answer = $this->captchaAnswer('ru');

        $first = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/ru/check-store', [
            'url' => 'shop.example.com/product/demo',
            'captcha_answer' => $answer,
        ])->assertRedirect();
        $this->assertMatchesRegularExpression('#/ru/check-store/[0-9a-f]{64}$#', $first->headers->get('Location'));
        $token = basename((string) $first->headers->get('Location'));
        $this->assertDatabaseHas('public_storefront_checks', [
            'submitted_url' => 'shop.example.com/product/demo',
            'normalized_origin' => 'https://shop.example.com',
            'normalized_host' => 'shop.example.com',
            'status' => PublicStorefrontCheck::STATUS_QUEUED,
        ]);
        $this->get('/ru/check-store/'.$token)
            ->assertOk()
            ->assertSee('data-status-url="/ru/check-store/'.$token.'/status"', false)
            ->assertSee('Результат появится здесь автоматически');
        $this->getJson('/ru/check-store/'.$token.'/status')
            ->assertOk()
            ->assertJsonPath('status', PublicStorefrontCheck::STATUS_QUEUED)
            ->assertJsonPath('terminal', false)
            ->assertJsonPath('poll_after_ms', 2500);

        Carbon::setTestNow('2026-10-20T10:03:00Z');
        $staleStatus = $this->getJson('/ru/check-store/'.$token.'/status')
            ->assertOk()
            ->assertJsonPath('status', PublicStorefrontCheck::STATUS_INCONCLUSIVE)
            ->assertJsonPath('terminal', true);
        $this->assertStringContainsString('Свободный браузерный воркер', (string) $staleStatus->json('html'));
        $this->assertStringNotContainsString('No browser worker picked up', (string) $staleStatus->json('html'));

        $hostCooldownAnswer = $this->captchaAnswer('ru');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->from('/ru/check-store')->post('/ru/check-store', [
            'url' => 'https://shop.example.com/another',
            'captcha_answer' => $hostCooldownAnswer,
        ])->assertRedirect('/ru/check-store')->assertSessionHasErrors('url');

        $ipCooldownAnswer = $this->captchaAnswer('ru');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->from('/ru/check-store')->post('/ru/check-store', [
            'url' => 'https://other.example.com',
            'captcha_answer' => $ipCooldownAnswer,
        ])->assertRedirect('/ru/check-store')->assertSessionHasErrors('url');

        $httpAnswer = $this->captchaAnswer('ru');
        $this->from('/ru/check-store')->post('/ru/check-store', [
            'url' => 'http://shop.example.com',
            'captcha_answer' => $httpAnswer,
        ])->assertRedirect('/ru/check-store')->assertSessionHasErrors('url');

        $privateAnswer = $this->captchaAnswer('ru');
        $this->from('/ru/check-store')->post('/ru/check-store', [
            'url' => 'https://127.0.0.1',
            'captcha_answer' => $privateAnswer,
        ])->assertRedirect('/ru/check-store')->assertSessionHasErrors('url');
    }

    public function test_worker_runs_public_check_saves_short_lived_artifact_and_purges_it(): void
    {
        $answer = $this->captchaAnswer('en');
        $redirect = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->post('/en/check-store', [
            'url' => 'https://store.example.com',
            'captcha_answer' => $answer,
        ])->assertRedirect();
        $token = basename((string) $redirect->headers->get('Location'));

        BrowserWorker::query()->create([
            'name' => 'worker-public',
            'token_hash' => hash('sha256', $this->workerToken),
            'status' => BrowserWorker::STATUS_ACTIVE,
            'created_at' => Carbon::now(),
        ]);

        $lease = $this->postJson('/internal/v1/browser/leases', [
            'browser_version' => 'chromium-test',
            'location' => 'test',
        ], $this->workerHeaders())->assertOk();
        $lease->assertJsonPath('type', 'public_storefront')
            ->assertJsonPath('scenario.adapter_version', 'public-storefront@1')
            ->assertJsonPath('scenario.store_origin', 'https://store.example.com')
            ->assertJsonPath('scenario.allowed_store_origins.0', 'https://store.example.com')
            ->assertJsonPath('scenario.allowed_store_origins.1', 'https://www.store.example.com')
            ->assertJsonPath('network_policy.allowed_origins.1', 'https://www.store.example.com');
        $attemptId = $lease->json('attempt_id');
        $leaseToken = $lease->json('lease_token');
        $fencing = $lease->json('fencing_token');

        PublicStorefrontCheck::query()->create([
            'result_token_hash' => hash('sha256', 'second-public-check-token'),
            'locale' => 'en',
            'submitted_url' => 'https://second.example.com',
            'normalized_origin' => 'https://second.example.com',
            'normalized_host' => 'second.example.com',
            'ip_hash' => hash('sha256', '203.0.113.21'),
            'status' => PublicStorefrontCheck::STATUS_QUEUED,
            'queued_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);
        $this->postJson('/internal/v1/browser/leases', [
            'browser_version' => 'chromium-test',
            'location' => 'test',
        ], $this->workerHeaders())->assertNoContent();

        $jpeg = $this->jpeg();
        $artifact = $this->call('POST', '/internal/v1/browser/attempts/'.$attemptId.'/artifacts', [], [], [], [
            'CONTENT_TYPE' => 'image/jpeg',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->workerToken,
            'HTTP_X_BW_LEASE_TOKEN' => $leaseToken,
            'HTTP_X_BW_FENCING_TOKEN' => (string) $fencing,
            'HTTP_X_BW_SHA256' => hash('sha256', $jpeg),
            'HTTP_X_BW_REDACTION_VERSION' => 'r1',
        ], $jpeg)->assertCreated()->json('artifact_id');

        $now = Carbon::now();
        $this->postJson('/internal/v1/browser/attempts/'.$attemptId.'/result', [
            'fencing_token' => $fencing,
            'status' => 'passed',
            'finished_at' => $now->toJSON(),
            'error_code' => null,
            'artifact_ids' => [$artifact],
            'steps' => [[
                'index' => 0,
                'code' => 'public_home',
                'status' => 'passed',
                'started_at' => $now->toJSON(),
                'finished_at' => $now->toJSON(),
                'assertions' => [['code' => 'public_page_loaded', 'passed' => true, 'detail_code' => 'loaded']],
                'network_summary' => [],
                'error_code' => null,
            ], [
                'index' => 1,
                'code' => 'public_checkout',
                'status' => 'passed',
                'started_at' => $now->toJSON(),
                'finished_at' => $now->toJSON(),
                'assertions' => [['code' => 'checkout_link_opened', 'passed' => true, 'detail_code' => 'checkout_reached']],
                'network_summary' => [],
                'error_code' => null,
            ]],
            'diagnostics' => [
                'redaction_version' => 'r1',
                'relevant_errors' => [['type' => 'console_error', 'message_code' => 'Uncaught TypeError', 'step_code' => 'public_checkout']],
                'detected_platform' => 'woocommerce_hint',
                'public_audit' => [
                    'platform' => 'woocommerce_hint',
                    'supported' => true,
                    'categories_found' => 12,
                    'products_found' => 48,
                    'products_tested' => 1,
                    'cart_result' => 'product_present',
                    'checkout_result' => 'customer_details_reached',
                    'sampled_products' => [['url' => 'https://store.example.com/product/demo', 'add_to_cart' => 'works', 'cart_verified' => true]],
                ],
            ],
        ], $this->workerHeaders(['X-BW-Lease-Token' => $leaseToken]))->assertOk();

        $this->assertDatabaseHas('public_storefront_checks', [
            'id' => $attemptId,
            'status' => PublicStorefrontCheck::STATUS_PASSED,
            'verdict' => PublicStorefrontCheck::VERDICT_CHECKOUT_REACHED,
        ]);
        $this->assertDatabaseHas('public_storefront_check_artifacts', ['id' => $artifact, 'state' => PublicStorefrontCheckArtifact::STATE_READY]);

        $this->get('/en/check-store/'.$token)
            ->assertOk()
            ->assertSee('Public path reached checkout')
            ->assertSee('Looks like: WooCommerce')
            ->assertSee('Categories: 12. Products: 48.')
            ->assertSee('Add-to-cart works')
            ->assertSee('Reached customer details form')
            ->assertSee('Uncaught TypeError')
            ->assertSee('/en/check-store/'.$token.'/artifacts/'.$artifact, false);
        $this->get('/en/check-store/'.$token.'/artifacts/'.$artifact)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        Carbon::setTestNow('2026-10-20T10:06:00Z');
        $this->artisan('site:public-checks-purge')->assertExitCode(0);
        $this->assertDatabaseMissing('public_storefront_checks', ['id' => $attemptId]);
        $this->get('/en/check-store/'.$token)->assertNotFound();
    }

    public function test_localhost_can_submit_repeated_public_checks_when_dev_bypass_is_enabled(): void
    {
        config()->set('watchdog.public_check.bypass_limits_on_localhost', true);

        $firstAnswer = $this->captchaAnswer('ru');
        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1'])->post('/ru/check-store', [
            'url' => 'https://shop.example.com',
            'captcha_answer' => $firstAnswer,
        ])->assertRedirect();

        $secondAnswer = $this->captchaAnswer('ru');
        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1'])->post('/ru/check-store', [
            'url' => 'https://shop.example.com/again',
            'captcha_answer' => $secondAnswer,
        ])->assertRedirect();

        $thirdAnswer = $this->captchaAnswer('ru');
        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1'])->post('/ru/check-store', [
            'url' => 'https://other.example.com',
            'captcha_answer' => $thirdAnswer,
        ])->assertRedirect();

        $this->assertDatabaseCount('public_storefront_checks', 3);
    }

    public function test_local_dev_can_prefer_public_leases_over_regular_browser_backlog(): void
    {
        config()->set('watchdog.public_check.prefer_public_leases', true);

        $answer = $this->captchaAnswer('en');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])->post('/en/check-store', [
            'url' => 'https://store.example.com',
            'captcha_answer' => $answer,
        ])->assertRedirect();

        BrowserWorker::query()->create([
            'name' => 'worker-public-priority',
            'token_hash' => hash('sha256', $this->workerToken),
            'status' => BrowserWorker::STATUS_ACTIVE,
            'created_at' => Carbon::now(),
        ]);

        $this->postJson('/internal/v1/browser/leases', [
            'browser_version' => 'chromium-test',
            'location' => 'test',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('type', 'public_storefront');
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function workerHeaders(array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.$this->workerToken] + $extra;
    }

    private function jpeg(): string
    {
        return "\xFF\xD8\xFF\xE0".str_repeat('a', 200)."\xFF\xD9";
    }

    private function captchaAnswer(string $locale): int
    {
        $response = $this->get('/'.$locale.'/check-store/captcha')
            ->assertOk();
        $this->assertContains($response->headers->get('Content-Type'), ['image/png', 'image/svg+xml']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));

        $challenge = session('public_storefront_math_captcha');
        $this->assertIsArray($challenge);
        $this->assertMatchesRegularExpression('/^(\\d+) \\+ (\\d+)$/', $challenge['question'] ?? '');
        preg_match('/^(\\d+) \\+ (\\d+)$/', $challenge['question'], $matches);

        return (int) $matches[1] + (int) $matches[2];
    }
}
