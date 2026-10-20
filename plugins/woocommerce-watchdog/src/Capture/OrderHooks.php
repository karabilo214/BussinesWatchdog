<?php

namespace BusinessWatchdog\WooCommerce\Capture;

use BusinessWatchdog\WooCommerce\Safety\Guard;

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
            add_action($hook, Guard::action(static function (...$args) use ($argPosition): void {
                OrderCapture::markDirty($args[$argPosition - 1] ?? 0);
            }, 'order_dirty'), 20, $argPosition);
        }

        foreach (self::BLOCKS_CHECKOUT_HOOKS as $hook) {
            add_action($hook, Guard::action([self::class, 'onBlocksCheckout'], 'blocks_checkout'), 20, 1);
        }

        PaymentMode::register();

        add_action('woocommerce_before_delete_order', Guard::action([self::class, 'onBeforeDeleteOrder'], 'before_delete_order'), 10, 2);
        add_filter('woocommerce_pre_delete_order_refund', Guard::filter([self::class, 'onPreDeleteRefund'], 'pre_delete_refund'), 10, 2);
        add_action('woocommerce_delete_order', Guard::action([self::class, 'onDeleteOrder'], 'delete_order'), 10, 1);
        add_action('woocommerce_delete_order_refund', Guard::action([self::class, 'onDeleteRefund'], 'delete_refund'), 10, 1);
        add_action('woocommerce_before_delete_order_refund', Guard::action([self::class, 'onDeleteRefund'], 'before_delete_refund'), 10, 1);
        add_action('before_delete_post', Guard::action([self::class, 'onLegacyDeletePost'], 'legacy_delete'), 10, 1);
        add_action('wp_trash_post', Guard::action([self::class, 'onLegacyTrashPost'], 'legacy_trash'), 10, 1);
        add_action('untrashed_post', Guard::action([self::class, 'onLegacyTrashPost'], 'legacy_untrash'), 10, 1);
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
