<?php

namespace App\Support\Browser;

use App\Models\CheckScenario;
use App\Models\Integration;
use App\Models\Store;
use App\Support\Checkout\CheckoutLayout;
use App\Support\Checkout\CheckoutObservation;

class ScenarioDefinition
{
    public const ADAPTER_VERSION = 'woocommerce-payment-form@1';

    public const SHOPWARE_ADAPTER_VERSION = 'shopware-payment-form@1';

    /** Platforms the worker has a checkout adapter for (ADR 0055). */
    public const ADAPTERS = [
        'woocommerce' => self::ADAPTER_VERSION,
        'shopware' => self::SHOPWARE_ADAPTER_VERSION,
    ];

    /** Where a platform shows payment methods only to a signed-in or registered customer. */
    public const SIGN_IN_PLATFORMS = ['shopware'];

    public const NETWORK_POLICY_VERSION = 'v1';

    /** The Shopware app's storefront endpoint for checkout observation reports (ADR 0062): the check never calls it. */
    public const SHOPWARE_OBSERVATION_PATH = '/storefront/script/bw-observe';

    /** The WooCommerce plugin's observation beacons (ADR 0059), as REST routes; the worker also matches `?rest_route=`. */
    public const WOOCOMMERCE_OBSERVATION_PATHS = ['/business-watchdog/v1/observe', '/business-watchdog/v1/observe/noscript'];

    /**
     * Actions that mutate an order or money. The worker adapter maps each to concrete
     * requests (Classic wc-ajax checkout, Store API checkout, order-pay, gateway capture)
     * and blocks them at the network layer; an unknown payment mutation is blocked too.
     */
    public const BLOCKED_OPERATIONS = [
        'place_order',
        'classic_checkout_submit',
        'store_api_checkout_submit',
        'order_pay_submit',
        'payment_capture',
        'refund',
        'unknown_payment_mutation',
    ];

    public const SHOPWARE_BLOCKED_OPERATIONS = [
        'place_order',
        'order_pay_submit',
        'payment_capture',
        'refund',
        'customer_account_creation',
        'unknown_payment_mutation',
    ];

    public function adapterFor(Store $store): ?string
    {
        return self::ADAPTERS[$store->platform] ?? null;
    }

    public function usesCheckoutAccess(Store $store): bool
    {
        return in_array($store->platform, self::SIGN_IN_PLATFORMS, true);
    }

    public function checkoutAccess(CheckScenario $scenario, Store $store): ?string
    {
        if (! $this->usesCheckoutAccess($store)) {
            return null;
        }

        return in_array($scenario->checkout_access, CheckScenario::ACCESSES, true) ? $scenario->checkout_access : CheckScenario::ACCESS_STOP_BEFORE_SIGN_IN;
    }

