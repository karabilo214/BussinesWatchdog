<?php

namespace App\Support\Billing\Paddle;

use App\Models\BillingRefund;
use App\Models\BillingSubscriptionContract;
use App\Models\BillingTransaction;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\SubscriptionProjector;
use App\Support\Platform\PlatformAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Refunds of our own subscription payments (ADR 0044): staff with payments access request one (amount, reason), the
 * platform owner approves it with an authenticator code and only then it is sent to Paddle as a refund adjustment.
 * The requested, sent and done refunds of a payment never exceed what was charged (checked under the payment lock).
 * A confirmed Paddle refund revokes access and cancels the linked subscription immediately.
 */
class PaddleRefunds
{
    private const PROVIDER_STATUS = [
        'pending_approval' => BillingRefund::STATUS_PENDING,
        'approved' => BillingRefund::STATUS_REFUNDED,
        'rejected' => BillingRefund::STATUS_DECLINED,
        'reversed' => BillingRefund::STATUS_DECLINED,
    ];

    public function __construct(
        private readonly PaddleClient $client,
        private readonly PlatformAudit $audit,
        private readonly PaddleSubscriptionActions $actions,
    ) {}

    public function request(string $staffId, string $transactionId, string $amountMinor, string $reason, bool $cancelSubscription): BillingRefund
    {
        if (preg_match('/^[1-9]\d{0,14}$/', $amountMinor) !== 1) {
            throw new BillingCheckoutRejected('refund_amount_invalid');
        }

        return DB::transaction(function () use ($staffId, $transactionId, $amountMinor, $reason, $cancelSubscription): BillingRefund {
            /** @var BillingTransaction|null $transaction */
            $transaction = BillingTransaction::query()->whereKey($transactionId)->where('mode', PaddleConfig::mode())->lockForUpdate()->first();

            if ($transaction === null) {
                throw new BillingCheckoutRejected('payment_not_found');
            }

            if ($transaction->status !== 'completed' || $transaction->total_minor === null) {
                throw new BillingCheckoutRejected('refund_not_available');
            }

            $reserved = (int) BillingRefund::query()->where('transaction_id', $transaction->id)->whereIn('status', BillingRefund::RESERVING)->sum('amount_minor');
            $amount = (int) $amountMinor;

            if ($reserved + $amount > $transaction->total_minor) {
                throw new BillingCheckoutRejected('refund_exceeds_payment', ['left_minor' => (string) max(0, $transaction->total_minor - $reserved)]);
            }

            $refund = BillingRefund::query()->create([
                'tenant_id' => $transaction->tenant_id,
                'transaction_id' => $transaction->id,
                'mode' => $transaction->mode,
                'kind' => $reserved === 0 && $amount === $transaction->total_minor ? 'full' : 'partial',
                'amount_minor' => $amount,
                'currency' => $transaction->currency,
                'currency_exponent' => $transaction->currency_exponent,
                'reason' => $reason,
                'cancel_subscription' => $cancelSubscription,
                'status' => BillingRefund::STATUS_REQUESTED,
                'requested_by' => $staffId,
            ]);
            $this->audit->record($staffId, 'billing.refund_requested', 'billing_refund', $refund->id, PlatformAudit::RESULT_SUCCEEDED, ['amount_minor' => (string) $amount, 'currency' => $transaction->currency, 'transaction' => $transaction->provider_transaction_ref, 'cancel_subscription' => $cancelSubscription], $reason, $transaction->tenant_id);

            return $refund;
        });
    }

    public function reject(string $staffId, string $refundId, string $reason): BillingRefund
    {
        return DB::transaction(function () use ($staffId, $refundId, $reason): BillingRefund {
            $refund = $this->locked($refundId);
            $refund->forceFill(['status' => BillingRefund::STATUS_REJECTED, 'decided_by' => $staffId, 'decision_reason' => $reason, 'decided_at' => Carbon::now()])->save();
            $this->audit->record($staffId, 'billing.refund_rejected', 'billing_refund', $refund->id, PlatformAudit::RESULT_SUCCEEDED, [], $reason, $refund->tenant_id);

            return $refund;
        });
    }

