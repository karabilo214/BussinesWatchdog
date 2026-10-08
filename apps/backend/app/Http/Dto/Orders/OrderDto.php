<?php

namespace App\Http\Dto\Orders;

use App\Models\Order;

class OrderDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Order $order): array
    {
        return [
            'id' => $order->id,
            'store_id' => $order->store_id,
            'integration_id' => $order->integration_id,
            'external_id' => $order->external_id,
            'display_number' => $order->display_number,
            'status' => $order->status,
            'gateway' => $order->gateway,
            'mode' => $order->mode,
            'currency' => $order->currency,
            'currency_exponent' => $order->currency_exponent,
            'total_minor' => (string) $order->total_minor,
            'payment_expected' => $order->payment_expected,
            'paid_marked_at' => $order->paid_marked_at?->toJSON(),
            'transaction_ref' => $order->transaction_ref,
            'financial_support' => $order->financial_support,
            'is_synthetic' => $order->is_synthetic,
            'source_created_at' => $order->source_created_at?->toJSON(),
            'source_updated_at' => $order->source_updated_at?->toJSON(),
            'created_at' => $order->created_at?->toJSON(),
            'updated_at' => $order->updated_at?->toJSON(),
        ];
    }
}
