<?php

namespace Tests\Feature\Site;

use App\Mail\NotificationMessageMail;
use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\ContactRequest;
use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Billing\FeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20T10:00:00Z');
        config()->set('app.url', 'https://watchdog.test');
        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_root_sends_the_visitor_to_their_language_and_home_works_without_published_content(): void
    {
        $this->get('/', ['Accept-Language' => 'de-DE,de;q=0.9'])->assertRedirect('/de')->assertHeader('Vary', 'Accept-Language');
        $this->get('/', ['Accept-Language' => 'fr-FR'])->assertRedirect('/en');

        $response = $this->get('/ru')->assertOk();

        $response->assertSee('<html lang="ru">', false)
            ->assertSee('Узнайте, что оплата сломалась, раньше покупателей')
            ->assertSee('<link rel="canonical" href="https://watchdog.test/ru">', false)
            ->assertSee('hreflang="de" href="https://watchdog.test/de"', false)
            ->assertSee('href="/ru/pricing"', false)
            ->assertSee('href="/ru/contact"', false);
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
        $this->get('/en')->assertSee('Find out that payments broke before your customers do');
        $this->get('/ru/nothing-here')->assertNotFound()->assertSee('Страница не найдена');
        $this->get('/ru/a/b/c')->assertNotFound();
    }

    public function test_a_page_is_drafted_sanitized_published_versioned_restored_and_unpublished(): void
    {
        $this->signIn();
        $page = $this->postJson('/platform-api/v1/pages', ['slug' => 'about', 'menu' => 'header', 'sort' => 30])->assertCreated()->json();
        $this->assertSame('about', $page['path']);

        $this->putJson("/platform-api/v1/pages/{$page['id']}/locales/ru/draft", [
            'title' => 'О компании',
            'description' => 'Кто мы',
            'body' => '<h2 class="lead" onclick="steal()">Мы</h2><script>alert(1)</script><p style="position:fixed">Текст <a href="javascript:alert(1)">ссылка</a></p><iframe src="https://evil.test"></iframe>',
        ])->assertOk()->assertJsonPath('locales.ru.draft', true)->assertJsonPath('locales.ru.published', null);
        $this->get('/ru/about')->assertNotFound();

        $preview = $this->postJson("/platform-api/v1/pages/{$page['id']}/preview", ['locale' => 'ru', 'title' => 'О компании', 'body' => '<p>Черновик</p><script>x()</script>'])->assertOk();
        $this->assertStringContainsString('noindex', $preview->json('html'));
        $this->assertStringContainsString('<p>Черновик</p>', $preview->json('html'));
        $this->assertStringNotContainsString('<script>x()', $preview->json('html'));

        $this->postJson("/platform-api/v1/pages/{$page['id']}/locales/ru/publish", [])->assertUnprocessable();
        $this->postJson("/platform-api/v1/pages/{$page['id']}/locales/ru/publish", ['reason' => 'Первая версия'])->assertOk()->assertJsonPath('content.ru.published.version', 1);

        $public = $this->get('/ru/about')->assertOk();
        $public->assertSee('<title>О компании — Business Watchdog</title>', false)
            ->assertSee('<meta name="description" content="Кто мы">', false)
            ->assertSee('<h2 class="lead">Мы</h2>', false)
            ->assertDontSee('alert(1)', false)
            ->assertDontSee('steal()', false)
            ->assertDontSee('position:fixed', false)
            ->assertDontSee('evil.test', false)
            ->assertSee('<link rel="alternate" hreflang="ru"', false)
            ->assertDontSee('<link rel="alternate" hreflang="en"', false);
        $this->get('/ru')->assertSee('href="/ru/about"', false);
        $this->get('/en/about')->assertNotFound();
        $this->get('/en')->assertDontSee('href="/en/about"', false);

        $this->putJson("/platform-api/v1/pages/{$page['id']}/locales/ru/draft", ['title' => 'О нас', 'body' => '<p>Новая</p>'])->assertOk()->assertJsonPath('content.ru.draft.version', 2);
        $this->get('/ru/about')->assertSee('Мы')->assertDontSee('Новая');
        $this->postJson("/platform-api/v1/pages/{$page['id']}/locales/ru/publish", ['reason' => 'Обновили'])->assertOk();
        $this->get('/ru/about')->assertSee('Новая');

        $card = $this->getJson("/platform-api/v1/pages/{$page['id']}")->assertOk()->json();
        $history = collect($card['history']);
        $this->assertSame(['published', 'retired'], $history->pluck('state')->all());
        $first = $history->firstWhere('version', 1);
        $this->assertSame('Staff', $first['published_by']);
        $this->assertSame('<h2 class="lead">Мы</h2><p>Текст <a rel="noopener noreferrer">ссылка</a></p>', PublicPageVersion::query()->find($first['id'])->body);

        $this->postJson("/platform-api/v1/pages/{$page['id']}/versions/{$first['id']}/restore")->assertOk()->assertJsonPath('content.ru.draft.version', 3)->assertJsonPath('content.ru.draft.title', 'О компании');
        $this->get('/ru/about')->assertSee('Новая');
        $this->deleteJson("/platform-api/v1/pages/{$page['id']}/locales/ru/draft")->assertOk()->assertJsonPath('content.ru.draft', null);

        $this->postJson("/platform-api/v1/pages/{$page['id']}/locales/ru/unpublish", ['reason' => 'Убрали'])->assertOk()->assertJsonPath('content.ru.published', null);
        $this->get('/ru/about')->assertNotFound();
        $this->assertSame(
            ['site.page.created', 'site.page.published', 'site.page.published', 'site.page.restored', 'site.page.draft_discarded', 'site.page.unpublished'],
            DB::table('platform_audit_log')->where('target_type', 'public_page')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('Первая версия', DB::table('platform_audit_log')->where('action', 'site.page.published')->orderBy('id')->value('reason'));
    }

    public function test_slugs_are_fixed_at_creation_unique_and_nested_two_levels_deep(): void
    {
        $this->signIn();
        $legal = $this->postJson('/platform-api/v1/pages', ['slug' => 'legal'])->assertCreated()->json();
        $terms = $this->postJson('/platform-api/v1/pages', ['slug' => 'terms', 'parent_id' => $legal['id'], 'menu' => 'footer'])->assertCreated()->json();
        $this->assertSame('legal/terms', $terms['path']);

        $this->postJson('/platform-api/v1/pages', ['slug' => 'deeper', 'parent_id' => $terms['id']])->assertUnprocessable()->assertJsonPath('code', 'too_deep');
        $this->postJson('/platform-api/v1/pages', ['slug' => 'terms', 'parent_id' => $legal['id']])->assertConflict()->assertJsonPath('code', 'slug_taken');
        $this->postJson('/platform-api/v1/pages', ['slug' => 'pricing'])->assertConflict()->assertJsonPath('code', 'slug_taken');
        $this->postJson('/platform-api/v1/pages', ['slug' => 'Über uns'])->assertUnprocessable()->assertJsonPath('code', 'slug_invalid');
        $this->postJson('/platform-api/v1/pages', ['slug' => '-x'])->assertUnprocessable()->assertJsonPath('code', 'slug_invalid');
        $system = PublicPage::query()->where('kind', 'pricing')->value('id');
        $this->postJson('/platform-api/v1/pages', ['slug' => 'x', 'parent_id' => $system])->assertUnprocessable()->assertJsonPath('code', 'parent_invalid');

        $this->patchJson("/platform-api/v1/pages/{$terms['id']}", ['slug' => 'agb', 'menu' => 'header', 'sort' => 5])->assertOk()->assertJsonPath('path', 'legal/terms')->assertJsonPath('menu', 'header');
        $this->putJson("/platform-api/v1/pages/{$terms['id']}/locales/de/draft", ['title' => 'AGB', 'body' => '<p>Bedingungen</p>'])->assertOk();
        $this->putJson("/platform-api/v1/pages/{$terms['id']}/locales/ru/draft", ['title' => 'Условия', 'body' => ''])->assertUnprocessable()->assertJsonPath('code', 'body_required');
        $this->postJson("/platform-api/v1/pages/{$terms['id']}/locales/de/publish", ['reason' => 'AGB online'])->assertOk();
        $this->get('/de/legal/terms')->assertOk()->assertSee('Bedingungen')->assertSee('<link rel="canonical" href="https://watchdog.test/de/legal/terms">', false);
        $this->get('/de/legal')->assertNotFound();

        $this->postJson("/platform-api/v1/pages/{$legal['id']}/archive", ['reason' => 'cleanup'])->assertConflict()->assertJsonPath('code', 'has_children');
        $this->postJson("/platform-api/v1/pages/{$system}/archive", ['reason' => 'cleanup'])->assertConflict()->assertJsonPath('code', 'system_page');
        $this->postJson("/platform-api/v1/pages/{$terms['id']}/archive", ['reason' => 'cleanup'])->assertNoContent();
        $this->get('/de/legal/terms')->assertNotFound();
        $this->postJson('/platform-api/v1/pages', ['slug' => 'terms', 'parent_id' => $legal['id']])->assertCreated();
        $this->getJson('/platform-api/v1/pages/not-a-uuid')->assertNotFound();
    }

    public function test_home_pricing_and_contact_take_their_text_from_the_owner_panel(): void
    {
        $this->signIn();
        $home = PublicPage::query()->where('kind', 'home')->value('id');
        $this->putJson("/platform-api/v1/pages/{$home}/locales/en/draft", ['title' => 'Watchdog for shops', 'body' => '<section class="hero"><h1>Own home</h1></section>'])->assertOk();
        $this->postJson("/platform-api/v1/pages/{$home}/locales/en/publish", ['reason' => 'New home'])->assertOk();

        $this->get('/en')->assertSee('Own home')->assertDontSee('Find out that payments broke')->assertSee('<title>Watchdog for shops — Business Watchdog</title>', false);
        $this->get('/ru')->assertSee('Узнайте, что оплата сломалась');

        $contact = PublicPage::query()->where('kind', 'contact')->value('id');
        $this->putJson("/platform-api/v1/pages/{$contact}/locales/en/draft", ['title' => 'Write to us', 'body' => '<p>We answer within a day.</p>'])->assertOk();
        $this->postJson("/platform-api/v1/pages/{$contact}/locales/en/publish", ['reason' => 'Contact text'])->assertOk();
        $this->get('/en/contact')->assertSee('We answer within a day.')->assertSee('data-form="contact"', false)->assertSee('>Write to us</a>', false);
    }

    public function test_pricing_shows_published_plans_with_the_real_yearly_charge_and_hides_drafts(): void
    {
        $start = $this->plan('start', ['en' => 'Start', 'ru' => 'Старт'], [['month', 1900], ['year', 19000]], extra: 500);
        $this->plan('example', ['en' => 'Example'], [['month', 100]], placeholder: true);
        $this->plan('hidden', ['en' => 'Hidden'], [['month', 100]], state: BillingPlan::STATE_DRAFT);

        $monthly = $this->get('/ru/pricing')->assertOk();
        $monthly->assertSee('data-plan="start"', false)
            ->assertSee('data-plan="example"', false)
            ->assertSee('Пример тарифа')
            ->assertDontSee('data-plan="hidden"', false)
            ->assertSee('Старт')
            ->assertSee('19,00')
            ->assertSee('в месяц')
            ->assertSee('Дополнительный магазин: 5,00')
            ->assertSee('14 дней бесплатно, без карты')
            ->assertSee('Проверка оформления заказа в браузере')
            ->assertSee('Тестовый режим')
            ->assertSee('?period=year%3A1&amp;currency=EUR', false);

        $yearly = $this->get('/ru/pricing?period=year:1')->assertOk();
        $yearly->assertSee('190,00')->assertSee('в год')->assertSee('примерно 15,83')->assertSee('Выгоднее на 16 %');
        $this->get('/en/pricing?period=year:1')->assertSee('€190.00')->assertSee('about €15.83 per month');
        $this->get('/de/pricing?period=week:9&currency=XXX')->assertSee('19,00');
        $this->assertNotNull($start);

        config()->set('watchdog.billing.paddle.environment', 'production');
        $this->plan('live', ['en' => 'Live'], [['month', 2900]], mode: 'live');
        $this->plan('live-example', ['en' => 'Live example'], [['month', 100]], placeholder: true, mode: 'live');
        $this->get('/en/pricing')->assertSee('data-plan="live"', false)->assertSee('€29.00')->assertDontSee('data-plan="live-example"', false)->assertDontSee('data-plan="start"', false)->assertDontSee('Test mode');
    }

    public function test_contact_form_stores_the_message_notifies_the_owner_and_resists_spam(): void
    {
        $this->get('/ru/contact')->assertOk()->assertSee('name="_token"', false)->assertHeader('Cache-Control', 'no-store, private');

        $this->post('/ru/contact', ['name' => 'Анна', 'email' => 'Anna@Shop.test', 'company' => 'Kaffee', 'message' => 'Хочу подключить два магазина.'])
            ->assertRedirect('/ru/contact')
            ->assertSessionHas('contact_sent', true);
        $this->get('/ru/contact')->assertSee('data-contact="sent"', false);
        $stored = ContactRequest::query()->sole();
        $this->assertSame(['ru', 'Анна', 'anna@shop.test', 'Kaffee', 'new'], [$stored->locale, $stored->name, $stored->email, $stored->company, $stored->status]);

        $this->post('/ru/contact', ['name' => '', 'email' => 'nope', 'message' => 'short'])->assertSessionHasErrors(['name', 'email', 'message']);
        $this->post('/ru/contact', ['name' => 'Bot', 'email' => 'bot@spam.test', 'message' => 'Buy cheap things now!', 'website' => 'https://spam.test'])->assertSessionHas('contact_sent', true);
        $this->assertSame(1, ContactRequest::query()->count());

        for ($i = 0; $i < 4; $i++) {
            $this->post('/en/contact', ['name' => 'Ben', 'email' => 'ben@shop.test', 'message' => 'Message number '.$i])->assertSessionHas('contact_sent', true);
        }

        $this->post('/en/contact', ['name' => 'Ben', 'email' => 'ben@shop.test', 'message' => 'One message too many'])->assertSessionHasErrors('form');
        $this->assertSame(5, ContactRequest::query()->count());

        Mail::fake();
        $this->owner('owner@platform.test');
        $this->artisan('site:contact-notify')->expectsOutput('sent=5 failed=0')->assertSuccessful();
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('owner@platform.test') && str_contains($mail->bodyText, 'Хочу подключить два магазина.') && str_contains($mail->bodyText, '/owner/contact-requests/'.$stored->id));
        $this->artisan('site:contact-notify')->expectsOutput('sent=0 failed=0');

        config()->set('watchdog.site.contact_notify', 'inbox@watchdog.test');
        ContactRequest::query()->whereKey($stored->id)->update(['notified_at' => null]);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
        $this->artisan('site:contact-notify')->expectsOutput('sent=0 failed=1');
        $this->assertNull(ContactRequest::query()->find($stored->id)->notified_at);
    }

    public function test_messages_are_handled_in_the_owner_panel_by_role_and_closed_ones_expire(): void
    {
        $request = ContactRequest::query()->create(['locale' => 'de', 'name' => 'Jonas', 'email' => 'jonas@shop.test', 'message' => str_repeat('Frage zur Einrichtung. ', 20), 'status' => 'new']);
        ContactRequest::query()->create(['locale' => 'en', 'name' => 'Old', 'email' => 'old@shop.test', 'message' => 'Old question here', 'status' => 'closed', 'closed_at' => Carbon::now()->subDays(400)]);

        $this->signIn(PlatformStaff::ROLE_SUPPORT);
        $list = $this->getJson('/platform-api/v1/contact-requests?status=new')->assertOk();
        $list->assertJsonPath('data.0.name', 'Jonas')->assertJsonPath('counts.new', 1)->assertJsonPath('counts.closed', 1);
        $this->assertLessThan(200, mb_strlen($list->json('data.0.message')));
        $this->getJson("/platform-api/v1/contact-requests/{$request->id}")->assertOk()->assertJsonPath('email', 'jonas@shop.test');
        $this->patchJson("/platform-api/v1/contact-requests/{$request->id}", ['status' => 'closed', 'note' => 'Answered by mail'])
            ->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('note', 'Answered by mail')->assertJsonPath('handled_by', 'Staff');
        $this->getJson('/platform-api/v1/pages')->assertForbidden();
        $this->assertSame('site.contact.updated', DB::table('platform_audit_log')->where('target_id', $request->id)->value('action'));

        $this->artisan('site:contact-purge')->expectsOutput('deleted=1');
        $this->assertSame(['Jonas'], ContactRequest::query()->pluck('name')->all());
    }

    public function test_admin_reads_messages_but_neither_edits_pages_nor_handles_messages(): void
    {
        $request = ContactRequest::query()->create(['locale' => 'en', 'name' => 'Jonas', 'email' => 'jonas@shop.test', 'message' => 'A question for you', 'status' => 'new']);
        $this->signIn(PlatformStaff::ROLE_ADMIN);

        $this->getJson('/platform-api/v1/contact-requests')->assertOk();
        $this->patchJson("/platform-api/v1/contact-requests/{$request->id}", ['status' => 'closed'])->assertForbidden();
        $this->postJson('/platform-api/v1/pages', ['slug' => 'about'])->assertForbidden();
    }

    /**
     * @param  array<string, string>  $name
     * @param  list<array{0: string, 1: int}>  $prices
     */
    private function plan(string $code, array $name, array $prices, ?int $extra = null, bool $placeholder = false, string $state = BillingPlan::STATE_PUBLISHED, string $mode = 'test'): BillingPlanVersion
    {
        $plan = BillingPlan::query()->create(['code' => $code, 'state' => $state]);
        $version = BillingPlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version' => 1,
            'state' => $state,
            'trial_days' => 14,
            'localized_content' => ['name' => $name, 'placeholder' => $placeholder],
            'entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 2, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 15]]),
        ]);

        foreach ($prices as [$interval, $amount]) {
            foreach (array_filter([BillingPrice::KIND_BASE => $amount, BillingPrice::KIND_EXTRA_STORE => $extra === null ? null : ($interval === 'year' ? $extra * 10 : $extra)]) as $kind => $minor) {
                BillingPrice::query()->create([
                    'plan_version_id' => $version->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_price_ref' => "pri_{$code}_{$interval}_{$kind}",
                    'price_kind' => $kind, 'mode' => $mode, 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => $minor,
                    'billing_interval' => $interval, 'tax_behavior' => 'provider_calculated', 'state' => BillingPlan::STATE_PUBLISHED,
                ]);
            }
        }

        return $version;
    }

    private function owner(string $email): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => $email, 'password_hash' => Hash::make('owner-password-1234'), 'locale' => 'ru']);
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
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
