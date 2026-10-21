<?php

namespace Tests\Feature\Site;

use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeDraftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_saved_as_drafts_and_published_only_on_request(): void
    {
        $this->owner();
        $home = PublicPage::query()->where('kind', PublicPage::KIND_HOME)->firstOrFail();

        $this->artisan('site:home-drafts', ['staff' => 'owner@platform.test'])->assertSuccessful();

        $versions = PublicPageVersion::query()->where('page_id', $home->id)->get();
        $this->assertCount(3, $versions);
        $this->assertSame([PublicPageVersion::STATE_DRAFT], $versions->pluck('state')->unique()->values()->all());
        $this->get('/en')->assertOk()->assertDontSee('Can your customers pay?');

        $this->artisan('site:home-drafts', ['staff' => 'owner@platform.test', '--publish' => 'New home page'])->assertSuccessful();

        $this->assertSame(3, PublicPageVersion::query()->where('page_id', $home->id)->where('state', PublicPageVersion::STATE_PUBLISHED)->count());
        $this->assertSame(0, PublicPageVersion::query()->where('page_id', $home->id)->where('state', PublicPageVersion::STATE_DRAFT)->count());
        $this->assertSame(3, DB::table('platform_audit_log')->where('action', 'site.page.published')->where('reason', 'New home page')->count());

        $this->get('/en')->assertOk()
            ->assertSee('<title>WooCommerce &amp; Shopware Payment Monitoring | Business Watchdog</title>', false)
            ->assertSee('class="bw-page"', false)
            ->assertSee('Can your customers pay?')
            ->assertSee('href="/en/check-store"', false);
        $this->get('/de')->assertOk()->assertSee('<title>WooCommerce &amp; Shopware Monitoring | Business Watchdog</title>', false);
        $this->get('/ru')->assertOk()->assertSee('Мониторинг WooCommerce и Shopware: оплата и заказы | Business Watchdog', false);
    }

    public function test_it_refuses_without_an_active_owner(): void
    {
        $this->artisan('site:home-drafts', ['staff' => 'nobody@platform.test'])->assertFailed();

        $this->assertSame(0, PublicPageVersion::query()->count());
    }

    private function owner(): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password_hash' => 'x', 'locale' => 'en']);

        return PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
    }
}
