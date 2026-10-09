<?php

namespace BusinessWatchdog\WooCommerce\Capture;

use BusinessWatchdog\WooCommerce\Compat\OrderCacheBuster;
use BusinessWatchdog\WooCommerce\Storage\Outbox;
use BusinessWatchdog\WooCommerce\Storage\Revisions;
use BusinessWatchdog\WooCommerce\Storage\State;

final class OrderCapture
{
    public const STATE_LAST_ERROR = 'last_capture_error';

    private static array $dirtyOrders = [];

    private static bool $shutdownRegistered = false;

    private static ?bool $capturing = null;

    private static $afterFlush = null;

    public static function onRecorded(callable $callback): void
    {
        self::$afterFlush = $callback;
    }

    public static function capturing(): bool
    {
        if (self::$capturing === null) {
            self::$capturing = \BusinessWatchdog\WooCommerce\Connection\Connection::current() !== null;
        }

        return self::$capturing;
    }

    public static function resetCapturingCache(): void
    {
        self::$capturing = null;
    }

    public static function markDirty($orderId): void
    {
        $orderId = (int) $orderId;

        if ($orderId <= 0 || ! self::capturing()) {
            return;
        }

        self::$dirtyOrders[$orderId] = true;

        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            add_action('shutdown', [self::class, 'flush'], 5);
        }
    }

    public static function pendingIds(): array
    {
        return array_keys(self::$dirtyOrders);
    }

    public static function flush(): int
    {
        $ids = array_keys(self::$dirtyOrders);
        self::$dirtyOrders = [];
        $recorded = 0;

        foreach ($ids as $id) {
            try {
                $recorded += self::captureById($id);
            } catch (\Throwable $exception) {
                self::recordError($id, $exception);
            }
        }

        if ($recorded > 0 && self::$afterFlush !== null) {
            (self::$afterFlush)();
        }

        return $recorded;
    }

    public static function captureById(int $id): int
    {
        OrderCacheBuster::forget($id);
        $object = wc_get_order($id);

        if (! $object) {
            return 0;
        }

        if ($object instanceof \WC_Order_Refund) {
            OrderCacheBuster::forget((int) $object->get_parent_id());
            $parent = wc_get_order($object->get_parent_id());

            return $parent instanceof \WC_Order ? self::captureOrder($parent) : 0;
        }

        return $object instanceof \WC_Order ? self::captureOrder($object) : 0;
    }

    public static function captureOrder(\WC_Order $order): int
    {
        if (in_array($order->get_status('edit'), OrderSnapshotBuilder::SKIPPED_STATUSES, true)) {
            return 0;
        }

        $orderKey = self::orderKey($order->get_id());
        $data = OrderSnapshotBuilder::build($order);
        $recorded = self::record('order.snapshot', 'order', (string) $order->get_id(), $orderKey, null, $data, $data['source_updated_at'] ?? null);

        $seenRefundKeys = [];

        foreach (self::currentRefunds($order) as $refund) {
            if (! $refund instanceof \WC_Order_Refund) {
                continue;
            }

            $refundKey = self::refundKey($refund->get_id());
            $seenRefundKeys[$refundKey] = true;
            $refundData = RefundSnapshotBuilder::build($refund, $order);
            $recorded += self::record('refund.snapshot', 'refund', (string) $refund->get_id(), $refundKey, $orderKey, $refundData, EventFactory::isoDate($refund->get_date_created('edit')));
        }

        foreach (Revisions::liveChildren($orderKey) as $refundKey => $lastData) {
            if (isset($seenRefundKeys[$refundKey]) || ! is_array($lastData)) {
                continue;
            }

            $lastData['status'] = 'deleted';
            $recorded += self::recordDeletion('refund.snapshot', 'refund', substr($refundKey, strlen('refund:')), $refundKey, $orderKey, $lastData);
        }

        return $recorded;
    }

    public static function captureDeletion($orderId, string $reasonCode): int
    {
        $orderId = (int) $orderId;

        if ($orderId <= 0 || ! self::capturing() || Revisions::find(self::orderKey($orderId)) === null) {
            return 0;
        }

        try {
            return self::recordDeletion('order.deleted', 'order', (string) $orderId, self::orderKey($orderId), null, ['reason_code' => $reasonCode]);
        } catch (\Throwable $exception) {
            self::recordError($orderId, $exception);

            return 0;
        }
    }

    public static function currentRefunds(\WC_Order $order): array
    {
        return (array) wc_get_orders([
            'type' => 'shop_order_refund',
            'parent' => $order->get_id(),
            'limit' => -1,
        ]);
    }

    public static function orderKey(int $orderId): string
    {
        return 'order:' . $orderId;
    }

    public static function refundKey(int $refundId): string
    {
        return 'refund:' . $refundId;
    }

    private static function record(string $type, string $aggregateType, string $aggregateId, string $key, ?string $parentKey, array $data, ?string $occurredAt): int
    {
        $revision = Revisions::observe($key, hash('sha256', (string) wp_json_encode($data)), $parentKey, $data);

        if ($revision === null) {
            return 0;
        }

        Outbox::enqueue(EventFactory::envelope($type, $aggregateType, $aggregateId, $revision, $occurredAt, $data), $key);

        return 1;
    }

    private static function recordDeletion(string $type, string $aggregateType, string $aggregateId, string $key, ?string $parentKey, array $data): int
    {
        $revision = Revisions::observe($key, Revisions::DELETED_HASH, $parentKey, $data);

        if ($revision === null) {
            return 0;
        }

        Outbox::enqueue(EventFactory::envelope($type, $aggregateType, $aggregateId, $revision, null, $data), $key);

        return 1;
    }

    public static function reportError(int $orderId, \Throwable $exception): void
    {
        self::recordError($orderId, $exception);
    }

    private static function recordError(int $orderId, \Throwable $exception): void
    {
        $code = $exception instanceof SnapshotUnavailable ? $exception->getMessage() : 'capture_failed';

        try {
            State::set(self::STATE_LAST_ERROR, [
                'at' => gmdate('c'),
                'order_id' => $orderId,
                'code' => $code,
            ]);
        } catch (\Throwable $ignored) {
            error_log('business-watchdog: capture error ' . $code . ' for order ' . $orderId);
        }
    }
}
