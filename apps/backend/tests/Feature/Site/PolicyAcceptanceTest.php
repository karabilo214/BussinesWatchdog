<?php

namespace Tests\Feature\Site;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Models\Tenant;
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
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PolicyAcceptanceTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_marks_one_page_per_document_type(): void
    {
        $this->signIn();
        $terms = $this->postJson('/platform-api/v1/pages', ['slug' => 'terms'])->assertCreated()->json();
        $other = $this->postJson('/platform-api/v1/pages', ['slug' => 'agb'])->assertCreated()->json();
        $home = PublicPage::query()->where('kind', 'home')->value('id');

        $this->patchJson("/platform-api/v1/pages/{$terms['id']}", ['policy_type' => 'service_terms'])->assertOk()->assertJsonPath('policy_type', 'service_terms');
        $this->patchJson("/platform-api/v1/pages/{$other['id']}", ['policy_type' => 'service_terms'])->assertConflict()->assertJsonPath('code', 'policy_taken');
        $this->patchJson("/platform-api/v1/pages/{$home}", ['policy_type' => 'privacy_notice'])->assertUnprocessable()->assertJsonPath('code', 'policy_type_invalid');
        $this->patchJson("/platform-api/v1/pages/{$terms['id']}", ['policy_type' => 'teleport'])->assertUnprocessable();
        $this->postJson('/platform-api/v1/pages', ['slug' => 'signup'])->assertConflict()->assertJsonPath('code', 'slug_taken');

        $this->postJson("/platform-api/v1/pages/{$terms['id']}/archive", ['reason' => 'replaced'])->assertNoContent();
        $this->patchJson("/platform-api/v1/pages/{$other['id']}", ['policy_type' => 'service_terms'])->assertOk();
    }

    public function test_signup_on_the_site_creates_the_account_with_consent_to_the_published_versions(): void
    {
        $terms = $this->document('terms', PublicPage::POLICY_SERVICE_TERMS, ['ru' => 'Условия использования', 'en' => 'Terms of service']);
        $privacy = $this->document('privacy', PublicPage::POLICY_PRIVACY_NOTICE, ['en' => 'Privacy notice']);

        $form = $this->get('/ru/signup?plan=start')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $form->assertSee('data-form="signup"', false)
            ->assertSee('href="/ru/terms"', false)
            ->assertSee('Условия использования')
            ->assertSee('href="/en/privacy"', false)
            ->assertSee('name="plan" value="start"', false)
            ->assertSee('value="'.$terms['ru'].'"', false);
        $this->get('/ru/pricing')->assertOk();

        $body = $this->signup(['policy_version_ids' => [$terms['ru'], $privacy['en']]]);
        $this->post('/ru/signup', $body)->assertSessionHasErrors('accept');
        $this->assertSame(0, User::query()->count());

        $this->post('/ru/signup', $body + ['accept' => '1'])->assertRedirect('/app/settings/billing?plan=start');

        $user = User::query()->sole();
        $tenant = Tenant::query()->sole();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(['anna@shop.test', 'ru', 'Kaffee GmbH', 'Europe/Berlin'], [$user->email, $user->locale, $tenant->name, $tenant->timezone]);
        $rows = DB::table('policy_acceptances')->orderBy('policy_type')->get();
        $this->assertSame([PublicPage::POLICY_PRIVACY_NOTICE, PublicPage::POLICY_SERVICE_TERMS], $rows->pluck('policy_type')->all());
        $this->assertSame([$privacy['en'], $terms['ru']], $rows->pluck('page_version_id')->all());
        $this->assertSame(['signup'], $rows->pluck('purpose')->unique()->values()->all());
        $this->assertSame($tenant->id, $rows[0]->tenant_id);
        $this->get('/ru/signup')->assertRedirect('/app/settings/billing');
    }

    public function test_signup_refuses_a_taken_email_and_an_outdated_version_without_creating_anything(): void
    {
        $terms = $this->document('terms', PublicPage::POLICY_SERVICE_TERMS, ['en' => 'Terms']);
        $this->context();
        $taken = User::query()->value('email');

        $this->post('/en/signup', $this->signup(['email' => $taken, 'accept' => '1', 'policy_version_ids' => [$terms['en']]]))->assertSessionHasErrors('email');
        $this->assertStringContainsString('/app/login', session('errors')->first('email'));

        $page = PublicPage::query()->where('policy_type', PublicPage::POLICY_SERVICE_TERMS)->sole();
        PublicPageVersion::query()->whereKey($terms['en'])->update(['state' => 'retired', 'retired_at' => now()]);
        $this->publish($page, 'en', 'Terms v2', 2);

        $this->post('/en/signup', $this->signup(['accept' => '1', 'policy_version_ids' => [$terms['en']]]))->assertSessionHasErrors('accept');
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, DB::table('policy_acceptances')->count());

        $this->postJson('/api/v1/auth/register', $this->signup(['policy_version_ids' => [$terms['en']], 'locale' => 'en']))->assertUnprocessable()->assertJsonPath('code', 'policy_acceptance_required')->assertJsonPath('details.documents.0.version', 2);
    }

    public function test_without_published_documents_signup_needs_no_consent_and_only_real_attempts_are_limited(): void
    {
        $this->get('/de/signup')->assertOk()->assertDontSee('data-documents', false);

        foreach (range(1, 5) as $attempt) {
            $this->post('/de/signup', $this->signup(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        }

        $this->post('/de/signup', $this->signup(['plan' => null]))->assertRedirect('/app/settings/billing');
        $this->assertSame(1, User::query()->count());
        auth('web')->logout();

        foreach (range(2, 3) as $attempt) {
            $this->post('/de/signup', $this->signup(['email' => "user{$attempt}@shop.test", 'plan' => null]))->assertRedirect('/app/settings/billing');
            auth('web')->logout();
        }

        $this->post('/de/signup', $this->signup(['email' => 'user4@shop.test']))->assertSessionHasErrors('form');
        $this->assertSame(3, User::query()->count());
    }

    public function test_trial_needs_consent_to_the_current_terms_and_subscription_terms_and_records_it(): void
    {
        $terms = $this->document('terms', PublicPage::POLICY_SERVICE_TERMS, ['en' => 'Terms']);
        $recurring = $this->document('subscription-terms', PublicPage::POLICY_RECURRING_PAYMENT, ['en' => 'Subscription terms']);
        $context = $this->context();
        $context['user']->forceFill(['email_verified_at' => now(), 'locale' => 'en'])->save();
        $version = $this->planVersion();
        $request = fn (array $body) => $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/billing/trial', ['plan_version_id' => $version->id] + $body);

        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->getJson('/api/v1/billing/plans')
            ->assertOk()->assertJsonPath('documents.0.type', 'service_terms')->assertJsonPath('documents.1.type', 'recurring_payment')->assertJsonPath('documents.1.url', '/en/subscription-terms');

        $request(['policy_version_ids' => [$terms['en']]])->assertUnprocessable()->assertJsonPath('code', 'policy_acceptance_required')->assertJsonCount(2, 'details.documents');
        $this->assertSame(0, DB::table('subscriptions')->count());

        $request(['policy_version_ids' => [$terms['en'], $recurring['en']]])->assertCreated();
        $rows = DB::table('policy_acceptances')->get();
        $this->assertSame(['trial'], $rows->pluck('purpose')->unique()->values()->all());
        $this->assertSame(DB::table('subscriptions')->value('id'), $rows[0]->context_id);
        $this->assertCount(2, $rows);
    }

    public function test_a_guest_gets_401_from_the_api_without_asking_for_json(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_the_contact_form_links_the_page_marked_as_privacy_notice(): void
    {
        $this->get('/en/contact')->assertDontSee('Privacy policy');
        $this->document('data-protection', PublicPage::POLICY_PRIVACY_NOTICE, ['en' => 'Data protection']);
        $this->get('/en/contact')->assertSee('href="/en/data-protection">Privacy policy</a>', false);
    }

    /**
     * @param  array<string, string>  $titles
     * @return array<string, string>
     */
    private function document(string $slug, string $type, array $titles): array
    {
        $page = PublicPage::query()->create(['kind' => 'page', 'slug' => $slug, 'path' => $slug, 'menu' => 'footer', 'sort' => 0, 'policy_type' => $type]);
        $ids = [];

        foreach ($titles as $locale => $title) {
            $ids[$locale] = $this->publish($page, $locale, $title, 1)->id;
        }

        return $ids;
    }

    private function publish(PublicPage $page, string $locale, string $title, int $version): PublicPageVersion
    {
        return PublicPageVersion::query()->create([
            'page_id' => $page->id, 'locale' => $locale, 'version' => $version, 'title' => $title, 'body' => '<p>'.$title.'</p>',
            'content_hash' => hash('sha256', $title.$version), 'state' => 'published', 'published_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Anna', 'organization_name' => 'Kaffee GmbH', 'email' => 'Anna@Shop.test', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
            'timezone' => 'Europe/Berlin', 'plan' => 'start',
        ], $overrides);
    }

    private function planVersion(): BillingPlanVersion
    {
        $plan = BillingPlan::query()->create(['code' => 'pro', 'state' => BillingPlan::STATE_PUBLISHED]);

        return BillingPlanVersion::query()->create([
            'plan_id' => $plan->id, 'version' => 1, 'state' => BillingPlan::STATE_PUBLISHED, 'localized_content' => ['name' => ['en' => 'Pro']],
            'entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 3]]), 'trial_days' => 14,
        ]);
    }

    private function signIn(): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
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
