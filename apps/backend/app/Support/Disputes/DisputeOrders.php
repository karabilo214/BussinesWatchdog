<?php

namespace App\Support\Disputes;

use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\ProviderDispute;

class DisputeOrders
{
    public function order(ProviderDispute $dispute): ?Order
    {
        if ($dispute->payment_id === null) {
            return null;
        }

        $orderId = PaymentAllocation::query()
            ->where('tenant_id', $dispute->tenant_id)
            ->where('store_id', $dispute->store_id)
            ->where('payment_id', $dispute->payment_id)
            ->whereNull('revoked_at')
            ->orderBy('created_at')
            ->value('order_id');

        return $orderId === null ? null : Order::query()
            ->where('tenant_id', $dispute->tenant_id)
            ->where('store_id', $dispute->store_id)
            ->whereKey($orderId)
            ->first();
    }

    public function forIncident(string $tenantId, string $storeId, string $fingerprint): ?ProviderDispute
    {
        return ProviderDispute::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('incident_fingerprint', $fingerprint)
            ->first();
    }
}
