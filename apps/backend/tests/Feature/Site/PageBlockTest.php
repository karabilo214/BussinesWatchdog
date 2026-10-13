<?php

namespace Tests\Feature\Site;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Site\PageSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PageBlockTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = '';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_built_in_block_templates_are_listed_for_the_page_editor_in_every_language(): void
    {
        $this->signIn();
        $blocks = $this->getJson('/platform-api/v1/page-blocks')->assertOk()->json('data');

        $this->assertSame(['hero', 'features', 'steps', 'pricing', 'faq', 'cta', 'text', 'text'], array_column($blocks, 'category'));
        $this->assertSame('Первый экран', $blocks[0]['name']);
        $sanitizer = app(PageSanitizer::class);

        foreach ($blocks as $block) {
            $this->assertEqualsCanonicalizing(['ru', 'en', 'de'], array_keys($block['bodies']));

            foreach ($block['bodies'] as $locale => $body) {
                $this->assertNotSame('', $body);
                $this->assertSame(str_replace(' rel="noopener noreferrer"', '', $sanitizer->sanitize($body)), $body, "{$block['name']} {$locale} is not kept by the sanitizer");
                preg_match_all('/href="\/([a-z]{2})\//', $body, $links);
                $this->assertSame([], array_values(array_diff($links[1], [$locale])), "{$block['name']} {$locale} links to another language");
            }
        }

        $this->assertStringContainsString('<section class="hero">', $blocks[0]['bodies']['de']);
        $this->assertStringNotContainsString('€', implode('', $blocks[3]['bodies']));
    }

    public function test_block_templates_need_the_content_capability(): void
    {
        $this->getJson('/platform-api/v1/page-blocks')->assertUnauthorized();
        $this->signIn(PlatformStaff::ROLE_ADMIN);
        $this->getJson('/platform-api/v1/page-blocks')->assertForbidden();
    }

    private function signIn(string $role = PlatformStaff::ROLE_OWNER): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'staff@platform.test', 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])->assertOk();
    }

    private function code(): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(Carbon::now()->getTimestamp()));
    }
}
