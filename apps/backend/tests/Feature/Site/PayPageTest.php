<?php

namespace Tests\Feature\Site;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Site\PageAdmin;
use App\Support\Site\SiteRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_payment_link_page_loads_paddle_with_the_client_token_in_each_language(): void
    {
        config(['watchdog.billing.paddle.client_token' => 'live_token123', 'watchdog.billing.paddle.environment' => 'production']);

        $response = $this->get('/en/pay?_ptxn=txn_01abc');
        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('https://cdn.paddle.com/paddle/v2/paddle.js', false)
            ->assertSee('"live_token123"', false)
            ->assertSee('Paddle.com, our reseller and the Merchant of Record', false)
            ->assertSee('href="/app/settings/billing"', false);
        $this->assertStringContainsString('var sandbox = false;', $response->getContent());

        config(['watchdog.billing.paddle.environment' => 'sandbox']);
        $this->assertStringContainsString('var sandbox = true;', $this->get('/de/pay')->assertOk()->assertSee('Zahlung')->getContent());
        $this->get('/ru/pay')->assertOk()->assertSee('Оплата');

        config(['watchdog.billing.paddle.client_token' => '']);
        $this->assertStringContainsString('var token = null;', $this->get('/en/pay')->getContent());
    }

    public function test_a_site_page_cannot_take_the_pay_address(): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password_hash' => 'x', 'locale' => 'en']);
        $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);

        $this->expectException(SiteRejected::class);
        app(PageAdmin::class)->create($staff->id, ['slug' => 'pay']);
    }
}
