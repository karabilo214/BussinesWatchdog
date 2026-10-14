<?php

namespace App\Support\Providers\WooCommerce;

use Illuminate\Support\Carbon;

/**
 * Maps a WooCommerce REST API order to the same events the WooCommerce plugin sends (`order.snapshot`,
 * `refund.snapshot`, `order.deleted`), so ingestion, projections, matching and reconciliation stay platform-neutral.
 * Only the listed fields are read; billing, shipping, customer and other meta are never kept.
 */
class WooCommerceApiOrderMapper
{
    public const ORDER_FIELDS = 'id,number,status,currency,total,payment_method,transaction_id,order_key,created_via,date_created_gmt,date_modified_gmt,date_paid_gmt,refunds,meta_data';

    public const REFUND_FIELDS = 'id,amount,date_created_gmt,refunded_payment,meta_data';

    public const SKIPPED_STATUSES = ['checkout-draft', 'auto-draft'];

    /** Refund meta where a gateway stores the provider refund id (confirmed: WooCommerce Stripe Gateway 11.0.1). */
    private const PROVIDER_REFUND_ID_META = ['_stripe_refund_id'];

    public static function supportedGateway(string $gateway): bool
    {
        return $gateway === 'stripe' || str_starts_with($gateway, 'stripe_') || str_starts_with($gateway, 'ppcp-');
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{draft: ?array<string, mixed>, skipped: ?string}
     */
    public function order(array $order): array
    {
        $id = $this->id($order['id'] ?? null);
        $status = is_string($order['status'] ?? null) ? $order['status'] : '';

        if ($id === null || $status === '') {
            return ['draft' => null, 'skipped' => 'order_invalid'];
        }

        if (in_array($status, self::SKIPPED_STATUSES, true)) {
            return ['draft' => null, 'skipped' => 'draft_order'];
        }

        $currency = strtoupper((string) ($order['currency'] ?? ''));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return ['draft' => null, 'skipped' => 'currency_invalid'];
        }

        $exponent = WooCommerceAmount::exponent($currency);
        $total = WooCommerceAmount::toMinor($order['total'] ?? null, $exponent);

        if ($total === null) {
            return ['draft' => null, 'skipped' => 'amount_not_representable'];
        }

        $gateway = is_string($order['payment_method'] ?? null) ? $order['payment_method'] : '';
        $transactionRef = is_string($order['transaction_id'] ?? null) ? trim($order['transaction_id']) : '';
        $meta = $this->meta($order['meta_data'] ?? null);
        $created = $this->time($order['date_created_gmt'] ?? null);
        $modified = $this->time($order['date_modified_gmt'] ?? null);

        $data = [
            'status' => substr($status, 0, 64),
            'currency' => $currency,
            'currency_exponent' => $exponent,
            'total_minor' => $total,
            'gateway' => $gateway !== '' ? substr($gateway, 0, 255) : null,
            'transaction_ref' => $transactionRef !== '' ? substr($transactionRef, 0, 255) : null,
            'payment_expected' => $total !== '0',
            'paid_marked_at' => $this->time($order['date_paid_gmt'] ?? null),
            'financial_support' => $gateway === '' ? 'unknown' : (self::supportedGateway($gateway) ? 'supported' : 'unsupported'),
        ];

        $number = is_scalar($order['number'] ?? null) ? trim((string) $order['number']) : '';

        if ($number !== '') {
            $data['display_number'] = substr($number, 0, 255);
        }

        $mode = $this->mode($gateway, $meta);

        if ($mode !== null) {
            $data['mode'] = $mode;
        }

        if (is_string($order['order_key'] ?? null) && $order['order_key'] !== '') {
            $data['order_key_hash'] = hash('sha256', $order['order_key']);
        }

        if ($created !== null) {
            $data['source_created_at'] = $created;
        }

        if ($modified !== null || $created !== null) {
            $data['source_updated_at'] = $modified ?? $created;
        }

        return ['draft' => [
            'type' => 'order.snapshot',
            'aggregate_type' => 'order',
            'aggregate_id' => $id,
            'created' => Carbon::parse($modified ?? $created ?? 'now')->getTimestamp(),
            'data' => $data,
            'revisioned' => true,
        ], 'skipped' => null];
    }