    /**
     * @return list<array{code: string, action: string, timeout_ms: int, target?: string}>
     */
    public function steps(string $platform = 'woocommerce', ?string $access = null): array
    {
        if ($platform !== 'shopware') {
            return [
                ['code' => 'product', 'action' => 'goto', 'target' => 'product_url', 'timeout_ms' => 30000],
                ['code' => 'add_to_cart', 'action' => 'click_safe', 'timeout_ms' => 20000],
                ['code' => 'product_again', 'action' => 'goto', 'target' => 'product_url', 'timeout_ms' => 20000],
                ['code' => 'cart', 'action' => 'assert_cart', 'target' => 'cart_url', 'timeout_ms' => 30000],
                ['code' => 'checkout', 'action' => 'goto', 'target' => 'checkout_url', 'timeout_ms' => 30000],
                ['code' => 'shipping', 'action' => 'fill_synthetic', 'timeout_ms' => 20000],
                ['code' => 'payment_form', 'action' => 'assert_visible', 'timeout_ms' => 20000],
            ];
        }

        $steps = [
            ['code' => 'product', 'action' => 'goto', 'target' => 'product_url', 'timeout_ms' => 30000],
            ['code' => 'add_to_cart', 'action' => 'click_safe', 'timeout_ms' => 20000],
            ['code' => 'product_again', 'action' => 'goto', 'target' => 'product_url', 'timeout_ms' => 20000],
            ['code' => 'cart', 'action' => 'assert_cart', 'target' => 'cart_url', 'timeout_ms' => 30000],
            ['code' => 'checkout', 'action' => 'goto', 'target' => 'checkout_url', 'timeout_ms' => 30000],
        ];

        return match ($access) {
            CheckScenario::ACCESS_TEST_CUSTOMER => [...$steps,
                ['code' => 'sign_in', 'action' => 'sign_in', 'timeout_ms' => 30000],
                ['code' => 'shipping', 'action' => 'assert_visible', 'timeout_ms' => 20000],
                ['code' => 'payment_form', 'action' => 'assert_visible', 'timeout_ms' => 20000],
            ],
            CheckScenario::ACCESS_GUEST => [...$steps,
                ['code' => 'guest_details', 'action' => 'fill_synthetic', 'timeout_ms' => 30000],
                ['code' => 'shipping', 'action' => 'assert_visible', 'timeout_ms' => 20000],
                ['code' => 'payment_form', 'action' => 'assert_visible', 'timeout_ms' => 20000],
            ],
            default => $steps,
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function untestedComponents(string $platform = 'woocommerce', ?string $access = null, array $definition = []): array
    {
        if ($platform === 'shopware') {
            return $access === CheckScenario::ACCESS_STOP_BEFORE_SIGN_IN
                ? ['payment_methods_stop_before_sign_in', 'redirect_gateways']
                : [($definition['load_payment_providers'] ?? false) ? 'provider_form_fields_shopware' : 'provider_forms_not_loaded', 'redirect_gateways'];
        }

        return [
            ...(empty($definition['synthetic_location']) ? ['shipping_required_before_payment'] : []),
            ($definition['load_payment_providers'] ?? false) ? 'provider_form_contents' : 'provider_forms_not_loaded',
            'redirect_gateways',
        ];
    }

    /**
     * @return list<string>
     */
    public function blockedOperations(string $platform): array
    {
        return $platform === 'shopware' ? self::SHOPWARE_BLOCKED_OPERATIONS : self::BLOCKED_OPERATIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(CheckScenario $scenario, Store $store): array
    {
        $definition = $scenario->definition ?? [];
        $origin = $this->origin($store->base_url);
        $shopware = $store->platform === 'shopware';
        $access = $this->checkoutAccess($scenario, $store);

        return [
            'version' => $scenario->version,
            'adapter_version' => $this->adapterFor($store) ?? $scenario->adapter_version,
            'platform' => $store->platform,
            'mode' => $scenario->mode,
            'checkout_access' => $access,
            'store_origin' => $origin,
            'product_url' => $definition['product_url'],
            'cart_url' => $definition['cart_url'] ?? $origin.($shopware ? '/checkout/cart' : '/cart/'),
            'checkout_url' => $definition['checkout_url'] ?? $origin.($shopware ? CheckoutLayout::effective($store)['confirm_path'] : '/checkout/'),
            'synthetic_location' => $definition['synthetic_location'] ?? null,
            'expect_checkout_script' => $this->expectsCheckoutScript($store, $access),
            'checkout_layout' => $shopware ? (object) CheckoutLayout::custom($store) : null,
            'steps' => $this->steps($store->platform, $access),
            'network_policy' => [
                'version' => self::NETWORK_POLICY_VERSION,
                'allowed_origins' => array_values(array_unique(array_merge([$origin], $definition['extra_allowed_origins'] ?? []))),
                'blocked_operations' => $this->blockedOperations($store->platform),
                'quiet_paths' => $shopware ? [self::SHOPWARE_OBSERVATION_PATH] : self::WOOCOMMERCE_OBSERVATION_PATHS,
                'payment_providers' => (bool) ($definition['load_payment_providers'] ?? false),
            ],
        ];
    }

    /**
     * The checkout observation script is expected on the payment page when the store is observed: the Shopware app with
     * a check that reaches that page (ADR 0057/0062) or the WooCommerce plugin (ADR 0059).
     */
    public function expectsCheckoutScript(Store $store, ?string $access): bool
    {
        if ($store->platform === 'shopware' && ! in_array($access, [CheckScenario::ACCESS_TEST_CUSTOMER, CheckScenario::ACCESS_GUEST], true)) {
            return false;
        }

        $observation = app(CheckoutObservation::class);

        return Integration::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('status', Integration::STATUS_ACTIVE)
            ->get()
            ->contains(fn (Integration $integration): bool => CheckoutObservation::carriesScript($integration) && $observation->decide($integration->id)['observe']);
    }

    public function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')).$port;
    }
}
