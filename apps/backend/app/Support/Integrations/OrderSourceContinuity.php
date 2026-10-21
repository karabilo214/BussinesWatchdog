<?php

namespace App\Support\Integrations;

use App\Models\Integration;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

/** Keep a store order's identity and payment allocations when the same connector is reconnected. */
class OrderSourceContinuity
{
    /** Call inside the transaction that activates the replacement source, before its first sync. */
    public function carryForward(Integration $previous, Integration $current): bool
    {
        if ($previous->tenant_id !== $current->tenant_id || $previous->store_id !== $current->store_id
            || $previous->provider !== $current->provider || $previous->status !== Integration::STATUS_REVOKED
            || $previous->id === $current->id
            || Order::query()->where('integration_id', $current->id)->exists()
            || Refund::query()->where('integration_id', $current->id)->exists()
            || DB::table('provider_object_states')->where('integration_id', $current->id)->exists()) {
            return false;
        }

        Order::query()->where('tenant_id', $current->tenant_id)->where('store_id', $current->store_id)
            ->where('integration_id', $previous->id)->update(['integration_id' => $current->id]);
        Refund::query()->where('tenant_id', $current->tenant_id)->where('store_id', $current->store_id)
            ->where('integration_id', $previous->id)->update(['integration_id' => $current->id]);
        DB::table('provider_object_states')->where('tenant_id', $current->tenant_id)->where('store_id', $current->store_id)
            ->where('integration_id', $previous->id)->update(['integration_id' => $current->id]);

        return true;
    }
}
