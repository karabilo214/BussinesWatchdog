<?php

namespace App\Support\Providers\Stripe;

use App\Models\Integration;
use App\Support\Payments\StoreOrigin;

/**
 * Stripe objects → normalized event bodies (without event id and timestamps). Payment status comes from the
 * PaymentIntent, so a failed retry never overrides a successful charge; a charge becomes a capture operation only
 * once it is captured; refunds are separate operations. Objects of the other mode (test/live) are ignored.
 *
 * @phpstan-type Draft array{type: string, aggregate_type: string, aggregate_id: string, created: int, data: array<string, mixed>}
 */
class StripeEventMapper
{
    public const SKIP_OTHER_MODE = 'other_mode';

    public const SKIP_CURRENCY = 'unsupported_currency';

    /**
     * @param  array<string, mixed>  $object
     * @return array{drafts: list<array<string, mixed>>, skipped: ?string}
     */
    public function map(Integration $integration, array $object): array
    {
        $type = (string) ($object['object'] ?? '');

        if ((($object['livemode'] ?? null) === true ? 'live' : 'test') !== $integration->mode) {
            return ['drafts' => [], 'skipped' => self::SKIP_OTHER_MODE];
        }

        $exponent = StripeCurrency::exponent((string) ($object['currency'] ?? ''));

        if ($exponent === null) {
            return ['drafts' => [], 'skipped' => self::SKIP_CURRENCY];
        }

        $drafts = match ($type) {
            'payment_intent' => [$this->intentSnapshot($integration, $object, $exponent)],
            'charge' => $this->charge($integration, $object, $exponent),
            'refund' => array_values(array_filter([$this->refund($integration, $object, $exponent)])),
            default => [],
        };

        return ['drafts' => $drafts, 'skipped' => null];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function intentSnapshot(Integration $integration, array $intent, int $exponent): array
    {
        $status = match ($intent['status'] ?? null) {
            'succeeded' => 'captured',
            'requires_capture' => 'authorized',
            'canceled' => 'cancelled',
            'processing', 'requires_action', 'requires_confirmation' => 'pending',
            'requires_payment_method' => is_array($intent['last_payment_error'] ?? null) ? 'failed' : 'pending',
            default => 'unknown',
        };

        return $this->paymentDraft($integration, (string) $intent['id'], (string) $intent['id'], $this->id($intent['latest_charge'] ?? null), $status, $intent, $exponent);
    }

    /**
     * @param  array<string, mixed>  $charge
     * @return list<array<string, mixed>>
     */
    private function charge(Integration $integration, array $charge, int $exponent): array
    {
        $intentId = $this->id($charge['payment_intent'] ?? null);
        $paymentId = $intentId ?? (string) $charge['id'];
        $drafts = [];

        if ($intentId === null) {
            $status = match (true) {
                ($charge['status'] ?? null) === 'succeeded' && ($charge['captured'] ?? false) === true => 'captured',
                ($charge['status'] ?? null) === 'succeeded' => 'authorized',
                ($charge['status'] ?? null) === 'failed' => 'failed',
                ($charge['status'] ?? null) === 'pending' => 'pending',
                default => 'unknown',
            };
            $drafts[] = $this->paymentDraft($integration, $paymentId, null, (string) $charge['id'], $status, $charge, $exponent);
        }

        $captured = ($charge['status'] ?? null) === 'succeeded' && ($charge['captured'] ?? false) === true;
        $amount = $charge['amount_captured'] ?? null;

        if ($captured && is_int($amount) && $amount > 0) {
            $drafts[] = $this->transactionDraft($integration, (string) $charge['id'], 'capture', 'succeeded', $paymentId, $amount, $charge, $exponent);
        }

        return $drafts;
    }

    /**
     * @param  array<string, mixed>  $refund
     * @return array<string, mixed>|null
     */
    private function refund(Integration $integration, array $refund, int $exponent): ?array
    {
        $paymentId = $this->id($refund['payment_intent'] ?? null) ?? $this->id($refund['charge'] ?? null);
        $amount = $refund['amount'] ?? null;

        if (! is_int($amount) || $amount < 0) {
            return null;
        }

        $status = match ($refund['status'] ?? null) {
            'succeeded' => 'succeeded',
            'failed' => 'failed',
            'canceled' => 'cancelled',
            default => 'pending',
        };

        return $this->transactionDraft($integration, (string) $refund['id'], 'refund', $status, $paymentId, $amount, $refund, $exponent);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function paymentDraft(Integration $integration, string $aggregateId, ?string $intentRef, ?string $chargeRef, string $status, array $object, int $exponent): array
    {
        return [
            'type' => 'payment.snapshot',
            'aggregate_type' => 'payment',
            'aggregate_id' => $aggregateId,
            'created' => (int) ($object['created'] ?? time()),
            'data' => [
                'intent_ref' => $intentRef,
                'charge_ref' => $chargeRef,
                'mode' => $integration->mode,
                'currency' => strtoupper((string) $object['currency']),
                'currency_exponent' => $exponent,
                'status' => $status,
                'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
                'provider_order_ref' => $this->orderRef($object),
                'provider_site_origin' => $this->siteOrigin($object),
                'provider_order_key_hash' => $this->orderKeyHash($object),
            ],
        ];
    }

    /**
     * Only the store's order id, site origin and a hash of the order key are taken from metadata (written by the
     * WooCommerce Stripe Gateway); customer email and name in the same metadata are never read (ADR 0020).
     *
     * @param  array<string, mixed>  $object
     */
    private function orderRef(array $object): ?string
    {
        $value = $object['metadata']['order_id'] ?? null;

        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,255}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function orderKeyHash(array $object): ?string
    {
        $value = $object['metadata']['order_key'] ?? null;

        return is_string($value) && $value !== '' && strlen($value) <= 255 ? hash('sha256', $value) : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function siteOrigin(array $object): ?string
    {
        return StoreOrigin::of($object['metadata']['site_url'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function transactionDraft(Integration $integration, string $operationId, string $kind, string $status, ?string $paymentId, int $amount, array $object, int $exponent): array
    {
        return [
            'type' => 'transaction.observed',
            'aggregate_type' => 'transaction',
            'aggregate_id' => $operationId,
            'created' => (int) ($object['created'] ?? time()),
            'data' => [
                'external_operation_id' => $operationId,
                'payment_external_id' => $paymentId,
                'kind' => $kind,
                'status' => $status,
                'currency' => strtoupper((string) $object['currency']),
                'currency_exponent' => $exponent,
                'amount_minor' => (string) $amount,
                'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            ],
        ];
    }

    private function id(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null;
    }
}
