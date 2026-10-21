<?php

namespace App\Support\Providers\Shopware;

use App\Support\Integrations\ProviderCoverage;
use App\Support\Providers\WooCommerce\WooCommerceAmount;
use Illuminate\Support\Carbon;

/**
 * Maps a Shopware order (read with `includes`, so no customer data) to `order.snapshot` (ADR 0026). Shopware keeps
 * refunds only as the payment state, so the snapshot carries `refund_state` instead of refund events. Amounts are JSON
 * floats; only values exactly representable in the currency's ISO minor units are sent.
 */
class ShopwareOrderMapper
{
    private const PAID_STATES = ['paid', 'paid_partially', 'refunded', 'refunded_partially'];

    private const INACTIVE_TRANSACTION_STATES = ['cancelled', 'failed'];

    /**
     * Criteria part shared by every order read: the associations needed and nothing else.
     *
     * @return array<string, mixed>
     */
    public static function criteria(): array
    {
        return [
            'associations' => [
                'currency' => [],
                'stateMachineState' => [],
                'transactions' => ['associations' => ['stateMachineState' => [], 'paymentMethod' => []]],
            ],
            'includes' => [
                'order' => ['id', 'orderNumber', 'amountTotal', 'currency', 'stateMachineState', 'transactions', 'createdAt', 'updatedAt'],
                'currency' => ['isoCode'],
                'state_machine_state' => ['technicalName'],
                'order_transaction' => ['id', 'amount', 'stateMachineState', 'paymentMethod', 'customFields', 'createdAt', 'updatedAt'],
                'payment_method' => ['technicalName', 'handlerIdentifier'],
                'calculated_price' => ['totalPrice'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{draft: ?array<string, mixed>, skipped: ?string}
     */
    public function order(array $order): array
    {
        $id = is_string($order['id'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $order['id']) === 1 ? $order['id'] : null;
        $status = $order['stateMachineState']['technicalName'] ?? null;

        if ($id === null || ! is_string($status) || $status === '') {
            return ['draft' => null, 'skipped' => 'order_invalid'];
        }

        $currency = strtoupper((string) ($order['currency']['isoCode'] ?? ''));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return ['draft' => null, 'skipped' => 'currency_invalid'];
        }

        $exponent = WooCommerceAmount::exponent($currency);
        $total = self::minor($order['amountTotal'] ?? null, $exponent);

        if ($total === null) {
            return ['draft' => null, 'skipped' => 'amount_not_representable'];
        }

        $transaction = $this->effectiveTransaction(is_array($order['transactions'] ?? null) ? $order['transactions'] : []);
        $gateway = is_string($transaction['paymentMethod']['technicalName'] ?? null) ? $transaction['paymentMethod']['technicalName'] : '';
        $transactionState = (string) ($transaction['stateMachineState']['technicalName'] ?? '');
        $custom = is_array($transaction['customFields'] ?? null) ? $transaction['customFields'] : [];
        $captureRef = is_string($custom['swag_paypal_resource_id'] ?? null) ? trim($custom['swag_paypal_resource_id']) : '';
        $created = $this->time($order['createdAt'] ?? null);
        $changed = collect([$this->time($order['updatedAt'] ?? null), $created, $this->time($transaction['updatedAt'] ?? null)])->filter()->max();

        $data = [
            'status' => substr($status, 0, 64),
            'display_number' => is_scalar($order['orderNumber'] ?? null) ? substr((string) $order['orderNumber'], 0, 255) : null,
            'currency' => $currency,
            'currency_exponent' => $exponent,
            'total_minor' => $total,
            'gateway' => $gateway !== '' ? substr($gateway, 0, 255) : null,
            'transaction_ref' => $captureRef !== '' ? substr($captureRef, 0, 255) : null,
            'payment_expected' => $total !== '0',
            'paid_marked_at' => in_array($transactionState, self::PAID_STATES, true) ? ($this->time($transaction['updatedAt'] ?? null) ?? $this->time($transaction['createdAt'] ?? null)) : null,
            'financial_support' => $gateway === '' ? 'unknown' : (ProviderCoverage::providerForGateway($gateway) !== null ? 'supported' : 'unsupported'),
            'refund_state' => match ($transactionState) {
                'refunded' => 'full',
                'refunded_partially' => 'partial',
                default => 'none',
            },
            'payment_state' => match ($transactionState) {
                'open' => 'open',
                'in_progress', 'unconfirmed', 'reminded' => 'pending',
                'authorized' => 'authorized',
                'paid' => 'paid',
                'paid_partially' => 'partially_paid',
                'refunded' => 'refunded',
                'refunded_partially' => 'partially_refunded',
                'failed' => 'failed',
                'cancelled' => 'cancelled',
                'chargeback' => 'chargeback',
                default => null,
            },
        ];

        if ($data['payment_state'] === null) {
            unset($data['payment_state']);
        }

        if (is_string($transaction['id'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $transaction['id']) === 1) {
            $data['store_transaction_ref'] = $transaction['id'];
        }

        if ($data['display_number'] === null) {
            unset($data['display_number']);
        }

        if (is_bool($custom['swag_paypal_is_sandbox'] ?? null)) {
            $data['mode'] = $custom['swag_paypal_is_sandbox'] ? 'test' : 'live';
        }

        if ($created !== null) {
            $data['source_created_at'] = $created;
        }

        if ($changed !== null) {
            $data['source_updated_at'] = $changed;
        }

        return ['draft' => [
            'type' => 'order.snapshot',
            'aggregate_type' => 'order',
            'aggregate_id' => $id,
            'created' => Carbon::parse($changed ?? 'now')->getTimestamp(),
            'data' => $data,
            'revisioned' => true,
        ], 'skipped' => null];
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

    /** 19.99 with exponent 2 → "1999"; null when the float is not a whole number of minor units or is negative. */
    public static function minor(mixed $value, int $exponent): ?string
    {
        if (! is_int($value) && ! is_float($value) || $value < 0 || $value > 1e13) {
            return null;
        }

        $rounded = round((float) $value, $exponent);

        if (abs((float) $value - $rounded) > 1e-9) {
            return null;
        }

        return WooCommerceAmount::toMinor(number_format($rounded, $exponent, '.', ''), $exponent);
    }

    /**
     * The latest transaction that is not cancelled or failed (Shopware adds a new one when the customer changes the
     * payment method), otherwise the latest one.
     *
     * @param  list<mixed>  $transactions
     * @return array<string, mixed>
     */
    private function effectiveTransaction(array $transactions): array
    {
        $rows = array_values(array_filter($transactions, 'is_array'));
        usort($rows, fn (array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));

        foreach ($rows as $row) {
            if (! in_array($row['stateMachineState']['technicalName'] ?? null, self::INACTIVE_TRANSACTION_STATES, true)) {
                return $row;
            }
        }

        return $rows[0] ?? [];
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && strtotime($value) !== false ? Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
