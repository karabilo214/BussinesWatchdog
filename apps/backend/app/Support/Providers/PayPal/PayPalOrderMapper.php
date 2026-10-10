<?php

namespace App\Support\Providers\PayPal;

use App\Models\Integration;
use Illuminate\Support\Carbon;

/**
 * A PayPal order (Orders v2, with its captures, authorizations and refunds) → normalized drafts, like a Stripe
 * PaymentIntent with its charges. A completed PayPal order is not captured money: only captures in COMPLETED,
 * PARTIALLY_REFUNDED or REFUNDED become capture operations; a PENDING capture keeps the payment pending (ADR 0022).
 * Only `custom_id` (the store's order id) is taken as metadata; payer data is never read.
 */
class PayPalOrderMapper
{
    public const SKIP_CURRENCY = 'unsupported_currency';

    public const SKIP_SHAPE = 'unsupported_order_shape';

    private const CAPTURED = ['COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED'];

    /**
     * @param  array<string, mixed>  $order
     * @return array{drafts: list<array<string, mixed>>, skipped: ?string}
     */
    public function map(Integration $integration, array $order): array
    {
        $units = $order['purchase_units'] ?? null;

        if (! is_string($order['id'] ?? null) || ! is_array($units) || count($units) !== 1 || ! is_array($units[0])) {
            return ['drafts' => [], 'skipped' => self::SKIP_SHAPE];
        }

        $unit = $units[0];
        $currency = strtoupper((string) ($unit['amount']['currency_code'] ?? ''));
        $exponent = PayPalCurrency::exponent($currency);

        if ($exponent === null) {
            return ['drafts' => [], 'skipped' => self::SKIP_CURRENCY];
        }

        $payments = is_array($unit['payments'] ?? null) ? $unit['payments'] : [];
        $captures = $this->list($payments['captures'] ?? null);
        $authorizations = $this->list($payments['authorizations'] ?? null);
        $refunds = $this->list($payments['refunds'] ?? null);
        $orderId = $order['id'];
        $drafts = [[
            'type' => 'payment.snapshot',
            'aggregate_type' => 'payment',
            'aggregate_id' => $orderId,
            'created' => $this->time($order['create_time'] ?? null),
            'data' => [
                'intent_ref' => $orderId,
                'charge_ref' => $this->id($captures[0] ?? null) ?? $this->id($authorizations[0] ?? null),
                'mode' => $integration->mode,
                'currency' => $currency,
                'currency_exponent' => $exponent,
                'status' => $this->paymentStatus((string) ($order['status'] ?? ''), $captures, $authorizations),
                'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
                'provider_order_ref' => $this->orderRef($unit['custom_id'] ?? null),
            ],
        ]];

        foreach ($captures as $capture) {
            $status = match ($capture['status'] ?? null) {
                'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' => 'succeeded',
                'DECLINED', 'FAILED' => 'failed',
                default => null,
            };
            $draft = $status === null ? null : $this->operation($integration, $capture, 'capture', $status, $orderId, $currency, $exponent);

            if ($draft !== null) {
                $drafts[] = $draft;
            }
        }

        foreach ($refunds as $refund) {
            $status = match ($refund['status'] ?? null) {
                'COMPLETED' => 'succeeded',
                'FAILED' => 'failed',
                'CANCELLED' => 'cancelled',
                default => 'pending',
            };
            $draft = $this->operation($integration, $refund, 'refund', $status, $orderId, $currency, $exponent);

            if ($draft !== null) {
                $drafts[] = $draft;
            }
        }

        return ['drafts' => $drafts, 'skipped' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $captures
     * @param  list<array<string, mixed>>  $authorizations
     */
    private function paymentStatus(string $orderStatus, array $captures, array $authorizations): string
    {
        $captureStatuses = array_map(fn (array $capture): string => (string) ($capture['status'] ?? ''), $captures);
        $authorizationStatuses = array_map(fn (array $authorization): string => (string) ($authorization['status'] ?? ''), $authorizations);

        return match (true) {
            array_intersect($captureStatuses, self::CAPTURED) !== [] => 'captured',
            in_array('PENDING', $captureStatuses, true) => 'pending',
            array_intersect($authorizationStatuses, ['CREATED', 'PENDING', 'PARTIALLY_CAPTURED']) !== [] => 'authorized',
            array_intersect($captureStatuses, ['DECLINED', 'FAILED']) !== [] => 'failed',
            array_intersect($authorizationStatuses, ['DENIED']) !== [] => 'failed',
            array_intersect($authorizationStatuses, ['VOIDED', 'EXPIRED']) !== [] || $orderStatus === 'VOIDED' => 'cancelled',
            in_array($orderStatus, ['CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED'], true) => 'pending',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function operation(Integration $integration, array $object, string $kind, string $status, string $orderId, string $currency, int $exponent): ?array
    {
        $id = $this->id($object);
        $amount = PayPalCurrency::toMinor($object['amount']['value'] ?? null, $exponent);

        if ($id === null || $amount === null || strtoupper((string) ($object['amount']['currency_code'] ?? '')) !== $currency) {
            return null;
        }

        return [
            'type' => 'transaction.observed',
            'aggregate_type' => 'transaction',
            'aggregate_id' => $id,
            'created' => $this->time($object['create_time'] ?? null),
            'data' => [
                'external_operation_id' => $id,
                'payment_external_id' => $orderId,
                'kind' => $kind,
                'status' => $status,
                'currency' => $currency,
                'currency_exponent' => $exponent,
                'amount_minor' => $amount,
                'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            ],
        ];
    }

    private function orderRef(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,255}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function id(mixed $object): ?string
    {
        return is_array($object) && is_string($object['id'] ?? null) && preg_match('/^[A-Z0-9]{1,36}$/', $object['id']) === 1 ? $object['id'] : null;
    }

    private function time(mixed $value): int
    {
        try {
            return is_string($value) ? Carbon::parse($value)->getTimestamp() : Carbon::now()->getTimestamp();
        } catch (\Throwable) {
            return Carbon::now()->getTimestamp();
        }
    }
}