    public function approve(string $staffId, string $refundId, string $reason): BillingRefund
    {
        $refund = DB::transaction(function () use ($staffId, $refundId, $reason): BillingRefund {
            $refund = $this->locked($refundId);
            $refund->forceFill(['status' => BillingRefund::STATUS_SUBMITTING, 'decided_by' => $staffId, 'decision_reason' => $reason, 'decided_at' => Carbon::now()])->save();

            return $refund;
        });
        /** @var BillingTransaction $transaction */
        $transaction = BillingTransaction::query()->findOrFail($refund->transaction_id);

        try {
            $body = ['action' => 'refund', 'transaction_id' => $transaction->provider_transaction_ref, 'reason' => Str::limit($refund->reason, 200, '')];
            $body += $refund->kind === 'full' ? ['type' => 'full'] : ['type' => 'partial', 'items' => $this->items($transaction, $refund->amount_minor)];
            $adjustment = $this->client->post('/adjustments', $body)['data'] ?? [];
        } catch (PaddleRequestFailed $exception) {
            $refund->forceFill(['status' => $exception->outcomeUnknown() ? BillingRefund::STATUS_UNKNOWN : BillingRefund::STATUS_FAILED, 'error_code' => $exception->paddleCode ?? $exception->reasonCode])->save();
            $this->audit->record($staffId, 'billing.refund_approved', 'billing_refund', $refund->id, $exception->outcomeUnknown() ? 'unknown' : PlatformAudit::RESULT_FAILED, ['error' => $exception->paddleCode ?? $exception->reasonCode], $reason, $refund->tenant_id);

            throw new BillingCheckoutRejected($exception->outcomeUnknown() ? 'provider_outcome_unknown' : 'refund_rejected_by_provider');
        }

        $this->apply($refund, $adjustment);
        $this->audit->record($staffId, 'billing.refund_approved', 'billing_refund', $refund->id, PlatformAudit::RESULT_SUCCEEDED, ['adjustment' => $refund->provider_adjustment_ref, 'status' => $refund->status], $reason, $refund->tenant_id);

        return $refund->refresh();
    }

    /**
     * An adjustment event from Paddle: updates our refund, or records a refund made in the Paddle dashboard.
     *
     * @param  array<string, mixed>  $payload  a sanitized adjustment (PaddleEventRecorder::sanitize)
     */
    public function project(array $payload): ?BillingRefund
    {
        $ref = $payload['id'] ?? null;

        if (! is_string($ref) || ($payload['action'] ?? null) !== 'refund') {
            return null;
        }

        /** @var BillingRefund|null $refund */
        $refund = BillingRefund::query()->where('mode', PaddleConfig::mode())->where('provider_adjustment_ref', $ref)->first();

        if ($refund === null) {
            /** @var BillingTransaction|null $transaction */
            $transaction = BillingTransaction::query()->where('mode', PaddleConfig::mode())->where('provider_transaction_ref', $payload['transaction_id'] ?? '')->first();
            $total = $payload['totals']['total'] ?? null;

            if ($transaction === null || ! is_string($total) || preg_match('/^[1-9]\d{0,14}$/', $total) !== 1) {
                return null;
            }

            $refund = BillingRefund::query()->create([
                'tenant_id' => $transaction->tenant_id,
                'transaction_id' => $transaction->id,
                'mode' => $transaction->mode,
                'kind' => ($payload['type'] ?? null) === 'full' ? 'full' : 'partial',
                'amount_minor' => (int) $total,
                'currency' => $transaction->currency,
                'currency_exponent' => $transaction->currency_exponent,
                'reason' => is_string($payload['reason'] ?? null) ? $payload['reason'] : 'Paddle',
                'status' => BillingRefund::STATUS_PENDING,
                'provider_adjustment_ref' => $ref,
            ]);
        }

        $this->apply($refund, $payload);

        return $refund->refresh();
    }

    /**
     * Refunds whose Paddle answer was lost are matched with the transaction's adjustments.
     */
    public function settleUnknown(): int
    {
        $settled = 0;

        foreach (BillingRefund::query()->where('mode', PaddleConfig::mode())->where('status', BillingRefund::STATUS_UNKNOWN)->where('updated_at', '<', Carbon::now()->subMinute())->get() as $refund) {
            /** @var BillingTransaction $transaction */
            $transaction = BillingTransaction::query()->findOrFail($refund->transaction_id);

            try {
                $adjustments = $this->client->get('/adjustments', ['transaction_id' => $transaction->provider_transaction_ref, 'action' => 'refund'])['data'] ?? [];
            } catch (PaddleRequestFailed) {
                continue;
            }

            $linked = BillingRefund::query()->where('transaction_id', $transaction->id)->whereNotNull('provider_adjustment_ref')->pluck('provider_adjustment_ref')->all();
            $match = collect(is_array($adjustments) ? $adjustments : [])
                ->filter(fn (mixed $adjustment): bool => is_array($adjustment) && ! in_array($adjustment['id'] ?? null, $linked, true) && (string) ($adjustment['totals']['total'] ?? '') === (string) $refund->amount_minor)
                ->first();

            if ($match === null) {
                if ($refund->updated_at->lessThan(Carbon::now()->subHour())) {
                    $refund->forceFill(['status' => BillingRefund::STATUS_FAILED, 'error_code' => 'provider_outcome_not_found'])->save();
                }

                continue;
            }

            $this->apply($refund, PaddleEventRecorder::sanitize('adjustment', $match));
            $settled++;
        }

        return $settled;
    }

