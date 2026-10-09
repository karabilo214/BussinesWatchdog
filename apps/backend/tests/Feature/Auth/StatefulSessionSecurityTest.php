<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StatefulSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_cookie_endpoint_issues_an_xsrf_token(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_session_endpoints_reject_requests_not_coming_from_the_spa_origin(): void
    {
        $this->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'x'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'stateful_session_required');

        $this->withoutHeader('Origin')
            ->getJson('/api/v1/stores')
            ->assertUnauthorized();
    }

    public function test_connector_endpoints_do_not_need_a_browser_session(): void
    {
        $this->withoutHeader('Origin')
            ->postJson('/api/v1/ingest/heartbeat', [])
            ->assertUnauthorized();
    }

    public function test_login_is_throttled_per_email_and_ip(): void
    {
        $this->user('owner@example.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong-password'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong-password'])
            ->assertStatus(429);

        $this->postJson('/api/v1/auth/login', ['email' => 'other@example.test', 'password' => 'wrong-password'])
            ->assertStatus(422);
    }

    public function test_signup_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/register', $this->registration("owner{$i}@example.test"))->assertCreated();
            $this->postJson('/api/v1/auth/logout')->assertNoContent();
            $this->app['auth']->forgetGuards();
        }

        $this->postJson('/api/v1/auth/register', $this->registration('owner9@example.test'))
            ->assertStatus(429);
    }

    public function test_api_routes_are_no_longer_excluded_from_csrf_verification(): void
    {
        $middleware = new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $session = $this->app['session']->driver();
        $session->start();

        $withoutToken = Request::create('/api/v1/stores', 'POST', server: ['HTTP_ORIGIN' => 'http://localhost']);
        $withoutToken->setLaravelSession($session);

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($withoutToken, fn () => response('ok'));
    }

    public function test_api_request_with_the_session_csrf_token_passes_verification(): void
    {
        $middleware = new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $session = $this->app['session']->driver();
        $session->start();

        $request = Request::create('/api/v1/stores', 'POST', server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $session->token(),
        ]);
        $request->setLaravelSession($session);

        $this->assertSame('ok', $middleware->handle($request, fn () => response('ok'))->getContent());
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Owner',
            'email' => $email,
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function registration(string $email): array
    {
        return [
            'name' => 'Owner',
            'email' => $email,
            'password' => 'very-secure-password',
            'password_confirmation' => 'very-secure-password',
            'organization_name' => 'Demo Store',
            'timezone' => 'Europe/Kyiv',
            'locale' => 'ru',
        ];
    }
}
