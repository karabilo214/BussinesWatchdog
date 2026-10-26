<?php

namespace App\Support\Providers\PayPal;

use App\Models\Integration;
use Illuminate\Support\Carbon;

class PayPalDisputeMapper
{
    public const SKIP_SHAPE = 'unexpected_shape';

    public const SKIP_CURRENCY = 'unsupported_currency';

    private const SELLER_FAVOUR = ['RESOLVED_SELLER_FAVOUR', 'RESOLVED_WITH_PAYOUT', 'DENIED'];

    private const BUYER_FAVOUR = ['RESOLVED_BUYER_FAVOUR', 'ACCEPTED'];

    /**
     * @param  array<string, mixed>  $dispute
     * @return array{draft: ?array<string, mixed>, skipped: ?string, capture: ?string}
     */
    public function map(Integration $integration, array $dispute, ?string $paymentExternalId): array
    {
        $id = $dispute['dispute_id'] ?? null;
        $capture = self::captureId($dispute);
        $currency = strtoupper((string) ($dispute['dispute_amount']['currency_code'] ?? ''));

        if (! is_string($id) || preg_match('/^[A-Z0-9-]{1,64}$/', $id) !== 1 || ! is_string($dispute['create_time'] ?? null)) {
            return ['draft' => null, 'skipped' => self::SKIP_SHAPE, 'capture' => $capture];
        }

        $exponent = PayPalCurrency::exponent($currency);
        $amount = $exponent === null ? null : PayPalCurrency::toMinor($dispute['dispute_amount']['value'] ?? null, $exponent);

        if ($exponent === null || $amount === null) {
            return ['draft' => null, 'skipped' => self::SKIP_CURRENCY, 'capture' => $capture];
        }

        $payment = $paymentExternalId ?? $capture;

        if ($payment === null) {
            return ['draft' => null, 'skipped' => self::SKIP_SHAPE, 'capture' => null];
        }

        $status = $this->status($dispute);
        $reason = strtolower((string) ($dispute['reason'] ?? ''));
        $due = $dispute['seller_response_due_date'] ?? null;
        $opened = Carbon::parse($dispute['create_time'])->utc();

        return ['draft' => [
            'type' => 'dispute.snapshot',
            'aggregate_type' => 'dispute',
            'aggregate_id' => $id,
            'created' => $opened->getTimestamp(),
            'revisioned' => true,
            'data' => [
                'payment_external_id' => $payment,
                'charge_ref' => $capture,
                'mode' => $integration->mode,
                'currency' => $currency,
                'currency_exponent' => $exponent,
                'amount_minor' => $amount,
                'status' => $status,
                'reason' => preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reason) === 1 ? $reason : null,
                'evidence_due_by' => is_string($due) && in_array($status, ['needs_response', 'warning_needs_response'], true) ? Carbon::parse($due)->utc()->toJSON() : null,
                'opened_at' => $opened->toJSON(),
                'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            ],
        ], 'skipped' => null, 'capture' => $capture];
    }

    /**
     * @param  array<string, mixed>  $dispute
     */
    public static function captureId(array $dispute): ?string
    {
        foreach (is_array($dispute['disputed_transactions'] ?? null) ? $dispute['disputed_transactions'] : [] as $transaction) {
            $id = is_array($transaction) ? ($transaction['seller_transaction_id'] ?? null) : null;

            if (is_string($id) && preg_match('/^[A-Z0-9]{1,36}$/', $id) === 1) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $dispute
     */
    private function status(array $dispute): string
    {
        $inquiry = ($dispute['dispute_life_cycle_stage'] ?? null) === 'INQUIRY';
        $state = (string) ($dispute['status'] ?? '');

        if ($state === 'RESOLVED') {
            $outcome = (string) ($dispute['dispute_outcome']['outcome_code'] ?? '');

            return match (true) {
                in_array($outcome, self::BUYER_FAVOUR, true) => 'lost',
                $inquiry => 'warning_closed',
                in_array($outcome, self::SELLER_FAVOUR, true) => 'won',
                default => 'closed',
            };
        }

        if ($state === 'WAITING_FOR_SELLER_RESPONSE') {
            return $inquiry ? 'warning_needs_response' : 'needs_response';
        }

        return $inquiry ? 'warning_under_review' : 'under_review';
    }
}