    /**
     * @param  array<string, mixed>  $adjustment
     */
    private function apply(BillingRefund $refund, array $adjustment): void
    {
        $status = self::PROVIDER_STATUS[$adjustment['status'] ?? ''] ?? null;
        $updatedAt = isset($adjustment['updated_at']) && is_string($adjustment['updated_at']) ? Carbon::parse($adjustment['updated_at']) : Carbon::now();

        if ($refund->source_updated_at !== null && $refund->source_updated_at->greaterThan($updatedAt)) {
            return;
        }

        $totals = is_array($adjustment['totals'] ?? null) ? $adjustment['totals'] : [];
        $refund->forceFill(array_filter([
            'provider_adjustment_ref' => is_string($adjustment['id'] ?? null) ? $adjustment['id'] : null,
            'provider_status' => is_string($adjustment['status'] ?? null) ? $adjustment['status'] : null,
            'status' => $status,
            'tax_minor' => is_string($totals['tax'] ?? null) && preg_match('/^\d{1,15}$/', $totals['tax']) === 1 ? (int) $totals['tax'] : null,
            'source_updated_at' => $updatedAt,
        ], fn (mixed $value): bool => $value !== null) + ['error_code' => null])->save();

        if ($refund->status === BillingRefund::STATUS_REFUNDED) {
            $this->revokePaidAccess($refund);
        }
    }

    private function revokePaidAccess(BillingRefund $refund): void
    {
        /** @var BillingTransaction $transaction */
        $transaction = BillingTransaction::query()->findOrFail($refund->transaction_id);
        /** @var BillingSubscriptionContract|null $contract */
        $contract = BillingSubscriptionContract::query()
            ->where('tenant_id', $refund->tenant_id)->where('mode', $refund->mode)
            ->where('provider_subscription_ref', $transaction->provider_subscription_ref)
            ->first();

        if ($contract === null) {
            return;
        }

        if ($contract->access_revoked_at === null) {
            $contract->forceFill(['access_revoked_at' => Carbon::now()])->save();
            app(SubscriptionProjector::class)->project($refund->tenant_id, $refund->mode);
            app(EntitlementCalculator::class)->forget();
        }

        if ($contract->normalized_state === BillingSubscriptionContract::STATE_ACTIVE && $contract->provider_subscription_ref !== null) {
            $this->actions->cancelForRefund($refund->tenant_id, $contract->provider_subscription_ref);
        }
    }

    /**
     * Spreads a partial amount over the transaction's line items, each up to its total.
     *
     * @return list<array{item_id: string, type: string, amount: string}>
     */
    private function items(BillingTransaction $transaction, int $amount): array
    {
        $lines = $this->client->get('/transactions/'.rawurlencode($transaction->provider_transaction_ref))['data']['details']['line_items'] ?? [];
        $items = [];
        $left = $amount;

        foreach (is_array($lines) ? $lines : [] as $line) {
            $total = (int) ($line['totals']['total'] ?? 0);

            if ($left <= 0 || $total <= 0 || ! is_string($line['id'] ?? null)) {
                continue;
            }

            $part = min($left, $total);
            $items[] = ['item_id' => $line['id'], 'type' => $part === $total ? 'full' : 'partial', 'amount' => (string) $part];
            $left -= $part;
        }

        if ($left > 0 || $items === []) {
            throw new BillingCheckoutRejected('refund_exceeds_payment');
        }

        return $items;
    }

    private function locked(string $refundId): BillingRefund
    {
        /** @var BillingRefund|null $refund */
        $refund = Str::isUuid($refundId) ? BillingRefund::query()->whereKey($refundId)->where('mode', PaddleConfig::mode())->lockForUpdate()->first() : null;

        if ($refund === null) {
            throw new BillingCheckoutRejected('refund_not_found');
        }

        if ($refund->status !== BillingRefund::STATUS_REQUESTED) {
            throw new BillingCheckoutRejected('refund_already_decided');
        }

        return $refund;
    }
}
