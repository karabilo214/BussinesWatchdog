<?php

namespace BusinessWatchdog\WooCommerce\Capture;

final class OrderHooks
{
    private const DIRTY_ORDER_HOOKS = [
        'woocommerce_new_order' => 1,
        'woocommerce_update_order' => 1,
        'woocommerce_order_status_changed' => 1,
        'woocommerce_payment_complete' => 1,
        'woocommerce_order_refunded' => 1,
        'woocommerce_refund_created' => 1,
        'woocommerce_refund_deleted' => 2,
        'woocommerce_untrash_order' => 1,
        'woocommerce_trash_order' => 1,
    ];

    private const BLOCKS_CHECKOUT_HOOKS = [
        'woocommerce_store_api_checkout_order_processed',
        'woocommerce_blocks_checkout_order_processed',
    ];

    private const LEGACY_ORDER_POST_TYPES = ['shop_order', 'shop_order_refund'];

    public static function register(): void
    {
        foreach (self::DIRTY_ORDER_HOOKS as $hook => $argPosition) {
            add_action($hook, static function (...$args) use ($argPosition): void {
                OrderCapture::markDirty($args[$argPosition - 1] ?? 0);
            }, 20, $argPosition);
        }

        foreach (self::BLOCKS_CHECKOUT_HOOKS as $hook) {
            add_action($hook, [self::class, 'onBlocksCheckout'], 20, 1);
        }

        PaymentMode::register();

        add_action('woocommerce_before_delete_order', [self::class, 'onBeforeDeleteOrder'], 10, 2);
        add_filter('woocommerce_pre_delete_order_refund', [self::class, 'onPreDeleteRefund'], 10, 2);
        add_action('woocommerce_delete_order', [self::class, 'onDeleteOrder'], 10, 1);
        add_action('woocommerce_delete_order_refund', [self::class, 'onDeleteRefund'], 10, 1);
        add_action('woocommerce_before_delete_order_refund', [self::class, 'onDeleteRefund'], 10, 1);
        add_action('before_delete_post', [self::class, 'onLegacyDeletePost'], 10, 1);
        add_action('wp_trash_post', [self::class, 'onLegacyTrashPost'], 10, 1);
        add_action('untrashed_post', [self::class, 'onLegacyTrashPost'], 10, 1);
    }

    public static function onBlocksCheckout($order): void
    {
        if (is_object($order) && method_exists($order, 'get_id')) {
            OrderCapture::markDirty($order->get_id());
        }
    }

    public static function onBeforeDeleteOrder($orderId, $order = null): void
    {
        if ($order instanceof \WC_Order_Refund) {
            OrderCapture::markDirty($order->get_parent_id());
        }
    }

    public static function onPreDeleteRefund($check, $refund = null)
    {
        if ($refund instanceof \WC_Order_Refund) {
            OrderCapture::markDirty($refund->get_parent_id());
        }

        return $check;
    }

    public static function onDeleteOrder($orderId): void
    {
        if (\BusinessWatchdog\WooCommerce\Storage\Revisions::find(OrderCapture::refundKey((int) $orderId)) !== null) {
            self::onDeleteRefund($orderId);

            return;
        }

        OrderCapture::captureDeletion($orderId, 'deleted');
    }

    public static function onDeleteRefund($refundId): void
    {
        $known = \BusinessWatchdog\WooCommerce\Storage\Revisions::find(OrderCapture::refundKey((int) $refundId));

        if ($known !== null && is_string($known['parent_key']) && strpos($known['parent_key'], 'order:') === 0) {
            OrderCapture::markDirty((int) substr($known['parent_key'], strlen('order:')));
        }
    }

    public static function onLegacyDeletePost($postId): void
    {
        $type = get_post_type($postId);

        if ($type === 'shop_order') {
            OrderCapture::captureDeletion($postId, 'deleted');
        } elseif ($type === 'shop_order_refund') {
            OrderCapture::markDirty(wp_get_post_parent_id($postId));
        }
    }

    public static function onLegacyTrashPost($postId): void
    {
        if (in_array(get_post_type($postId), self::LEGACY_ORDER_POST_TYPES, true)) {
            OrderCapture::markDirty($postId);
        }
    }
}
