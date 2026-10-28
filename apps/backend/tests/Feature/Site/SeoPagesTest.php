<?php

namespace Tests\Feature\Site;

use App\Models\PlatformStaff;
use App\Models\PublicMenuSection;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Models\User;
use App\Support\Site\MenuSectionAdmin;
use App\Support\Site\PageAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeoPagesTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password_hash' => 'unused', 'locale' => 'en']);

        return PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
    }

    public function test_an_active_owner_is_required_and_fresh_installations_can_skip(): void
    {
        $this->artisan('site:seo-pages')->assertFailed();
        $this->artisan('site:seo-pages', ['--if-owner' => true])->assertSuccessful();
        $this->assertSame(0, PublicPageVersion::query()->count());
        $owner = $this->owner();
        $this->artisan('site:seo-pages', ['staff' => 'missing@example.test'])->assertFailed();
        $this->artisan('site:seo-pages', ['--publish' => ' '])->assertFailed();
        $owner->user->forceFill(['disabled_at' => now()])->save();
        $this->artisan('site:seo-pages')->assertFailed();
    }

    public function test_nine_editable_guides_have_three_locales_and_publish_with_sections_seo_and_sitemap(): void
    {
        $this->owner();
        $this->artisan('site:seo-pages')->assertSuccessful();
        $this->assertSame(27, PublicPageVersion::query()->where('state', 'draft')->count());
        $this->get('/en/woocommerce-payment-pending')->assertNotFound();
        $this->artisan('site:seo-pages', ['--publish' => 'Approved SEO release'])->assertSuccessful();
        $catalog = require database_path('data/seo/catalog.php');
        $this->assertSame(27, PublicPageVersion::query()->count());
        $this->assertSame(27, PublicPageVersion::query()->where('state', 'published')->count());
        $this->assertSame(3, PublicMenuSection::query()->where('code', 'like', 'seo-%')->count());
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach ($catalog['pages'] as $slug => $data) {
            $page = PublicPage::query()->where('path', $slug)->firstOrFail();
            $this->assertTrue($page->isEditable());
            $this->assertSame('header', $page->menu);
            $this->assertSame('seo-'.$data['section'], PublicMenuSection::query()->findOrFail($page->menu_section_id)->code);
            foreach (PublicPage::LOCALES as $locale) {
                $response = $this->get('/'.$locale.'/'.$slug)->assertOk();
                $html = $response->getContent();
                $this->assertSame(1, substr_count($html, '<h1>'));
                $response->assertSee($data['locales'][$locale]['title']);
                $this->assertStringContainsString('name="description" content="'.e($data['locales'][$locale]['description']).'"', $html);
                $this->assertStringContainsString('rel="canonical"', $html);
                $this->assertStringContainsString('hreflang="de"', $html);
                $this->assertStringContainsString('class="bw-hero"', $html);
                $this->assertStringContainsString('class="bw-honest"', $html);
                $this->assertStringContainsString('id="manual"', $html);
                $this->assertStringContainsString('/'.$locale.'/'.$slug, $sitemap);
                foreach (['signup', 'pricing', 'security'] as $target) {
                    $this->assertStringContainsString('href="/'.$locale.'/'.$target.'"', $html);
                }
            }
        }
        $this->assertSame(27, DB::table('platform_audit_log')->where('action', 'site.page.published')->where('reason', 'Approved SEO release')->count());
        $this->get('/en/shopware-order-monitoring')->assertSee('This page covers payment-recording discrepancies');
        $this->get('/en/woocommerce-missing-orders')->assertSee('not proof that WooCommerce deleted an order');
    }

    public function test_repeated_releases_preserve_edits_drafts_unpublication_archival_and_section_names(): void
    {
        $owner = $this->owner();
        $this->artisan('site:seo-pages', ['--publish' => 'Initial release'])->assertSuccessful();
        $pages = app(PageAdmin::class);
        $page = PublicPage::query()->where('path', 'woocommerce-payment-pending')->firstOrFail();
        $pages->updatePublished($owner->id, $page->id, 'en', ['title' => 'Editorial heading', 'description' => 'Editorial summary', 'body' => '<h2>Editorial copy</h2><p>Keep this text.</p>'], 'Owner edit');
        $pages->saveDraft($owner->id, $page->id, 'ru', ['title' => 'Черновик владельца', 'body' => '<p>Не перезаписывать</p>']);
        $pages->unpublish($owner->id, $page->id, 'de', 'Remove German publication');
        $pages->update($owner->id, $page->id, ['menu' => 'none']);
        $archived = PublicPage::query()->where('path', 'shopware-order-monitoring')->firstOrFail();
        $pages->archive($owner->id, $archived->id, 'Editorial decision');
        $section = PublicMenuSection::query()->where('code', 'seo-woocommerce')->firstOrFail();
        app(MenuSectionAdmin::class)->save($owner->id, ['labels' => ['ru' => 'Проверки', 'en' => 'Checks', 'de' => 'Prüfungen'], 'sort' => -30], $section->id);
        $versions = PublicPageVersion::query()->count();
        $audits = DB::table('platform_audit_log')->count();
        $this->artisan('site:seo-pages', ['--publish' => 'Another release'])->assertSuccessful();
        $this->assertSame($versions, PublicPageVersion::query()->count());
        $this->assertSame($audits, DB::table('platform_audit_log')->count());
        $this->assertSame(3, PublicMenuSection::query()->where('code', 'like', 'seo-%')->count());
        $this->assertSame('none', $page->fresh()->menu);
        $this->assertSame('Checks', $section->fresh()->labels['en']);
        $this->assertSame(-30, $section->fresh()->sort);
        $this->get('/en/woocommerce-payment-pending')->assertOk()->assertSee('Editorial copy');
        $this->get('/de/woocommerce-payment-pending')->assertNotFound();
        $this->get('/en/shopware-order-monitoring')->assertNotFound();
        $this->assertSame('Черновик владельца', PublicPageVersion::query()->where('page_id', $page->id)->where('locale', 'ru')->where('state', 'draft')->value('title'));
    }

    public function test_an_edited_initial_draft_is_not_automatically_published(): void
    {
        $owner = $this->owner();
        $this->artisan('site:seo-pages')->assertSuccessful();
        $page = PublicPage::query()->where('path', 'woocommerce-payment-pending')->firstOrFail();
        app(PageAdmin::class)->saveDraft($owner->id, $page->id, 'en', ['title' => 'Work in progress', 'body' => '<p>Not ready</p>']);
        $this->artisan('site:seo-pages', ['--publish' => 'Release ready translations'])->assertSuccessful();
        $this->get('/en/woocommerce-payment-pending')->assertNotFound();
        $this->assertSame(26, PublicPageVersion::query()->where('state', 'published')->count());
    }

    public function test_a_conflicting_legal_page_aborts_the_import_without_partial_publication(): void
    {
        $owner = $this->owner();
        $pages = app(PageAdmin::class);
        $page = $pages->create($owner->id, ['slug' => 'woocommerce-payment-pending']);
        $pages->update($owner->id, $page->id, ['policy_type' => 'privacy_notice']);
        $count = PublicPage::query()->count();
        $this->artisan('site:seo-pages', ['--publish' => 'Release'])->assertFailed();
        $this->assertSame($count, PublicPage::query()->count());
        $this->assertSame(0, PublicMenuSection::query()->where('code', 'like', 'seo-%')->count());
        $this->assertSame(0, PublicPageVersion::query()->count());
    }
}
