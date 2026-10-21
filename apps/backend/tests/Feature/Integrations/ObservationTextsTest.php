<?php

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class ObservationTextsTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    public function test_members_get_the_privacy_paragraph_and_lia_with_the_configured_provider(): void
    {
        config()->set('watchdog.provider.name', 'Ivan Petrenko');
        config()->set('watchdog.provider.address', 'Khreshchatyk 1, 01001 Kyiv, Ukraine');
        $context = $this->context('viewer');

        $response = $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson('/api/v1/downloads/observation-texts')->assertOk()->assertJsonPath('provider_set', true);

        $de = $response->json('texts.de');
        $en = $response->json('texts.en');
        $this->assertStringStartsWith('Funktionsüberwachung des Bestellvorgangs (Business Watchdog)', $de['privacy']);
        $this->assertStringContainsString('von Ivan Petrenko, Khreshchatyk 1, 01001 Kyiv, Ukraine ein.', $de['privacy']);
        $this->assertStringContainsString('auf Servern in Deutschland', $de['privacy']);
        $this->assertStringContainsString('provided by Ivan Petrenko, Khreshchatyk 1, 01001 Kyiv, Ukraine on the checkout', $en['privacy']);
        $this->assertStringContainsString('on servers in Germany', $en['privacy']);
        $this->assertStringStartsWith('Interessenabwägung nach Art. 6 Abs. 1 lit. f DSGVO', $de['lia']);
        $this->assertStringStartsWith('Legitimate interest assessment under Art. 6(1)(f) GDPR', $en['lia']);

        foreach ([$de, $en] as $set) {
            $this->assertStringNotContainsString(':provider', $set['privacy'].$set['lia']);
        }
    }

    public function test_without_a_configured_provider_the_texts_keep_a_placeholder(): void
    {
        config()->set('watchdog.provider.name', '');
        $context = $this->context('viewer');

        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson('/api/v1/downloads/observation-texts')->assertOk()
            ->assertJsonPath('provider_set', false)
            ->assertJsonPath('texts.en.privacy', fn (string $text): bool => str_contains($text, 'provided by [provider name and address] on'));

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/downloads/observation-texts')->assertUnauthorized();
    }
}
