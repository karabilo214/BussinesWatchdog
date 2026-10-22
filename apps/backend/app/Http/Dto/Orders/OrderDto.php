<?php

namespace App\Http\Dto\Orders;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;

class OrderDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Order $order): array
    {
        $mode = $this->mode($order);

        return [
            'id' => $order->id,
            'store_id' => $order->store_id,
            'integration_id' => $order->integration_id,
            'external_id' => $order->external_id,
            'display_number' => $order->display_number,
            'status' => $order->status,
            'gateway' => $order->gateway,
            'mode' => $mode ?? $order->mode,
            'mode_reported' => $mode !== null || ($order->metadata['mode_reported'] ?? true) !== false,
            'currency' => $order->currency,
            'currency_exponent' => $order->currency_exponent,
            'total_minor' => (string) $order->total_minor,
            'payment_expected' => $order->payment_expected,
            'paid_marked_at' => $order->paid_marked_at?->toJSON(),
            'payment_state' => $order->metadata['payment_state'] ?? null,
            'transaction_ref' => $order->transaction_ref,
            'financial_support' => $order->financial_support,
            'is_synthetic' => $order->is_synthetic,
            'source_created_at' => $order->source_created_at?->toJSON(),
            'source_updated_at' => $order->source_updated_at?->toJSON(),
            'created_at' => $order->created_at?->toJSON(),
            'updated_at' => $order->updated_at?->toJSON(),
        ];
    }

    /**
     * The store did not report test/live: the payment the order is linked to says it (ADR 0025).
     */
    private function mode(Order $order): ?string
    {
        if (($order->metadata['mode_reported'] ?? true) !== false) {
            return null;
        }

        $mode = Payment::query()
            ->whereIn('id', PaymentAllocation::query()->where('order_id', $order->id)->whereNull('revoked_at')->select('payment_id'))
            ->value('mode');

        return is_string($mode) ? $mode : null;
    }
}
