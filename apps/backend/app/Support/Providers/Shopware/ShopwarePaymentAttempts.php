<?php

namespace App\Support\Providers\Shopware;

use App\Models\Integration;
use App\Models\ObservedPaymentAttempt;
use App\Support\Checkout\ObservedPaymentAttempts;
use Illuminate\Support\Carbon;

/**
 * Real payment attempts of a Shopware store (ADR 0056): every payment transaction created after the order source
 * was connected is an attempt; its state gives the outcome. Uses only the order reads the sync already makes.
 */
class ShopwarePaymentAttempts
{
    private const PAID_STATES = ['paid', 'paid_partially', 'authorized', 'refunded', 'refunded_partially', 'chargeback'];

    private const OFFLINE_METHODS = ['payment_prepayment', 'payment_invoice', 'payment_cashpayment', 'payment_debitpayment'];

    private const OFFLINE_HANDLER = '/\\\\PaymentHandler\\\\(PrePayment|InvoicePayment|CashPayment|DebitPayment)$/';

    public function __construct(
        private readonly ObservedPaymentAttempts $attempts,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $orders
     */
    public function observe(Integration $integration, array $orders): void
    {
        $observations = [];

        foreach ($orders as $order) {
            foreach ($this->fromOrder($order, $integration->created_at) as $observation) {
                $observations[] = $observation;
            }
        }

        $this->attempts->record($integration, $observations);
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<array{transaction_ref: string, order_ref: string, payment_method: string, attempted_at: Carbon, outcome: string, failure_class: ?string, outcome_at: ?Carbon}>
     */
    public function fromOrder(array $order, ?Carbon $since): array
    {
        $orderId = $order['id'] ?? null;

        if (! is_string($orderId) || preg_match('/^[0-9a-f]{32}$/', $orderId) !== 1) {
            return [];
        }

        $transactions = array_values(array_filter(is_array($order['transactions'] ?? null) ? $order['transactions'] : [], fn (mixed $row): bool => is_array($row)
            && is_string($row['id'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $row['id']) === 1
            && is_string($row['createdAt'] ?? null) && strtotime($row['createdAt']) !== false));
        usort($transactions, fn (array $a, array $b): int => Carbon::parse($a['createdAt'])->getTimestampMs() <=> Carbon::parse($b['createdAt'])->getTimestampMs());
        $observations = [];

        foreach ($transactions as $index => $transaction) {
            $attemptedAt = Carbon::parse($transaction['createdAt'])->utc();

            if ($since !== null && $attemptedAt->lessThan($since)) {
                continue;
            }

            $method = $transaction['paymentMethod'] ?? [];
            $technical = is_string($method['technicalName'] ?? null) && $method['technicalName'] !== '' ? $method['technicalName'] : 'unknown';
            $state = (string) ($transaction['stateMachineState']['technicalName'] ?? '');
            $changedAt = is_string($transaction['updatedAt'] ?? null) && strtotime($transaction['updatedAt']) !== false ? Carbon::parse($transaction['updatedAt'])->utc() : $attemptedAt;
            $offline = in_array($technical, self::OFFLINE_METHODS, true) || (is_string($method['handlerIdentifier'] ?? null) && preg_match(self::OFFLINE_HANDLER, $method['handlerIdentifier']) === 1);

            [$outcome, $class, $at] = match (true) {
                in_array($state, self::PAID_STATES, true) => [ObservedPaymentAttempt::OUTCOME_PAID, null, $changedAt],
                $state === 'failed' => [ObservedPaymentAttempt::OUTCOME_FAILED, 'status_failed', $changedAt],
                $state === 'cancelled' => [ObservedPaymentAttempt::OUTCOME_FAILED, $index < count($transactions) - 1 ? 'retried' : 'cancelled', $changedAt],
                $offline => [ObservedPaymentAttempt::OUTCOME_ON_HOLD, null, $attemptedAt],
                default => [ObservedPaymentAttempt::OUTCOME_PENDING, null, null],
            };

            $observations[] = [
                'transaction_ref' => $transaction['id'],
                'order_ref' => $orderId,
                'payment_method' => substr($technical, 0, 255),
                'attempted_at' => $attemptedAt,
                'outcome' => $outcome,
                'failure_class' => $class,
                'outcome_at' => $at,
            ];
        }

        return $observations;
    }
}
