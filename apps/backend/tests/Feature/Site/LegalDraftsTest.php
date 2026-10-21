<?php

namespace Tests\Feature\Site;

use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Models\User;
use App\Support\Site\PageAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalDraftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legal_pages_are_saved_as_drafts_in_every_language_with_the_provider_filled_in(): void
    {
        $owner = $this->owner();

        $this->artisan('site:legal-drafts', ['staff' => 'owner@platform.test', '--name' => 'Ivan <Petrenko>', '--address' => 'Khreshchatyk 1, 01001 Kyiv, Ukraine'])->assertSuccessful();

        $pages = PublicPage::query()->whereIn('path', ['terms', 'privacy', 'refund-policy', 'imprint'])->get()->keyBy('path');
        $this->assertCount(4, $pages);
        $this->assertSame('service_terms', $pages['terms']->policy_type);
        $this->assertSame('privacy_notice', $pages['privacy']->policy_type);
        $this->assertSame('recurring_payment', $pages['refund-policy']->policy_type);
        $this->assertNull($pages['imprint']->policy_type);
        $this->assertSame([PublicPage::MENU_FOOTER], $pages->pluck('menu')->unique()->values()->all());

        $versions = PublicPageVersion::query()->whereIn('page_id', $pages->pluck('id'))->get();
        $this->assertCount(12, $versions);
        $this->assertSame([PublicPageVersion::STATE_DRAFT], $versions->pluck('state')->unique()->values()->all());
        $this->assertSame(['de', 'en', 'ru'], $versions->pluck('locale')->unique()->sort()->values()->all());

        foreach ($versions as $version) {
            $this->assertStringNotContainsString(':seller', $version->body);
            $this->assertStringNotContainsString(':contact_email', $version->body);
            $this->assertStringNotContainsString(':locale', $version->body);
            $this->assertStringNotContainsString(':effective_date', $version->body);
            $this->assertStringNotContainsString('<Petrenko>', $version->body);

            if ($version->page_id !== $pages['refund-policy']->id) {
                $this->assertStringContainsString('Ivan &lt;Petrenko&gt;', $version->body);
            }
        }

        $terms = $versions->first(fn (PublicPageVersion $version): bool => $version->page_id === $pages['terms']->id && $version->locale === 'en');
        $this->assertStringContainsString('Paddle.com is the Merchant of Record for all our orders', $terms->body);
        $this->assertStringContainsString('href="mailto:hello&#64;business-watchdog.com"', $terms->body);
        $this->assertStringContainsString('href="/en/refund-policy"', $terms->body);
        $imprint = $versions->first(fn (PublicPageVersion $version): bool => $version->page_id === $pages['imprint']->id && $version->locale === 'de');
        $this->assertStringContainsString('Khreshchatyk 1<br />01001 Kyiv<br />Ukraine', $imprint->body);
        $this->assertStringContainsString('§ 5 DDG', $imprint->body);

        $this->get('/en/terms')->assertNotFound();

        $published = app(PageAdmin::class)->publish($owner->id, $pages['terms']->id, 'en', 'Reviewed');
        $this->artisan('site:legal-drafts', ['staff' => 'owner@platform.test', '--name' => 'Ivan Petrenko', '--address' => 'Kyiv, Ukraine'])->assertSuccessful();
        $this->assertSame(PublicPageVersion::STATE_PUBLISHED, $published->fresh()->state);
        $this->assertSame(4, PublicPage::query()->whereIn('path', ['terms', 'privacy', 'refund-policy', 'imprint'])->count());
        $this->assertSame(1, PublicPageVersion::query()->where('page_id', $pages['terms']->id)->where('locale', 'en')->where('state', PublicPageVersion::STATE_DRAFT)->count());
        $this->get('/en/terms')->assertOk()->assertSee('Terms of Service');
    }

    public function test_it_refuses_without_the_provider_or_an_owner_or_when_another_page_has_the_document_type(): void
    {
        $owner = $this->owner();

        $this->artisan('site:legal-drafts', ['staff' => 'owner@platform.test', '--address' => 'Kyiv, Ukraine'])->assertFailed();
        $this->artisan('site:legal-drafts', ['staff' => 'nobody@platform.test', '--name' => 'Ivan Petrenko', '--address' => 'Kyiv, Ukraine'])->assertFailed();

        $other = app(PageAdmin::class)->create($owner->id, ['slug' => 'agb']);
        app(PageAdmin::class)->update($owner->id, $other->id, ['policy_type' => 'service_terms']);
        $this->artisan('site:legal-drafts', ['staff' => 'owner@platform.test', '--name' => 'Ivan Petrenko', '--address' => 'Kyiv, Ukraine'])->assertFailed();

        $this->assertSame(1, PublicPage::query()->where('kind', PublicPage::KIND_PAGE)->count());
        $this->assertSame(0, PublicPageVersion::query()->count());
    }

    public function test_only_the_named_pages_get_drafts(): void
    {
        $this->owner();

        $this->artisan('site:legal-drafts', ['staff' => 'owner@platform.test', '--name' => 'Ivan Petrenko', '--address' => 'Kyiv, Ukraine', '--only' => ['terms']])->assertSuccessful();

        $this->assertSame(['terms'], PublicPage::query()->where('kind', PublicPage::KIND_PAGE)->pluck('path')->all());
        $this->assertSame(3, PublicPageVersion::query()->count());
        $terms = PublicPageVersion::query()->where('locale', 'de')->firstOrFail();
        $this->assertStringContainsString('Sie allein Verantwortlicher', $terms->body);
        $this->assertStringContainsString('Bußgelder von Aufsichtsbehörden', $terms->body);
        $this->assertStringContainsString('Server und Sicherungsspeicher in Deutschland', $terms->body);

        $this->artisan('site:legal-drafts', ['staff' => 'owner@platform.test', '--name' => 'Ivan Petrenko', '--address' => 'Kyiv, Ukraine', '--only' => ['agb']])->assertFailed();
    }

    private function owner(): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password_hash' => 'x', 'locale' => 'en']);

        return PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
    }
}
