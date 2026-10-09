<?php

namespace App\Support\Browser;

use App\Models\CheckScenario;
use App\Models\Store;

class ScenarioDefinition
{
    public const ADAPTER_VERSION = 'woocommerce-payment-form@1';

    public const NETWORK_POLICY_VERSION = 'v1';

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

    /**
     * @return list<array{code: string, action: string, timeout_ms: int, target?: string}>
     */
    public function steps(): array
    {
        return [
            ['code' => 'product', 'action' => 'goto', 'target' => 'product_url', 'timeout_ms' => 30000],
            ['code' => 'add_to_cart', 'action' => 'click_safe', 'timeout_ms' => 20000],
            ['code' => 'cart', 'action' => 'assert_cart', 'target' => 'cart_url', 'timeout_ms' => 30000],
            ['code' => 'checkout', 'action' => 'goto', 'target' => 'checkout_url', 'timeout_ms' => 30000],
            ['code' => 'shipping', 'action' => 'fill_synthetic', 'timeout_ms' => 20000],
            ['code' => 'payment_form', 'action' => 'assert_visible', 'timeout_ms' => 20000],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(CheckScenario $scenario, Store $store): array
    {
        $definition = $scenario->definition ?? [];
        $origin = $this->origin($store->base_url);

        return [
            'version' => $scenario->version,
            'adapter_version' => $scenario->adapter_version,
            'mode' => $scenario->mode,
            'store_origin' => $origin,
            'product_url' => $definition['product_url'],
            'cart_url' => $definition['cart_url'] ?? $origin.'/cart/',
            'checkout_url' => $definition['checkout_url'] ?? $origin.'/checkout/',
            'synthetic_location' => $definition['synthetic_location'] ?? null,
            'steps' => $this->steps(),
            'network_policy' => [
                'version' => self::NETWORK_POLICY_VERSION,
                'allowed_origins' => array_values(array_unique(array_merge([$origin], $definition['extra_allowed_origins'] ?? []))),
                'blocked_operations' => self::BLOCKED_OPERATIONS,
            ],
        ];
    }

    public function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')).$port;
    }
}
