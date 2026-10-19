<?php

namespace Tests\Feature\Site;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Site\PageSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        $this->assertSame(['hero', 'features', 'steps', 'pricing', 'faq', 'cta', 'text', 'text', 'testimonials', 'logos', 'comparison', 'stats', 'steps', 'team', 'gallery', 'contact', 'faq', 'cta'], array_column($blocks, 'category'));
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

    public function test_owner_creates_edits_and_removes_block_templates_with_audit(): void
    {
        $this->signIn();
        $created = $this->postJson('/platform-api/v1/page-blocks', [
            'name' => '  Отзывы клиентов ',
            'category' => 'other',
            'sort' => 90,
            'bodies' => ['ru' => '<section class="card" onclick="x()"><p>Отзыв</p><script>alert(1)</script></section>', 'en' => '', 'de' => null],
        ])->assertCreated()->assertJsonPath('name', 'Отзывы клиентов')->json();
        $this->assertSame(['ru' => '<section class="card"><p>Отзыв</p></section>'], $created['bodies']);

        $this->postJson('/platform-api/v1/page-blocks', ['name' => 'Пусто', 'category' => 'other', 'bodies' => ['ru' => '<script>x()</script>']])->assertStatus(422)->assertJsonPath('code', 'body_required');
        $this->postJson('/platform-api/v1/page-blocks', ['name' => 'X', 'category' => 'banner', 'bodies' => ['ru' => '<p>x</p>']])->assertStatus(422);
        $this->postJson('/platform-api/v1/page-blocks', ['name' => 'X', 'category' => 'other', 'bodies' => ['fr' => '<p>x</p>']])->assertStatus(422);

        $id = $created['id'];
        $this->patchJson("/platform-api/v1/page-blocks/{$id}", ['name' => 'Отзывы', 'bodies' => ['de' => '<p>Bewertung</p>']])->assertOk()
            ->assertJsonPath('bodies.ru', '<section class="card"><p>Отзыв</p></section>')->assertJsonPath('bodies.de', '<p>Bewertung</p>')->assertJsonPath('name', 'Отзывы');
        $this->patchJson("/platform-api/v1/page-blocks/{$id}", ['bodies' => ['ru' => null]])->assertOk()->assertJsonMissingPath('bodies.ru');
        $this->patchJson("/platform-api/v1/page-blocks/{$id}", ['bodies' => ['de' => '']])->assertStatus(422)->assertJsonPath('code', 'body_required');
        $this->patchJson("/platform-api/v1/page-blocks/{$id}", ['name' => ' '])->assertStatus(422);
        $this->getJson("/platform-api/v1/page-blocks/{$id}")->assertOk()->assertJsonPath('bodies.de', '<p>Bewertung</p>');
        $this->assertCount(19, $this->getJson('/platform-api/v1/page-blocks')->json('data'));

        $this->postJson("/platform-api/v1/page-blocks/{$id}/archive", ['reason' => ''])->assertStatus(422);
        $this->postJson("/platform-api/v1/page-blocks/{$id}/archive", ['reason' => 'Больше не нужен'])->assertNoContent();
        $this->getJson("/platform-api/v1/page-blocks/{$id}")->assertNotFound();
        $this->patchJson("/platform-api/v1/page-blocks/{$id}", ['name' => 'Снова'])->assertNotFound();
        $this->assertCount(18, $this->getJson('/platform-api/v1/page-blocks')->json('data'));

        $actions = DB::table('platform_audit_log')->where('target_type', 'page_block')->where('target_id', $id)->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['site.block.created', 'site.block.updated', 'site.block.updated', 'site.block.archived'], $actions);
        $this->assertSame('Больше не нужен', DB::table('platform_audit_log')->where('action', 'site.block.archived')->value('reason'));
    }

    public function test_block_templates_need_the_content_capability(): void
    {
        $this->getJson('/platform-api/v1/page-blocks')->assertUnauthorized();
        $this->signIn(PlatformStaff::ROLE_ADMIN);
        $this->getJson('/platform-api/v1/page-blocks')->assertForbidden();
        $this->postJson('/platform-api/v1/page-blocks', ['name' => 'X', 'category' => 'other', 'bodies' => ['ru' => '<p>x</p>']])->assertForbidden();
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
