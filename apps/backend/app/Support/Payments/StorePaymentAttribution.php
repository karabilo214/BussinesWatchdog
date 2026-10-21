<?php

namespace App\Support\Payments;

use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * One provider account may serve several stores. A payment that carries another store's marker — a Shopware order
 * transaction id this store does not have, another site origin, another PayPal Payments invoice prefix — is not this
 * store's «payment without order»: it is kept, marked with the reason, and listed apart. A payment without any marker
 * stays this store's to review (ADR 0073).
 */
class StorePaymentAttribution
{
    public const REASON_OTHER_STORE_TRANSACTION = 'other_store_transaction';

    public const REASON_OTHER_SITE = 'other_site';

    public const REASON_OTHER_INVOICE_PREFIX = 'other_invoice_prefix';

    public function refresh(Store $store): void
    {
        $payments = Payment::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->get(['id', 'metadata', 'foreign_store_reason']);

        if ($payments->isEmpty()) {
            return;
        }

        $allocated = PaymentAllocation::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('payment_id')
            ->flip();
        $ownPrefixes = $payments
            ->filter(fn (Payment $payment): bool => $allocated->has($payment->id))
            ->map(fn (Payment $payment): ?string => self::invoicePrefix($payment))
            ->filter()
            ->unique()
            ->flip();
        $knowsTransactions = Order::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->whereNotNull('metadata->store_transaction_ref')->exists();

        foreach ($payments as $payment) {
            $reason = $allocated->has($payment->id) ? null : $this->reason($store, $payment, $ownPrefixes, $knowsTransactions);

            if ($reason !== $payment->foreign_store_reason) {
                Payment::query()->whereKey($payment->id)->where('tenant_id', $store->tenant_id)->update(['foreign_store_reason' => $reason]);
            }
        }
    }

    /**
     * @param  Collection<string, int>  $ownPrefixes
     */
    private function reason(Store $store, Payment $payment, Collection $ownPrefixes, bool $knowsTransactions): ?string
    {
        $metadata = $payment->metadata ?? [];
        $storeTransaction = $metadata['provider_store_transaction_ref'] ?? null;

        if (is_string($storeTransaction)) {
            if ($store->platform !== 'shopware') {
                return self::REASON_OTHER_STORE_TRANSACTION;
            }

            $own = Order::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('metadata->store_transaction_ref', $storeTransaction)->exists();

            return $knowsTransactions && ! $own ? self::REASON_OTHER_STORE_TRANSACTION : null;
        }

        $siteOrigin = $metadata['provider_site_origin'] ?? null;

        if (is_string($siteOrigin) && $siteOrigin !== StoreOrigin::of($store->base_url)) {
            return self::REASON_OTHER_SITE;
        }

        $prefix = self::invoicePrefix($payment);

        if ($prefix !== null && ($store->platform === 'shopware' || ($ownPrefixes->isNotEmpty() && ! $ownPrefixes->has($prefix)))) {
            return self::REASON_OTHER_INVOICE_PREFIX;
        }

        return null;
    }

    /** PayPal Payments for WooCommerce sends invoice_id = the store's invoice prefix + the order number (custom_id). */
    private static function invoicePrefix(Payment $payment): ?string
    {
        $invoice = $payment->metadata['provider_invoice_ref'] ?? null;
        $orderRef = $payment->metadata['provider_order_ref'] ?? null;

        if (! is_string($invoice) || ! is_string($orderRef) || $orderRef === '' || strlen($invoice) <= strlen($orderRef) || ! str_ends_with($invoice, $orderRef)) {
            return null;
        }

        return substr($invoice, 0, -strlen($orderRef));
    }
}
