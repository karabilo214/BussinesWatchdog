<?php

namespace Tests\Concerns;

use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Gives an order a provider payment that its reference names but that has no capture, so a missing capture is a
 * known fact (MONEY_CAPTURE_MISSING) and not a missing link (MONEY_PAYMENT_NOT_MATCHED, ADR 0068).
 */
trait KnownProviderPayment
{
    private function withKnownProviderPayment(Order $order): Order
    {
        $reference = 'pi_'.Str::lower(Str::random(16));

        Payment::query()->create([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'integration_id' => $order->integration_id,
            'external_id' => $reference,
            'intent_ref' => $reference,
            'charge_ref' => null,
            'mode' => $order->mode,
            'currency' => $order->currency,
            'currency_exponent' => $order->currency_exponent,
            'status' => 'pending',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'source_updated_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', $reference),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order->forceFill(['transaction_ref' => $reference])->save();

        return $order;
    }
}
