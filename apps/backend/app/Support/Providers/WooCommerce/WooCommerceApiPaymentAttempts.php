<?php

namespace App\Support\Providers\WooCommerce;

use App\Models\Integration;
use App\Models\ObservedPaymentAttempt;
use App\Support\Checkout\ObservedPaymentAttempts;
use Illuminate\Support\Carbon;

/**
 * Real payment attempts of a WooCommerce store connected with a REST API key (ADR 0060): every checkout order
 * (Classic or Blocks) created after the key was connected is an attempt; its status gives the outcome. Uses only the
 * order reads the sync already makes.
 */
class WooCommerceApiPaymentAttempts
{
    private const CHECKOUT_SOURCES = ['checkout', 'store-api'];

    private const PAID_STATUSES = ['processing', 'completed', 'refunded'];

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
            $observation = $this->fromOrder($order, $integration->created_at);

            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        $this->attempts->record($integration, $observations);
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{transaction_ref: string, order_ref: string, payment_method: string, attempted_at: Carbon, outcome: string, failure_class: ?string, outcome_at: ?Carbon}|null
     */
    public function fromOrder(array $order, ?Carbon $since): ?array
    {
        $id = isset($order['id']) && is_scalar($order['id']) && preg_match('/^\d{1,20}$/', (string) $order['id']) === 1 ? (string) $order['id'] : null;
        $method = is_string($order['payment_method'] ?? null) ? trim($order['payment_method']) : '';
        $status = is_string($order['status'] ?? null) ? preg_replace('/^wc-/', '', $order['status']) : '';
        $created = $this->time($order['date_created_gmt'] ?? null);

        if ($id === null || $method === '' || $created === null || ! in_array($order['created_via'] ?? null, self::CHECKOUT_SOURCES, true)
            || ($since !== null && $created->lessThan($since))) {
            return null;
        }

        $paid = $this->time($order['date_paid_gmt'] ?? null);
        $changed = $this->time($order['date_modified_gmt'] ?? null) ?? $created;

        [$outcome, $class, $at] = match (true) {
            $paid !== null, in_array($status, self::PAID_STATUSES, true) => [ObservedPaymentAttempt::OUTCOME_PAID, null, $paid ?? $changed],
            $status === 'on-hold' => [ObservedPaymentAttempt::OUTCOME_ON_HOLD, null, $created],
            $status === 'failed' => [ObservedPaymentAttempt::OUTCOME_FAILED, 'status_failed', $changed],
            $status === 'cancelled' => [ObservedPaymentAttempt::OUTCOME_FAILED, 'cancelled', $changed],
            $status === 'pending' => [ObservedPaymentAttempt::OUTCOME_PENDING, null, null],
            default => [null, null, null],
        };

        if ($outcome === null) {
            return null;
        }

        return [
            'transaction_ref' => 'order:'.$id,
            'order_ref' => $id,
            'payment_method' => substr($method, 0, 255),
            'attempted_at' => $created,
            'outcome' => $outcome,
            'failure_class' => $class,
            'outcome_at' => $at,
        ];
    }

    private function time(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' && strtotime($value.'Z') !== false ? Carbon::parse($value.'Z')->utc() : null;
    }
}
