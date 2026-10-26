<?php

namespace Tests\Feature\Account;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\PersonalName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PersonalNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_registration_stores_salutation_names_and_company_and_shows_the_full_name(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration([
            'salutation' => 'ms', 'first_name' => ' Anna ', 'last_name' => 'Müller', 'company_name' => 'Kaffee Müller GmbH',
        ]))
            ->assertCreated()
            ->assertJsonPath('user.name', 'Anna Müller')
            ->assertJsonPath('user.salutation', 'ms')
            ->assertJsonPath('user.first_name', 'Anna')
            ->assertJsonPath('user.last_name', 'Müller')
            ->assertJsonPath('user.company_name', 'Kaffee Müller GmbH');

        $this->assertSame('Demo Store', Tenant::query()->value('name'));
    }

    public function test_only_the_last_name_is_required_and_the_salutation_is_one_of_the_known_values(): void
    {
        config(['watchdog.rate_limits.signup_per_hour' => 10]);

        $this->postJson('/api/v1/auth/register', $this->registration(['first_name' => 'Anna']))
            ->assertUnprocessable()->assertJsonValidationErrors(['last_name']);
        $this->postJson('/api/v1/auth/register', $this->registration(['last_name' => '   ']))
            ->assertUnprocessable()->assertJsonValidationErrors(['last_name']);
        $this->postJson('/api/v1/auth/register', $this->registration(['last_name' => 'Müller', 'salutation' => 'dr']))
            ->assertUnprocessable()->assertJsonValidationErrors(['salutation']);
        $this->assertSame(0, User::query()->count());

        $this->postJson('/api/v1/auth/register', $this->registration(['last_name' => 'Müller', 'first_name' => '', 'company_name' => '']))
            ->assertCreated()
            ->assertJsonPath('user.name', 'Müller')
            ->assertJsonPath('user.salutation', null)
            ->assertJsonPath('user.first_name', null)
            ->assertJsonPath('user.company_name', null);
    }

    public function test_an_older_client_sending_only_name_gets_it_as_the_last_name_without_splitting(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration(['name' => 'Anna Maria Müller']))
            ->assertCreated()
            ->assertJsonPath('user.name', 'Anna Maria Müller')
            ->assertJsonPath('user.first_name', null)
            ->assertJsonPath('user.last_name', 'Anna Maria Müller');

        $user = User::query()->create(['name' => 'Legacy Writer', 'email' => 'legacy@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);

        $this->assertSame('Legacy Writer', $user->last_name);
        $this->assertNull($user->first_name);
    }

    public function test_profile_changes_only_the_fields_sent_and_never_touches_the_organization(): void
    {
        $user = User::query()->create(['last_name' => 'Müller', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'de']);
        $tenant = Tenant::query()->create(['name' => 'Demo', 'timezone' => 'Europe/Berlin']);
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)->patchJson('/api/v1/auth/me', ['salutation' => 'mr', 'first_name' => 'Jonas', 'company_name' => 'Müller KG'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Jonas Müller')
            ->assertJsonPath('user.salutation', 'mr')
            ->assertJsonPath('user.last_name', 'Müller')
            ->assertJsonPath('user.company_name', 'Müller KG')
            ->assertJsonPath('user.locale', 'de');

        $this->actingAs($user)->patchJson('/api/v1/auth/me', ['last_name' => ''])->assertUnprocessable()->assertJsonValidationErrors(['last_name']);
        $this->actingAs($user)->patchJson('/api/v1/auth/me', ['salutation' => 'mrs'])->assertUnprocessable()->assertJsonValidationErrors(['salutation']);

        $this->actingAs($user)->patchJson('/api/v1/auth/me', ['salutation' => null, 'first_name' => '', 'last_name' => 'Schmidt', 'company_name' => ' '])
            ->assertOk()
            ->assertJsonPath('user.name', 'Schmidt')
            ->assertJsonPath('user.salutation', null)
            ->assertJsonPath('user.first_name', null)
            ->assertJsonPath('user.company_name', null);

        $this->assertSame('Demo', $tenant->refresh()->name);
    }

    public function test_the_site_signup_form_takes_the_same_fields(): void
    {
        $this->get('/de/signup')->assertOk()->assertSee('name="salutation"', false)->assertSee('Nachname')->assertSee('Keine Anrede');

        $this->post('/de/signup', $this->registration(['first_name' => 'Anna']))->assertSessionHasErrors('last_name');
        $this->assertSame(0, User::query()->count());

        $this->post('/de/signup', $this->registration(['salutation' => 'diverse', 'first_name' => 'Alex', 'last_name' => 'Müller', 'company_name' => '']))
            ->assertRedirect();

        $user = User::query()->firstOrFail();
        $this->assertSame(['diverse', 'Alex', 'Müller', null, 'Alex Müller'], [$user->salutation, $user->first_name, $user->last_name, $user->company_name, $user->name]);
    }

    public function test_greeting_uses_herr_or_frau_only_when_chosen_and_otherwise_the_full_name(): void
    {
        $person = fn (?string $salutation, ?string $first = 'Alex'): User => new User(['salutation' => $salutation, 'first_name' => $first, 'last_name' => 'Müller', 'name' => User::displayName($first, 'Müller')]);

        $this->assertSame('Sehr geehrte Frau Müller,', PersonalName::greeting($person('ms'), 'de'));
        $this->assertSame('Sehr geehrter Herr Müller,', PersonalName::greeting($person('mr'), 'de'));
        $this->assertSame('Guten Tag Alex Müller,', PersonalName::greeting($person('diverse'), 'de'));
        $this->assertSame('Guten Tag Alex Müller,', PersonalName::greeting($person(null), 'de'));
        $this->assertSame('Guten Tag Müller,', PersonalName::greeting($person(null, null), 'de'));
        $this->assertSame('Dear Ms Müller,', PersonalName::greeting($person('ms'), 'en'));
        $this->assertSame('Hello Alex Müller,', PersonalName::greeting($person('diverse'), 'en'));
        $this->assertSame('Уважаемый господин Müller!', PersonalName::greeting($person('mr'), 'ru'));
        $this->assertSame('Здравствуйте, Alex Müller!', PersonalName::greeting($person(null), 'ru'));
    }

    /**
     * @param  array<string, mixed>  $name
     * @return array<string, mixed>
     */
    private function registration(array $name): array
    {
        return $name + [
            'email' => 'owner@example.test',
            'password' => 'very-secure-password',
            'password_confirmation' => 'very-secure-password',
            'organization_name' => 'Demo Store',
            'timezone' => 'Europe/Berlin',
            'locale' => 'de',
        ];
    }
}