    /**
     * @param  array<string, mixed>  $refund
     * @return array{draft: ?array<string, mixed>, skipped: ?string}
     */
    public function refund(array $refund, string $orderId, string $currency): array
    {
        $id = $this->id($refund['id'] ?? null);
        $exponent = WooCommerceAmount::exponent($currency);
        $amount = WooCommerceAmount::toMinor($refund['amount'] ?? null, $exponent, absolute: true);

        if ($id === null) {
            return ['draft' => null, 'skipped' => 'refund_invalid'];
        }

        if ($amount === null) {
            return ['draft' => null, 'skipped' => 'amount_not_representable'];
        }

        $meta = $this->meta($refund['meta_data'] ?? null);
        $providerRef = null;

        foreach (self::PROVIDER_REFUND_ID_META as $key) {
            if (is_string($meta[$key] ?? null) && trim($meta[$key]) !== '') {
                $providerRef = substr(trim($meta[$key]), 0, 255);
                break;
            }
        }

        $created = $this->time($refund['date_created_gmt'] ?? null);

        return ['draft' => $this->refundDraft($id, [
            'order_id' => $orderId,
            'currency' => $currency,
            'currency_exponent' => $exponent,
            'amount_minor' => $amount,
            'external_required' => is_bool($refund['refunded_payment'] ?? null) ? $refund['refunded_payment'] : null,
            'provider_ref' => $providerRef,
            'status' => 'recorded',
        ], $created), 'skipped' => null];
    }

    /**
     * A refund that is gone from its order: the last known content with status `deleted`, as the plugin sends it.
     *
     * @param  array<string, mixed>  $lastData
     * @return array<string, mixed>
     */
    public function deletedRefund(string $refundId, array $lastData): array
    {
        return $this->refundDraft($refundId, [...$lastData, 'status' => 'deleted'], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function deletedOrder(string $orderId): array
    {
        return [
            'type' => 'order.deleted',
            'aggregate_type' => 'order',
            'aggregate_id' => $orderId,
            'created' => Carbon::now()->getTimestamp(),
            'data' => ['reason_code' => 'deleted'],
            'revisioned' => true,
        ];
    }

    /**
     * Refund ids and totals from the order's own `refunds` summary, used to decide whether refunds must be re-read.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, string>
     */
    public function refundSummary(array $order): array
    {
        $summary = [];

        foreach (is_array($order['refunds'] ?? null) ? $order['refunds'] : [] as $refund) {
            $id = is_array($refund) ? $this->id($refund['id'] ?? null) : null;

            if ($id !== null) {
                $summary[$id] = is_scalar($refund['total'] ?? null) ? (string) $refund['total'] : '';
            }
        }

        ksort($summary);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function refundDraft(string $id, array $data, ?string $created): array
    {
        return [
            'type' => 'refund.snapshot',
            'aggregate_type' => 'refund',
            'aggregate_id' => $id,
            'created' => Carbon::parse($created ?? 'now')->getTimestamp(),
            'data' => $data,
            'revisioned' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function mode(string $gateway, array $meta): ?string
    {
        $recorded = $meta['_bw_payment_mode'] ?? null;

        if ($recorded === 'live' || $recorded === 'test') {
            return $recorded;
        }

        if (str_starts_with($gateway, 'ppcp-')) {
            return match ($meta['_ppcp_paypal_payment_mode'] ?? null) {
                'sandbox' => 'test',
                'live' => 'live',
                default => null,
            };
        }

        return null;
    }

    /**
     * Only the meta keys the mapping needs; everything else (possibly personal data) is dropped here.
     *
     * @return array<string, mixed>
     */
    private function meta(mixed $meta): array
    {
        $wanted = ['_bw_payment_mode', '_ppcp_paypal_payment_mode', ...self::PROVIDER_REFUND_ID_META];
        $out = [];

        foreach (is_array($meta) ? $meta : [] as $item) {
            if (is_array($item) && in_array($item['key'] ?? null, $wanted, true) && is_scalar($item['value'] ?? null)) {
                $out[$item['key']] = (string) $item['value'];
            }
        }

        return $out;
    }

    private function id(mixed $value): ?string
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]{0,19}$/', $value) === 1) ? (string) $value : null;
    }

    private function time(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value.'Z';
    }
}
