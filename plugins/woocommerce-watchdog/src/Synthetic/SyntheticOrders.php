<?php

namespace BusinessWatchdog\WooCommerce\Synthetic;

use BusinessWatchdog\WooCommerce\Storage\State;

final class SyntheticOrders
{
    public const CLEANUP_HOOK = 'bw_synthetic_cleanup';

    public const CLEANUP_INTERVAL_SECONDS = 900;

    public const MIN_AGE_SECONDS = 600;

    public const BATCH = 100;

    public const STATE_LAST = 'last_synthetic_check';

    public static function register(): void
    {
        add_action('init', [self::class, 'observe'], 1, 0);
        add_action('woocommerce_new_order', [self::class, 'markIfSynthetic'], 5, 1);
        add_action('woocommerce_update_order', [self::class, 'markIfSynthetic'], 5, 1);
    }

    public static function observe(): void
    {
        if (! isset($_SERVER[SyntheticRequest::HEADER])) {
            return;
        }

        $synthetic = SyntheticRequest::current();
        $last = State::get(self::STATE_LAST);

        if ($synthetic !== null && (! is_array($last) || ($last['run_id'] ?? null) !== $synthetic['run_id'])) {
            State::set(self::STATE_LAST, ['run_id' => $synthetic['run_id'], 'at' => gmdate('c')]);
        }
    }

    public static function markIfSynthetic($orderId): void
    {
        $synthetic = SyntheticRequest::current();

        if ($synthetic === null) {
            return;
        }

        $order = wc_get_order((int) $orderId);

        if (! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || $order->get_meta(SyntheticRequest::META_KEY) !== '') {
            return;
        }

        $order->update_meta_data(SyntheticRequest::META_KEY, $synthetic['run_id']);
        $order->save_meta_data();
    }

    public static function isSynthetic(\WC_Order $order): bool
    {
        return $order->get_meta(SyntheticRequest::META_KEY) !== '';
    }

    /**
     * Deletes only checkout drafts this plugin marked as created by a verified browser check,
     * through WooCommerce itself. Other drafts and every real order are left untouched.
     */
    public static function cleanup(?int $now = null): int
    {
        $before = ($now ?? time()) - self::MIN_AGE_SECONDS;
        $deleted = 0;
        $drafts = wc_get_orders([
            'type' => 'shop_order',
            'status' => ['checkout-draft'],
            'date_created' => '<' . $before,
            'limit' => self::BATCH,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach ($drafts as $order) {
            if ($order instanceof \WC_Order && $order->get_status('edit') === 'checkout-draft' && self::isSynthetic($order)) {
                $order->delete(true);
                $deleted++;
            }
        }

        return $deleted;
    }
}
