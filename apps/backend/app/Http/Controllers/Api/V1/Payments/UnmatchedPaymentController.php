<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ListUnmatchedPaymentsRequest;
use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Store;
use App\Support\Api\UuidCursor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class UnmatchedPaymentController extends Controller
{
    public const MAX_MANUAL_CANDIDATES = 5;

    public function index(ListUnmatchedPaymentsRequest $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list unmatched payments');

        abort_unless($store->tenant_id === $tenantId, 404);

        $validated = $request->validated();
        $limit = $validated['limit'] ?? 50;

        $allocatedCaptureIds = PaymentAllocation::query()
            ->where('store_id', $store->id)
            ->whereNull('revoked_at')
            ->pluck('capture_transaction_id');

        $query = FinancialTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $store->id)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where('kind', 'capture')
            ->where('status', 'succeeded')
            ->whereNotIn('id', $allocatedCaptureIds);

        if (isset($validated['cursor'])) {
            $cursorId = UuidCursor::decode($validated['cursor']);
            abort_if($cursorId === null, 400, 'Invalid cursor.');

            $query->where('id', '>', $cursorId);
        }

        $captures = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $captures->count() > $limit;
        $captures = $captures->take($limit);

        $nextCursor = ($hasMore && $captures->isNotEmpty())
            ? UuidCursor::encode($captures->last()->id)
            : null;

        return response()->json([
            'data' => $captures->map(fn (FinancialTransaction $capture): array => $this->unmatchedEntry($store, $capture))->all(),
            'next_cursor' => $nextCursor,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function unmatchedEntry(Store $store, FinancialTransaction $capture): array
    {
        $payment = Payment::query()->find($capture->payment_id);
        $candidates = $this->candidates($store, $capture, $payment);

        return [
            'payment_id' => $payment?->id,
            'capture_transaction_id' => $capture->id,
            'currency' => $capture->currency,
            'amount_minor' => (string) $capture->amount_minor,
            'mode' => $payment?->mode,
            'occurred_at' => $capture->occurred_at?->toJSON(),
            'reason' => $candidates === [] ? 'no_candidate_found' : 'review_required',
            'candidates' => $candidates,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(Store $store, FinancialTransaction $capture, ?Payment $payment): array
    {
        $candidates = [];
        $exactOrder = null;

        if ($payment !== null && ($payment->intent_ref !== null || $payment->charge_ref !== null)) {
            $exactOrder = Order::query()
                ->where('store_id', $store->id)
                ->where(function ($query) use ($payment) {
                    if ($payment->intent_ref !== null) {
                        $query->orWhere('transaction_ref', $payment->intent_ref);
                    }

                    if ($payment->charge_ref !== null) {
                        $query->orWhere('transaction_ref', $payment->charge_ref);
                    }
                })
                ->first();
        }

        if ($exactOrder !== null) {
            $candidates[] = $this->candidateEntry($exactOrder, 'exact_candidate', $payment);
        }

        $manualCandidates = Order::query()
            ->where('store_id', $store->id)
            ->where('currency', $capture->currency)
            ->where('total_minor', $capture->amount_minor)
            ->when($exactOrder !== null, fn ($query) => $query->where('id', '!=', $exactOrder->id))
            ->get();

        $this->closestToCaptureTime($manualCandidates, $capture)
            ->each(function (Order $order) use (&$candidates, $payment) {
                $candidates[] = $this->candidateEntry($order, 'manual_review', $payment);
            });

        return $candidates;
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, Order>
     */
    private function closestToCaptureTime(Collection $orders, FinancialTransaction $capture): Collection
    {
        $capturedAt = $capture->occurred_at?->getTimestamp() ?? 0;

        return $orders
            ->sortBy(fn (Order $order): int => abs(($order->source_updated_at?->getTimestamp() ?? 0) - $capturedAt))
            ->take(self::MAX_MANUAL_CANDIDATES)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateEntry(Order $order, string $confidence, ?Payment $payment): array
    {
        return [
            'order_id' => $order->id,
            'confidence' => $confidence,
            'provider_ref' => $payment?->intent_ref ?? $payment?->charge_ref,
            'amount_minor' => (string) $order->total_minor,
            'currency' => $order->currency,
            'mode' => $order->mode,
        ];
    }
}
