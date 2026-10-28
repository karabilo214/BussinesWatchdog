<?php

namespace Tests\Feature\Site;

use App\Mail\NotificationMessageMail;
use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\ContactRequest;
use App\Models\MaintenanceNotice;
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
use Illuminate\Support\Str;
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

    public function test_sitemap_lists_shown_pages_with_their_languages_and_robots_points_to_it(): void
    {
        $about = PublicPage::query()->create(['kind' => 'page', 'slug' => 'about', 'path' => 'about', 'menu' => 'footer', 'sort' => 0]);
        PublicPageVersion::query()->create(['page_id' => $about->id, 'locale' => 'de', 'version' => 1, 'title' => 'Über uns', 'body' => '<p>Text</p>', 'content_hash' => str_repeat('0', 64), 'state' => 'published', 'published_at' => Carbon::parse('2026-10-15T08:00:00Z')]);
        PublicPageVersion::query()->create(['page_id' => $about->id, 'locale' => 'en', 'version' => 1, 'title' => 'About', 'body' => '<p>Text</p>', 'content_hash' => str_repeat('1', 64), 'state' => 'published', 'published_at' => Carbon::parse('2026-10-16T08:00:00Z')]);
        PublicPageVersion::query()->create(['page_id' => $about->id, 'locale' => 'ru', 'version' => 1, 'title' => 'О нас', 'body' => '<p>Текст</p>', 'content_hash' => str_repeat('2', 64), 'state' => 'draft']);
        $old = PublicPage::query()->create(['kind' => 'page', 'slug' => 'old', 'path' => 'old', 'menu' => 'none', 'sort' => 0, 'archived_at' => Carbon::now()]);
        PublicPageVersion::query()->create(['page_id' => $old->id, 'locale' => 'en', 'version' => 1, 'title' => 'Old', 'body' => '<p>Old</p>', 'content_hash' => str_repeat('3', 64), 'state' => 'published', 'published_at' => Carbon::now()]);

        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string((string) $response->getContent());
        $this->assertNotFalse($xml);
        $locs = [];

        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }

        foreach (['ru', 'en', 'de'] as $locale) {
            foreach (['', '/pricing', '/contact'] as $path) {
                $this->assertContains("https://watchdog.test/{$locale}{$path}", $locs);
            }
        }

        $this->assertContains('https://watchdog.test/de/about', $locs);
        $this->assertContains('https://watchdog.test/en/about', $locs);
        $this->assertNotContains('https://watchdog.test/ru/about', $locs, 'a draft is not shown');
        $this->assertNotContains('https://watchdog.test/en/old', $locs, 'an archived page is not shown');
        $this->assertSame([], array_values(array_filter($locs, fn (string $loc): bool => preg_match('#/(signup|check-store|pay)$#', $loc) === 1)));
        $this->assertSame(['https://watchdog.test/ru', 'https://watchdog.test/en', 'https://watchdog.test/de'], array_slice($locs, 0, 3), 'the home page comes first');

        $about = collect(iterator_to_array($xml->url, false))->first(fn ($url): bool => (string) $url->loc === 'https://watchdog.test/de/about');
        $this->assertSame('2026-10-15', (string) $about->lastmod);
        $alternates = [];

        foreach ($about->children('http://www.w3.org/1999/xhtml')->link as $link) {
            $alternates[(string) $link->attributes()->hreflang] = (string) $link->attributes()->href;
        }

        $this->assertSame(['en' => 'https://watchdog.test/en/about', 'de' => 'https://watchdog.test/de/about'], $alternates);

        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Sitemap: https://watchdog.test/sitemap.xml', false)
            ->assertSee("User-agent: *\nDisallow:\n", false);
    }

    public function test_the_videos_page_is_an_editable_page_created_by_command_with_working_video_tags(): void
    {
        $this->owner('owner@platform.test');

        $this->artisan('site:page', ['slug' => 'videos', 'staff' => 'owner@platform.test'])->assertSuccessful();
        $this->get('/de/videos')->assertNotFound();

        $this->artisan('site:page', ['slug' => 'videos', 'staff' => 'owner@platform.test', '--publish' => 'Videos'])->assertSuccessful();
        $this->assertSame(1, PublicPage::query()->where('path', 'videos')->count(), 'running again keeps one page');
        $this->get('/de/videos')->assertOk()
            ->assertSee('<source src="/videos/de-checkout-pruefung.mp4" type="video/mp4"', false)
            ->assertSee('poster="/videos/de-bezahlt-ohne-geld.webp"', false)
            ->assertSee('controls', false);
        $this->get('/ru/videos')->assertOk()->assertSee('/videos/en-paid-without-money.mp4', false)->assertSee('Видео на английском языке.');
        $this->get('/en')->assertOk()->assertSee('/en/videos', false);
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://watchdog.test/de/videos', false);

        foreach (['en', 'de', 'ru'] as $locale) {
            preg_match_all('#/videos/([a-z-]+\.(?:mp4|webp))#', (string) file_get_contents(database_path("data/pages/videos/{$locale}.html")), $files);
            $this->assertNotEmpty($files[1]);

            foreach ($files[1] as $file) {
                $this->assertFileExists(public_path('videos/'.$file));
            }
        }
    }

    public function test_the_security_page_is_published_by_command_in_the_footer_with_gdpr_answers_and_no_source_links(): void
    {
        $this->owner('owner@platform.test');

        $this->artisan('site:page', ['slug' => 'missing', 'staff' => 'owner@platform.test'])->assertFailed();
        $this->artisan('site:page', ['slug' => '../videos', 'staff' => 'owner@platform.test'])->assertFailed();
        $this->artisan('site:page', ['slug' => 'security', 'staff' => 'owner@platform.test', '--publish' => 'Security'])->assertSuccessful();

        $page = PublicPage::query()->where('path', 'security')->firstOrFail();
        $this->assertSame(PublicPage::MENU_FOOTER, $page->menu);

        $this->get('/en/security')->assertOk()
            ->assertSee('<h1>Why Business Watchdog is safe for your store</h1>', false)
            ->assertSee('<a href="/en/terms" rel="noopener noreferrer">', false)
            ->assertSee('<a href="/en/privacy" rel="noopener noreferrer">', false)
            ->assertSee('<section class="site-stores">', false)
            ->assertSee('Art. 28 GDPR');
        $this->get('/de/security')->assertOk()->assertSee('Auftragsverarbeiter nach Art. 28 DSGVO')->assertSee('<a href="/de/terms" rel="noopener noreferrer">', false);
        $this->get('/ru/security')->assertOk()->assertSee('Вопросы о GDPR')->assertSee('<a href="/ru/privacy" rel="noopener noreferrer">', false);
        $this->get('/en')->assertOk()->assertSee('/en/security', false);
        $this->get('/sitemap.xml')->assertOk()->assertSee('https://watchdog.test/ru/security', false);

        foreach (['en', 'de', 'ru'] as $locale) {
            $this->get("/{$locale}/security")->assertDontSee('github', false)->assertDontSee('GitHub', false);
        }
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
            ['site.page.created', 'site.page.draft_saved', 'site.page.published', 'site.page.draft_saved', 'site.page.published', 'site.page.restored', 'site.page.draft_discarded', 'site.page.unpublished'],
            DB::table('platform_audit_log')->where('target_type', 'public_page')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('Первая версия', DB::table('platform_audit_log')->where('action', 'site.page.published')->orderBy('id')->value('reason'));
    }

    public function test_the_published_version_is_changed_in_place_except_for_legal_documents(): void
    {
        $this->signIn();
        $page = $this->postJson('/platform-api/v1/pages', ['slug' => 'videos', 'menu' => 'header', 'sort' => 5])->assertCreated()->json();
        $url = "/platform-api/v1/pages/{$page['id']}/locales/de/published";

        $this->putJson($url, ['title' => 'Videos', 'body' => '<p>x</p>', 'reason' => 'Fix'])->assertStatus(409)->assertJsonPath('code', 'not_published');
        $this->putJson("/platform-api/v1/pages/{$page['id']}/locales/de/draft", ['title' => 'Videos', 'body' => '<p class="site-stores">Buttons</p>'])->assertOk();
        $this->postJson("/platform-api/v1/pages/{$page['id']}/locales/de/publish", ['reason' => 'Erste Version'])->assertOk();

        $this->putJson($url, ['title' => 'Videos', 'body' => '<section class="site-stores">Buttons</section>'])->assertUnprocessable();
        $this->putJson($url, ['title' => 'Videos', 'body' => '<section class="site-stores">Buttons</section><script>x()</script>', 'reason' => 'Abstand der Buttons'])
            ->assertOk()->assertJsonPath('content.de.published.version', 1)->assertJsonPath('content.de.draft', null);

        $this->get('/de/videos')->assertOk()->assertSee('<section class="site-stores">Buttons</section>', false)->assertDontSee('x()', false);
        $this->assertSame(1, PublicPageVersion::query()->where('page_id', $page['id'])->count(), 'no new version, no draft');
        $audit = DB::table('platform_audit_log')->where('action', 'site.page.published_updated')->sole();
        $changes = json_decode($audit->sanitized_changes, true);
        $this->assertSame('Abstand der Buttons', $audit->reason);
        $this->assertSame('<p class="site-stores">Buttons</p>', $changes['before']['body']);
        $this->assertSame('<section class="site-stores">Buttons</section>', $changes['after']['body']);

        $this->patchJson("/platform-api/v1/pages/{$page['id']}", ['policy_type' => 'privacy_notice'])->assertOk();
        $this->putJson($url, ['title' => 'Datenschutz', 'body' => '<p>Neu</p>', 'reason' => 'Fix'])->assertStatus(409)->assertJsonPath('code', 'legal_page_needs_version');
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

    public function test_home_is_edited_from_its_built_in_text_and_pricing_and_contact_only_in_code(): void
    {
        $this->signIn();
        $home = PublicPage::query()->where('kind', 'home')->value('id');
        $card = $this->getJson("/platform-api/v1/pages/{$home}")->assertOk()->assertJsonPath('editable', true)->json();
        $builtin = $card['builtin']['ru'];
        $this->assertSame('Business Watchdog — контроль оплаты в интернет-магазине', $builtin['title']);
        $this->assertStringContainsString('<section class="hero">', $builtin['body']);
        $this->assertStringContainsString('Узнайте, что оплата сломалась, раньше покупателей', $builtin['body']);
        $this->assertStringContainsString('href="/ru/signup"', $builtin['body']);
        $this->assertStringContainsString('Find out that payments broke before your customers do', $card['builtin']['en']['body']);
        $this->assertStringContainsString(str_replace('&#34;', '"', $builtin['body']), str_replace('&#34;', '"', $this->get('/ru')->getContent()));

        $preview = $this->postJson("/platform-api/v1/pages/{$home}/preview", ['locale' => 'ru', 'title' => 'Главная', 'body' => ''])->assertOk()->json('html');
        $this->assertStringContainsString('Узнайте, что оплата сломалась', $preview);

        $this->putJson("/platform-api/v1/pages/{$home}/locales/en/draft", ['title' => 'Watchdog for shops', 'body' => str_replace('Find out that payments broke before your customers do', 'Own home', $card['builtin']['en']['body'])])->assertOk();
        $this->postJson("/platform-api/v1/pages/{$home}/locales/en/publish", ['reason' => 'New home'])->assertOk();

        $this->get('/en')->assertSee('Own home')->assertDontSee('Find out that payments broke')->assertSee('Real payment attempts')->assertSee('<title>Watchdog for shops — Business Watchdog</title>', false);
        $this->get('/ru')->assertSee('Узнайте, что оплата сломалась');

        foreach (['pricing', 'contact'] as $kind) {
            $id = PublicPage::query()->where('kind', $kind)->value('id');
            $this->getJson("/platform-api/v1/pages/{$id}")->assertOk()->assertJsonPath('editable', false)->assertJsonPath('builtin', null);
            $this->putJson("/platform-api/v1/pages/{$id}/locales/en/draft", ['title' => 'Write to us', 'body' => '<p>Changed.</p>'])->assertStatus(409)->assertJsonPath('code', 'edited_in_code');
            $this->postJson("/platform-api/v1/pages/{$id}/locales/en/publish", ['reason' => 'Try'])->assertStatus(409)->assertJsonPath('code', 'edited_in_code');
            $this->postJson("/platform-api/v1/pages/{$id}/preview", ['locale' => 'en', 'title' => 'X'])->assertStatus(409)->assertJsonPath('code', 'edited_in_code');
            $this->patchJson("/platform-api/v1/pages/{$id}", ['menu' => 'footer', 'sort' => 5])->assertOk()->assertJsonPath('menu', 'footer');
        }

        $contact = PublicPage::query()->where('kind', 'contact')->value('id');
        PublicPageVersion::query()->create(['page_id' => $contact, 'locale' => 'en', 'version' => 1, 'title' => 'Stored title', 'body' => '<p>Stored text.</p>', 'content_hash' => str_repeat('0', 64), 'state' => 'published', 'published_at' => Carbon::now()]);
        $this->get('/en/contact')->assertDontSee('Stored text.')->assertDontSee('Stored title')->assertSee('data-form="contact"', false);
        $version = PublicPageVersion::query()->where('page_id', $contact)->value('id');
        $this->postJson("/platform-api/v1/pages/{$contact}/versions/{$version}/restore")->assertStatus(409)->assertJsonPath('code', 'edited_in_code');
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
        $this->get('/en/pricing')->assertSee('<title>Store Monitoring Plans &amp; Pricing | Business Watchdog</title>', false)->assertSee('class="bw-page"', false)->assertSee('Business Watchdog plans from €19 a month', false);
        $this->get('/de/pricing?period=week:9&currency=XXX')->assertSee('19,00');
        $this->assertNotNull($start);

        config()->set('watchdog.billing.paddle.environment', 'production');
        $this->plan('live', ['en' => 'Live'], [['month', 2900]], mode: 'live');
        $this->plan('live-example', ['en' => 'Live example'], [['month', 100]], placeholder: true, mode: 'live');
        $this->get('/en/pricing')->assertSee('data-plan="live"', false)->assertSee('€29.00')->assertDontSee('data-plan="live-example"', false)->assertDontSee('data-plan="start"', false)->assertDontSee('Test mode');
    }

    public function test_old_page_versions_can_be_deleted_except_the_current_ones_and_legal_documents(): void
    {
        $this->signIn();
        $page = $this->postJson('/platform-api/v1/pages', ['slug' => 'about'])->assertCreated()->json('id');
        foreach (['Erste', 'Zweite', 'Dritte'] as $title) {
            $this->putJson("/platform-api/v1/pages/{$page}/locales/de/draft", ['title' => $title, 'body' => "<p>{$title}</p>"])->assertOk();
            $this->postJson("/platform-api/v1/pages/{$page}/locales/de/publish", ['reason' => 'Neu'])->assertOk();
        }
        $this->putJson("/platform-api/v1/pages/{$page}/locales/de/draft", ['title' => 'Entwurf', 'body' => '<p>Entwurf</p>'])->assertOk();
        $versions = PublicPageVersion::query()->where('page_id', $page)->orderBy('version')->get()->keyBy('version');

        $this->postJson("/platform-api/v1/pages/{$page}/versions/{$versions[1]->id}/delete", [])->assertStatus(422);
        $card = $this->postJson("/platform-api/v1/pages/{$page}/versions/{$versions[1]->id}/delete", ['reason' => 'Alt'])->assertOk()->json();
        $this->assertSame([3, 2], array_column($card['history'], 'version'));
        $this->postJson("/platform-api/v1/pages/{$page}/versions/{$versions[3]->id}/delete", ['reason' => 'Aktuell'])->assertStatus(409)->assertJsonPath('code', 'version_not_retired');
        $this->postJson("/platform-api/v1/pages/{$page}/versions/{$versions[4]->id}/delete", ['reason' => 'Entwurf'])->assertStatus(409)->assertJsonPath('code', 'version_not_retired');
        $this->postJson("/platform-api/v1/pages/{$page}/versions/{$versions[1]->id}/delete", ['reason' => 'Nochmal'])->assertNotFound();
        $this->get('/de/about')->assertSee('Dritte');
        $this->assertSame(1, DB::table('platform_audit_log')->where('action', 'site.page.version_deleted')->where('reason', 'Alt')->count());

        $this->patchJson("/platform-api/v1/pages/{$page}", ['policy_type' => 'service_terms'])->assertOk();
        $this->postJson("/platform-api/v1/pages/{$page}/versions/{$versions[2]->id}/delete", ['reason' => 'Dokument'])->assertStatus(409)->assertJsonPath('code', 'version_is_legal');
        $user = User::query()->create(['name' => 'Kunde', 'email' => 'kunde@shop.test', 'password_hash' => Hash::make('kunde-password-1234'), 'locale' => 'de']);
        DB::table('policy_acceptances')->insert(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'policy_type' => 'service_terms', 'page_version_id' => $versions[2]->id, 'locale' => 'de', 'version' => 2, 'content_hash' => $versions[2]->content_hash, 'purpose' => 'signup', 'accepted_at' => now()]);
        $this->patchJson("/platform-api/v1/pages/{$page}", ['policy_type' => null])->assertOk();
        $this->postJson("/platform-api/v1/pages/{$page}/versions/{$versions[2]->id}/delete", ['reason' => 'Dokument'])->assertStatus(409)->assertJsonPath('code', 'version_is_legal');
    }

    public function test_pricing_recalculates_the_price_for_extra_stores_without_javascript_within_the_plan_limit(): void
    {
        $start = $this->plan('start', ['en' => 'Start', 'de' => 'Start'], [['month', 1900], ['year', 19000]], extra: 500);
        $start->forceFill(['entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_EXTRA_STORES => 4]])])->save();
        $this->plan('solo', ['en' => 'Solo', 'de' => 'Solo'], [['month', 900]]);

        $plain = $this->get('/de/pricing')->assertOk();
        $plain->assertSee('data-form="stores"', false)->assertSee('max="4"', false)->assertDontSee('data-total=', false);

        $three = $this->get('/de/pricing?stores=3')->assertOk();
        $three->assertSee('data-total="start"', false)->assertSee('Mit 3 zusätzlichen Shops:')->assertSee('34,00')->assertDontSee('data-total="solo"', false);
        $yearlyPriceId = BillingPrice::query()->where('plan_version_id', $start->id)->where('price_kind', BillingPrice::KIND_BASE)->where('billing_interval', 'year')->value('id');
        $this->get('/de/pricing?stores=2&period=year:1')->assertSee('strong>290,00', false)
            ->assertSee('price='.$yearlyPriceId.'&amp;stores=2', false);
        $three->assertSee('stores=3', false);
        $this->assertStringContainsString('value="3"', (string) $three->getContent());

        $this->get('/de/pricing?stores=10')->assertSee('Mit 4 zusätzlichen Shops:')->assertSee('39,00')->assertSee('höchstens 4 zusätzliche Shops');
        $this->get('/de/pricing?stores=2&period=year:1')->assertSee('290,00');
        $this->get('/de/pricing?stores=-2')->assertOk()->assertDontSee('data-total=', false);
        $this->get('/de/pricing?stores=abc')->assertOk()->assertDontSee('data-total=', false);
    }

    public function test_pricing_adds_large_minor_unit_amounts_without_overflow(): void
    {
        $this->plan('large', ['en' => 'Large'], [['month', 9000000000000000000]], extra: 100000000000000000);

        $this->get('/en/pricing?stores=2')->assertOk()
            ->assertSee('92000000000000000.00')
            ->assertSee('data-total="large"', false);
    }

    public function test_contact_form_stores_the_message_notifies_the_owner_and_resists_spam(): void
    {
        $this->get('/ru/contact')->assertOk()->assertSee('name="_token"', false)->assertHeader('Cache-Control', 'no-store, private');

        $this->post('/ru/contact', ['name' => 'Анна', 'email' => 'Anna@Shop.test', 'company' => 'Kaffee', 'message' => 'Хочу подключить два магазина.', 'captcha_answer' => $this->contactCaptcha('ru')])
            ->assertRedirect('/ru/contact')
            ->assertSessionHas('contact_sent', true);
        $this->get('/ru/contact')->assertSee('data-contact="sent"', false);
        $stored = ContactRequest::query()->sole();
        $this->assertSame(['ru', 'Анна', 'anna@shop.test', 'Kaffee', 'new'], [$stored->locale, $stored->name, $stored->email, $stored->company, $stored->status]);

        $this->post('/ru/contact', ['name' => '', 'email' => 'nope', 'message' => 'short'])->assertSessionHasErrors(['name', 'email', 'message']);
        $this->post('/ru/contact', ['name' => 'Bot', 'email' => 'bot@spam.test', 'message' => 'Buy cheap things now!', 'website' => 'https://spam.test'])->assertSessionHas('contact_sent', true);
        $this->assertSame(1, ContactRequest::query()->count());

        for ($i = 0; $i < 4; $i++) {
            $this->post('/en/contact', ['name' => 'Ben', 'email' => 'ben@shop.test', 'message' => 'Message number '.$i, 'captcha_answer' => $this->contactCaptcha('en')])->assertSessionHas('contact_sent', true);
        }

        $this->post('/en/contact', ['name' => 'Ben', 'email' => 'ben@shop.test', 'message' => 'One message too many', 'captcha_answer' => $this->contactCaptcha('en')])->assertSessionHasErrors('form');
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

    public function test_support_replies_to_a_message_by_email_from_a_template_and_the_reply_is_kept(): void
    {
        Mail::fake();
        $request = ContactRequest::query()->create(['locale' => 'de', 'name' => 'Jonas', 'email' => 'jonas@shop.test', 'message' => 'Wie verbinde ich zwei Shops?', 'status' => 'new']);
        $this->signIn(PlatformStaff::ROLE_SUPPORT);

        $card = $this->getJson("/platform-api/v1/contact-requests/{$request->id}")->assertOk()->assertJsonPath('replies', [])->json();
        $this->assertSame(['answer', 'more_details', 'resolved'], array_column($card['reply_templates'], 'code'));
        $this->assertSame('Business Watchdog: bitte ein paar Details', $card['reply_templates'][1]['subject']);

        $this->postJson("/platform-api/v1/contact-requests/{$request->id}/replies", ['template' => 'answer', 'subject' => 'Antwort', 'body' => ''])->assertStatus(422);
        $this->postJson("/platform-api/v1/contact-requests/{$request->id}/replies", ['template' => 'other', 'subject' => 'Antwort', 'body' => 'Text'])->assertStatus(422);
        Mail::assertNothingSent();

        $sent = $this->postJson("/platform-api/v1/contact-requests/{$request->id}/replies", ['template' => 'answer', 'subject' => 'Antwort auf Ihre Frage', 'body' => 'Fügen Sie den zweiten Shop im Kundenkonto hinzu.'])
            ->assertCreated()->assertJsonPath('status', 'in_progress')->assertJsonPath('replies.0.subject', 'Antwort auf Ihre Frage')->assertJsonPath('replies.0.sent_by', 'Staff')->assertJsonPath('replies.0.template', 'answer');
        $this->assertSame('Fügen Sie den zweiten Shop im Kundenkonto hinzu.', $sent->json('replies.0.body'));
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('jonas@shop.test') && $mail->subjectLine === 'Antwort auf Ihre Frage'
            && str_contains($mail->bodyText, 'Hallo Jonas,') && str_contains($mail->bodyText, 'zweiten Shop') && str_contains($mail->bodyText, 'Wie verbinde ich zwei Shops?') && str_contains($mail->bodyText, 'Business-Watchdog-Team'));
        $this->assertSame('site.contact.replied', DB::table('platform_audit_log')->where('target_id', $request->id)->where('action', 'site.contact.replied')->value('action'));

        $request->forceFill(['status' => 'closed', 'closed_at' => Carbon::now()])->save();
        $this->postJson("/platform-api/v1/contact-requests/{$request->id}/replies", ['template' => 'resolved', 'subject' => 'Geklärt', 'body' => 'Alles erledigt.'])->assertCreated()->assertJsonPath('status', 'closed')->assertJsonCount(2, 'replies');

        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
        $this->postJson("/platform-api/v1/contact-requests/{$request->id}/replies", ['template' => 'answer', 'subject' => 'Noch was', 'body' => 'Text dazu'])->assertStatus(502)->assertJsonPath('code', 'reply_not_sent');
        $this->assertSame(2, DB::table('contact_request_replies')->where('contact_request_id', $request->id)->count());

        $request->forceFill(['closed_at' => Carbon::now()->subDays(400)])->save();
        $this->artisan('site:contact-purge')->assertSuccessful();
        $this->assertSame(0, DB::table('contact_request_replies')->count());
    }

    public function test_reply_templates_are_saved_by_staff_and_authors_edit_their_own_notes(): void
    {
        $request = ContactRequest::query()->create(['locale' => 'de', 'name' => 'Jonas', 'email' => 'jonas@shop.test', 'message' => 'Wie verbinde ich zwei Shops?', 'status' => 'new']);
        $this->signIn(PlatformStaff::ROLE_SUPPORT);

        $this->putJson('/platform-api/v1/contact-reply-templates/answer/de', ['subject' => 'x', 'body' => 'Text'])->assertStatus(422)->assertJsonPath('code', 'template_text_invalid');
        $this->putJson('/platform-api/v1/contact-reply-templates/other/de', ['subject' => 'Antwort', 'body' => 'Text'])->assertNotFound();
        $this->putJson('/platform-api/v1/contact-reply-templates/answer/de', ['subject' => 'Antwort von Business Watchdog', 'body' => "Danke für Ihre Nachricht.\n\n"])->assertOk()->assertJsonPath('customized', true);
        $card = $this->getJson("/platform-api/v1/contact-requests/{$request->id}")->assertOk()->json();
        $this->assertSame('Antwort von Business Watchdog', $card['reply_templates'][0]['subject']);
        $this->assertTrue($card['reply_templates'][0]['customized']);
        $this->assertFalse($card['reply_templates'][1]['customized']);
        $saved = json_decode((string) DB::table('platform_audit_log')->where('action', 'site.contact.template_saved')->value('sanitized_changes'), true);
        $this->assertSame('Antwort von Business Watchdog', $saved['after']['subject']);
        $this->assertNotSame($saved['before']['subject'], $saved['after']['subject']);

        $note = $this->postJson("/platform-api/v1/contact-requests/{$request->id}/notes", ['body' => 'Called back, no answer'])->assertCreated()->json('notes.0');
        $this->assertNull($note['edited_at']);
        $edited = $this->patchJson("/platform-api/v1/contact-requests/{$request->id}/notes/{$note['id']}", ['body' => 'Called back, will try tomorrow'])->assertOk()->json('notes.0');
        $this->assertSame('Called back, will try tomorrow', $edited['body']);
        $this->assertNotNull($edited['edited_at']);
        $audit = json_decode((string) DB::table('platform_audit_log')->where('action', 'site.contact.note_edited')->value('sanitized_changes'), true);
        $this->assertEqualsCanonicalizing(['note_id' => $note['id'], 'before' => 'Called back, no answer', 'after' => 'Called back, will try tomorrow'], $audit);

        $other = PlatformStaff::query()->create(['user_id' => User::query()->create(['name' => 'Other', 'email' => 'other@platform.test', 'password_hash' => Hash::make('x-password-1234'), 'locale' => 'en'])->id, 'role' => PlatformStaff::ROLE_SUPPORT, 'state' => PlatformStaff::STATE_ACTIVE]);
        DB::table('contact_request_notes')->where('id', $note['id'])->update(['staff_id' => $other->id]);
        $this->patchJson("/platform-api/v1/contact-requests/{$request->id}/notes/{$note['id']}", ['body' => 'Not mine'])->assertForbidden()->assertJsonPath('code', 'note_not_yours');
        $this->patchJson("/platform-api/v1/contact-requests/{$request->id}/notes/".Str::uuid(), ['body' => 'Missing'])->assertNotFound();
    }

    public function test_the_public_site_shows_the_maintenance_window_in_the_page_language(): void
    {
        Carbon::setTestNow('2026-10-20T08:00:00Z');
        $this->get('/de')->assertOk()->assertDontSee('data-maintenance', false);
        $owner = PlatformStaff::query()->create(['user_id' => User::query()->create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password_hash' => 'x', 'locale' => 'ru'])->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
        MaintenanceNotice::query()->create(['version' => 1, 'starts_at' => Carbon::parse('2026-10-21T20:00:00Z'), 'ends_at' => Carbon::parse('2026-10-21T22:00:00Z'), 'messages' => ['ru' => 'Обновляем серверы.', 'de' => 'Wir aktualisieren die Server.', 'en' => 'We are updating servers.'], 'reason' => 'Upgrade', 'created_by' => $owner->id]);

        $this->get('/de')->assertOk()->assertSee('Geplante Wartung: 21.10.2026 22:00 — 22.10.2026 00:00 CEST. Wir aktualisieren die Server.');
        $this->get('/ru/pricing')->assertOk()->assertSee('Плановые технические работы')->assertSee('Обновляем серверы.');

        Carbon::setTestNow('2026-10-21T21:00:00Z');
        $this->get('/en')->assertOk()->assertSee('Maintenance in progress until 22.10.2026 00:00 CEST. We are updating servers.');

        Carbon::setTestNow('2026-10-21T22:01:00Z');
        $this->get('/en')->assertOk()->assertDontSee('data-maintenance', false);
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
        $this->postJson("/platform-api/v1/contact-requests/{$request->id}/notes", ['body' => ' '])->assertStatus(422);
        $this->postJson("/platform-api/v1/contact-requests/{$request->id}/notes", ['body' => 'Called back, asked for the shop address'])->assertCreated()->assertJsonCount(1, 'notes');
        Carbon::setTestNow(Carbon::now()->addMinute());
        $card = $this->postJson("/platform-api/v1/contact-requests/{$request->id}/notes", ['body' => 'Answered by mail'])->assertCreated()->json();
        $this->assertSame(['Answered by mail', 'Called back, asked for the shop address'], array_column($card['notes'], 'body'));
        $this->assertSame('Staff', $card['notes'][0]['author']);
        $this->assertNotSame($card['notes'][0]['created_at'], $card['notes'][1]['created_at']);
        $this->assertArrayNotHasKey('note', $card);
        $this->assertSame(2, DB::table('platform_audit_log')->where('target_id', $request->id)->where('action', 'site.contact.noted')->count());
        $this->assertStringNotContainsString('Answered by mail', (string) DB::table('platform_audit_log')->where('action', 'site.contact.noted')->pluck('sanitized_changes')->implode(''));
        $this->patchJson("/platform-api/v1/contact-requests/{$request->id}", [])->assertStatus(422);
        $this->patchJson("/platform-api/v1/contact-requests/{$request->id}", ['status' => 'closed'])
            ->assertOk()->assertJsonPath('status', 'closed')->assertJsonCount(2, 'notes')->assertJsonPath('handled_by', 'Staff');
        $this->getJson('/platform-api/v1/pages')->assertForbidden();
        $this->assertSame(1, DB::table('platform_audit_log')->where('target_id', $request->id)->where('action', 'site.contact.updated')->count());

        $this->artisan('site:contact-purge')->expectsOutput('deleted=1');
        $this->assertSame(['Jonas'], ContactRequest::query()->pluck('name')->all());
    }

    public function test_admin_reads_messages_but_neither_edits_pages_nor_handles_messages(): void
    {
        $request = ContactRequest::query()->create(['locale' => 'en', 'name' => 'Jonas', 'email' => 'jonas@shop.test', 'message' => 'A question for you', 'status' => 'new']);
        $this->signIn(PlatformStaff::ROLE_ADMIN);

        $this->getJson('/platform-api/v1/contact-requests')->assertOk();
        $this->patchJson("/platform-api/v1/contact-requests/{$request->id}", ['status' => 'closed'])->assertForbidden();
        $this->postJson("/platform-api/v1/contact-requests/{$request->id}/replies", ['template' => 'answer', 'subject' => 'Hi', 'body' => 'Text'])->assertForbidden();
        $this->postJson("/platform-api/v1/contact-requests/{$request->id}/notes", ['body' => 'Text'])->assertForbidden();
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

    private function contactCaptcha(string $locale): int
    {
        $this->get('/'.$locale.'/contact/captcha')->assertOk();
        preg_match('/^(\\d+) \\+ (\\d+)$/', (string) (session('contact_math_captcha')['question'] ?? ''), $matches);

        return (int) $matches[1] + (int) $matches[2];
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
