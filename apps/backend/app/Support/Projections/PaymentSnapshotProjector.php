<?php

namespace App\Support\Projections;

use App\Models\EventInbox;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Support\Ingest\EventProjectionResult;

class PaymentSnapshotProjector
{
    public const ERROR_INVALID = 'payment_snapshot_invalid';

    public const ERROR_SNAPSHOT_CONFLICT = 'payment_snapshot_conflict';

    public function project(EventInbox $event): EventProjectionResult
    {
        if ($event->event_type !== EventInbox::EVENT_PAYMENT_SNAPSHOT || $event->aggregate_type !== EventInbox::AGGREGATE_PAYMENT) {
            return EventProjectionResult::ok();
        }

        $payload = $event->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : null;

        if ($data === null || ! $this->isValidPaymentData($data)) {
            return EventProjectionResult::failed(self::ERROR_INVALID);
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $event->aggregate_external_id)
            ->lockForUpdate()
            ->first();

        $now = now();
        $payloadHash = $event->payload_hash;

        if ($payment === null) {
            $payment = Payment::query()->create($this->paymentAttributes($event, $data, $payloadHash, $now));
            $this->linkEarlierTransactions($payment);

            return EventProjectionResult::ok();
        }

        $sourceUpdatedAt = strtotime($data['source_updated_at']);
        if ($sourceUpdatedAt > $payment->source_updated_at->getTimestamp()) {
            $attributes = $this->paymentAttributes($event, $data, $payloadHash, $now);
            unset($attributes['created_at']);

            $payment->forceFill($attributes)->save();
        } elseif ($sourceUpdatedAt === $payment->source_updated_at->getTimestamp() && $payment->current_payload_hash !== $payloadHash) {
            return EventProjectionResult::failed(self::ERROR_SNAPSHOT_CONFLICT);
        }

        return EventProjectionResult::ok();
    }

    /**
     * @param array<string, mixed> $data
     */
    /** Operations can arrive before their payment; they are attached once the payment exists. */
    private function linkEarlierTransactions(Payment $payment): void
    {
        FinancialTransaction::query()
            ->where('tenant_id', $payment->tenant_id)
            ->where('integration_id', $payment->integration_id)
            ->whereNull('payment_id')
            ->where('metadata->payment_external_id', $payment->external_id)
            ->update(['payment_id' => $payment->id]);
    }

    private function isValidPaymentData(array $data): bool
    {
        return in_array($data['mode'] ?? null, ['live', 'test'], true)
            && is_string($data['currency'] ?? null)
            && preg_match('/^[A-Z]{3}$/', $data['currency']) === 1
            && is_int($data['currency_exponent'] ?? null)
            && $data['currency_exponent'] >= 0
            && $data['currency_exponent'] <= 6
            && in_array($data['status'] ?? null, ['pending', 'authorized', 'captured', 'failed', 'cancelled', 'unknown'], true)
            && is_string($data['source_updated_at'] ?? null)
            && strtotime($data['source_updated_at']) !== false
            && in_array($data['source_authority'] ?? null, ['store_reported', 'independent_provider'], true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function paymentAttributes(EventInbox $event, array $data, string $payloadHash, mixed $now): array
    {
        return [
            'tenant_id' => $event->tenant_id,
            'store_id' => $event->store_id,
            'integration_id' => $event->integration_id,
            'external_id' => $event->aggregate_external_id,
            'intent_ref' => ProjectionValueNormalizer::nullableString($data['intent_ref'] ?? null),
            'charge_ref' => ProjectionValueNormalizer::nullableString($data['charge_ref'] ?? null),
            'mode' => $data['mode'],
            'currency' => $data['currency'],
            'currency_exponent' => $data['currency_exponent'],
            'status' => $data['status'],
            'source_authority' => $data['source_authority'],
            'source_updated_at' => $data['source_updated_at'],
            'current_payload_hash' => $payloadHash,
            'metadata' => array_filter([
                'provider_order_ref' => ProjectionValueNormalizer::nullableString($data['provider_order_ref'] ?? null),
                'provider_site_origin' => ProjectionValueNormalizer::nullableString($data['provider_site_origin'] ?? null),
                'provider_order_key_hash' => ProjectionValueNormalizer::nullableString($data['provider_order_key_hash'] ?? null),
            ], fn (mixed $value): bool => $value !== null),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

}
