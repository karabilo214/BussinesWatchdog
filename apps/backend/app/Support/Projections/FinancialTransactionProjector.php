<?php

namespace App\Support\Projections;

use App\Models\EventInbox;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Support\Ingest\EventProjectionResult;

class FinancialTransactionProjector
{
    public const ERROR_INVALID = 'transaction_observed_invalid';

    public const ERROR_OPERATION_CONFLICT = 'transaction_operation_conflict';

    public function project(EventInbox $event): EventProjectionResult
    {
        if ($event->event_type !== EventInbox::EVENT_TRANSACTION_OBSERVED || $event->aggregate_type !== EventInbox::AGGREGATE_TRANSACTION) {
            return EventProjectionResult::ok();
        }

        $payload = $event->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : null;

        if ($data === null || ! $this->isValidTransactionData($data)) {
            return EventProjectionResult::failed(self::ERROR_INVALID);
        }

        $payment = $this->payment($event, $data['payment_external_id'] ?? null);
        $operationHash = $this->operationHash($data);

        /** @var FinancialTransaction|null $transaction */
        $transaction = FinancialTransaction::query()
            ->where('integration_id', $event->integration_id)
            ->where('kind', $data['kind'])
            ->where('external_operation_id', $data['external_operation_id'])
            ->lockForUpdate()
            ->first();

        if ($transaction === null) {
            FinancialTransaction::query()->create($this->transactionAttributes($event, $data, $payment, $operationHash));

            return EventProjectionResult::ok();
        }

        if ($transaction->payment_id === null && $payment !== null) {
            $transaction->forceFill(['payment_id' => $payment->id])->save();
        }

        if ($transaction->operation_hash === $operationHash) {
            return EventProjectionResult::ok();
        }

        return $this->statusTransition($event, $transaction, $data, $operationHash)
            ? EventProjectionResult::ok()
            : EventProjectionResult::failed(self::ERROR_OPERATION_CONFLICT);
    }

    /**
     * A provider operation keeps its identity (kind, amount, currency, payment) but may change status over its life
     * (a refund goes pending → succeeded, or later failed). Only that is accepted, in observation order, with history;
     * any other difference stays a conflict (ADR 0020).
     *
     * @param  array<string, mixed>  $data
     */
    private function statusTransition(EventInbox $event, FinancialTransaction $transaction, array $data, string $operationHash): bool
    {
        $sameIdentity = $data['source_authority'] === 'independent_provider'
            && $transaction->source_authority === 'independent_provider'
            && $transaction->currency === $data['currency']
            && $transaction->currency_exponent === $data['currency_exponent']
            && (string) $transaction->amount_minor === $data['amount_minor']
            && ($transaction->metadata['payment_external_id'] ?? null) === ($data['payment_external_id'] ?? null);

        if (! $sameIdentity) {
            return false;
        }

        $metadata = $transaction->metadata ?? [];
        $observedAt = $event->observed_at->toJSON();

        if (isset($metadata['status_observed_at']) && strcmp($observedAt, (string) $metadata['status_observed_at']) < 0) {
            return true;
        }

        $metadata['status_history'] = [...($metadata['status_history'] ?? []), ['from' => $transaction->status, 'to' => $data['status'], 'observed_at' => $observedAt, 'event_id' => $event->id]];
        $metadata['status_observed_at'] = $observedAt;

        $transaction->forceFill([
            'status' => $data['status'],
            'operation_hash' => $operationHash,
            'metadata' => $metadata,
        ])->save();

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isValidTransactionData(array $data): bool
    {
        return is_string($data['external_operation_id'] ?? null)
            && $data['external_operation_id'] !== ''
            && in_array($data['kind'] ?? null, ['capture', 'refund', 'fee', 'dispute_debit', 'dispute_credit', 'adjustment'], true)
            && in_array($data['status'] ?? null, ['pending', 'succeeded', 'failed', 'cancelled'], true)
            && is_string($data['currency'] ?? null)
            && preg_match('/^[A-Z]{3}$/', $data['currency']) === 1
            && is_int($data['currency_exponent'] ?? null)
            && $data['currency_exponent'] >= 0
            && $data['currency_exponent'] <= 6
            && is_string($data['amount_minor'] ?? null)
            && preg_match('/^(0|[1-9][0-9]{0,18})$/', $data['amount_minor']) === 1
            && in_array($data['source_authority'] ?? null, ['store_reported', 'independent_provider'], true);
    }

    private function payment(EventInbox $event, mixed $paymentExternalId): ?Payment
    {
        if (! is_string($paymentExternalId) || $paymentExternalId === '') {
            return null;
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $paymentExternalId)
            ->lockForUpdate()
            ->first();

        return $payment;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function transactionAttributes(EventInbox $event, array $data, ?Payment $payment, string $operationHash): array
    {
        return [
            'tenant_id' => $event->tenant_id,
            'store_id' => $event->store_id,
            'integration_id' => $event->integration_id,
            'payment_id' => $payment?->id,
            'external_operation_id' => $data['external_operation_id'],
            'kind' => $data['kind'],
            'status' => $data['status'],
            'currency' => $data['currency'],
            'currency_exponent' => $data['currency_exponent'],
            'amount_minor' => (int) $data['amount_minor'],
            'occurred_at' => $event->occurred_at,
            'source_event_id' => $event->id,
            'source_authority' => $data['source_authority'],
            'operation_hash' => $operationHash,
            'metadata' => array_filter([
                'payment_external_id' => $data['payment_external_id'] ?? null,
                'status_observed_at' => $event->observed_at?->toJSON(),
            ], fn (mixed $value): bool => $value !== null),
            'created_at' => now(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function operationHash(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
