<?php

namespace Tests\Feature\Stores;

use App\Models\AuditLog;
use App\Models\CheckScenario;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Checkout\CheckoutLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class StoreCheckoutLayoutTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    public function test_a_shopware_store_saves_its_checkout_pages_and_elements_and_the_check_opens_its_confirm_page(): void
    {
        $context = $this->context();
        $store = $context['store'];
        $store->forceFill(['platform' => 'shopware', 'base_url' => 'https://shop.example.test'])->save();
        $member = fn () => $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);

        $member()->getJson("/api/v1/stores/{$store->id}")->assertOk()
            ->assertJsonPath('checkout_layout.custom', [])
            ->assertJsonPath('checkout_layout.defaults.submit_button', '#confirmFormSubmit');

        $member()->putJson("/api/v1/stores/{$store->id}/checkout-layout", [
            'confirm_path' => ' /kasse/pruefen/ ',
            'submit_button' => 'button[form="orderForm"].btn-buy',
            'payment_method' => '',
        ])->assertOk()
            ->assertJsonPath('checkout_layout.custom', ['confirm_path' => '/kasse/pruefen', 'submit_button' => 'button[form="orderForm"].btn-buy']);

        $this->assertSame('/kasse/pruefen', CheckoutLayout::effective($store->refresh())['confirm_path']);
        $this->assertSame('/checkout/finish', CheckoutLayout::effective($store)['finish_path']);
        $this->assertSame(1, AuditLog::query()->where('action', AuditLog::ACTION_STORE_CHECKOUT_LAYOUT_SAVED)->count());

        $scenario = CheckScenario::query()->create([
            'tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'name' => 'Payment form', 'mode' => CheckScenario::MODE_PAYMENT_FORM,
            'version' => 1, 'enabled' => true, 'adapter_version' => ScenarioDefinition::ADAPTER_VERSION, 'definition' => ['product_url' => 'https://shop.example.test/p/1'],
            'interval_seconds' => 900, 'next_due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $snapshot = app(ScenarioDefinition::class)->snapshot($scenario, $store);
        $this->assertSame('https://shop.example.test/kasse/pruefen', $snapshot['checkout_url']);
        $this->assertSame(['confirm_path' => '/kasse/pruefen', 'submit_button' => 'button[form="orderForm"].btn-buy'], (array) $snapshot['checkout_layout']);

        $member()->putJson("/api/v1/stores/{$store->id}/checkout-layout", [])->assertOk()->assertJsonPath('checkout_layout.custom', []);
        $this->assertArrayNotHasKey('checkout_layout', $store->refresh()->settings ?? []);
        $this->assertSame('https://shop.example.test/checkout/confirm', app(ScenarioDefinition::class)->snapshot($scenario, $store)['checkout_url']);
    }

    public function test_paths_and_selectors_are_only_data_and_other_platforms_and_roles_are_refused(): void
    {
        $context = $this->context();
        $store = $context['store'];
        $store->forceFill(['platform' => 'shopware'])->save();
        $member = fn () => $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);

        $member()->putJson("/api/v1/stores/{$store->id}/checkout-layout", [
            'confirm_path' => '/../admin',
            'finish_path' => 'https://evil.example/finish',
            'order_form' => '<script>alert(1)</script>',
            'submit_button' => str_repeat('a', 201),
            'error_notice' => 'div{color:red}',
        ])->assertStatus(422)
            ->assertJsonPath('errors.confirm_path.0', 'checkout_layout_path_invalid')
            ->assertJsonPath('errors.finish_path.0', 'checkout_layout_path_invalid')
            ->assertJsonPath('errors.order_form.0', 'checkout_layout_selector_invalid')
            ->assertJsonPath('errors.submit_button.0', 'checkout_layout_too_long')
            ->assertJsonPath('errors.error_notice.0', 'checkout_layout_selector_invalid');
        $member()->putJson("/api/v1/stores/{$store->id}/checkout-layout", ['confirm_path' => '/'])->assertStatus(422);
        $this->assertArrayNotHasKey('checkout_layout', $store->refresh()->settings ?? []);

        $store->forceFill(['platform' => 'woocommerce'])->save();
        $member()->putJson("/api/v1/stores/{$store->id}/checkout-layout", ['confirm_path' => '/checkout'])->assertStatus(422)->assertJsonPath('code', 'checkout_layout_unsupported');
        $member()->getJson("/api/v1/stores/{$store->id}")->assertOk()->assertJsonPath('checkout_layout', null);

        $store->forceFill(['platform' => 'shopware'])->save();
        $viewer = $this->context('viewer', $context['tenant'], $store, $context['integration']);
        $this->actingAs($viewer['user'])->withSession(['active_tenant_id' => $context['tenant']->id])
            ->putJson("/api/v1/stores/{$store->id}/checkout-layout", ['confirm_path' => '/kasse'])->assertForbidden();
    }
}
