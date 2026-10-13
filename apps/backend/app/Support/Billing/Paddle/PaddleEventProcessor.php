<?php

namespace App\Support\Billing\Paddle;

use App\Models\BillingCheckoutSession;
use App\Models\BillingEventInbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Works through the billing inbox: each subscription named by an event is read from Paddle once per run and projected;
 * checkout sessions follow their transaction. Failures retry with backoff, then go to dead letter; events that cannot be
 * tied to a tenant are quarantined, never guessed.
 */
class PaddleEventProcessor
{
    public function __construct(private readonly PaddleSubscriptionSync $subscriptions) {}

    /**
     * @return array{processed: int, retried: int, quarantined: int}
     */
    public function processDue(int $limit = 100): array
    {
        $now = Carbon::now();
        $summary = ['processed' => 0, 'retried' => 0, 'quarantined' => 0];
        $synced = [];
        $due = BillingEventInbox::query()
            ->where('provider', PaddleConfig::PROVIDER)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('status', BillingEventInbox::STATUS_RECEIVED)->where('next_attempt_at', '<=', $now))
                ->orWhere(fn ($query) => $query->where('status', BillingEventInbox::STATUS_PROCESSING)->where('lease_until', '<', $now)))
            ->orderBy('occurred_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($due as $id) {
            $leased = BillingEventInbox::query()
                ->whereKey($id)
                ->whereIn('status', [BillingEventInbox::STATUS_RECEIVED, BillingEventInbox::STATUS_PROCESSING])
                ->where(fn ($query) => $query->where('status', BillingEventInbox::STATUS_RECEIVED)->orWhere('lease_until', '<', $now))
                ->update(['status' => BillingEventInbox::STATUS_PROCESSING, 'lease_until' => $now->copy()->addMinutes(2), 'attempts' => DB::raw('attempts + 1')]);

            if ($leased === 0) {
                continue;
            }

            /** @var BillingEventInbox $event */
            $event = BillingEventInbox::query()->findOrFail($id);
            $outcome = $this->handle($event, $synced);
            $summary[$outcome]++;
        }

        return $summary;
    }

    /**
     * @param  array<string, string>  $synced
     * @return 'processed'|'retried'|'quarantined'
     */
    private function handle(BillingEventInbox $event, array &$synced): string
    {
        $payload = $event->sanitized_payload;

        try {
            if ($event->entity_type === 'transaction') {
                $this->followCheckout($event->entity_ref, $payload);
            }

            $subscriptionRef = $event->entity_type === 'subscription' ? $event->entity_ref : ($payload['subscription_id'] ?? null);

            if (is_string($subscriptionRef)) {
                $synced[$subscriptionRef] ??= $this->subscriptions->sync($subscriptionRef);

                if ($synced[$subscriptionRef] === 'not_linked') {
                    return $this->finish($event, BillingEventInbox::STATUS_QUARANTINED, 'subscription_not_linked');
                }
            }

            if ($event->entity_type === 'transaction') {
                app(PaddleTransactions::class)->project($payload);
            }

            if ($event->entity_type === 'adjustment') {
                app(PaddleRefunds::class)->project($payload);
            }

            return $this->finish($event, BillingEventInbox::STATUS_PROCESSED, null);
        } catch (PaddleRequestFailed $exception) {
            if ($exception->reasonCode === 'paddle_not_found') {
                return $this->finish($event, BillingEventInbox::STATUS_QUARANTINED, $exception->reasonCode);
            }

            return $this->retry($event, $exception->reasonCode);
        } catch (Throwable $exception) {
            report($exception);

            return $this->retry($event, 'processing_failed');
        }
    }

    /**
     * @return 'retried'|'quarantined'
     */
    private function retry(BillingEventInbox $event, string $errorCode): string
    {
        if ($event->attempts >= (int) config('watchdog.billing.event_max_attempts')) {
            $this->finish($event, BillingEventInbox::STATUS_DEAD_LETTER, $errorCode);

            return 'quarantined';
        }

        $event->forceFill([
            'status' => BillingEventInbox::STATUS_RECEIVED,
            'lease_until' => null,
            'error_code' => $errorCode,
            'next_attempt_at' => Carbon::now()->addMinutes(min(60, 2 ** $event->attempts)),
        ])->save();

        return 'retried';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function followCheckout(string $transactionRef, array $payload): void
    {
        $checkoutId = $payload['custom_data']['checkout_id'] ?? null;

        $tenantRef = $payload['custom_data']['tenant_id'] ?? null;

        if (! is_string($checkoutId) || ! Str::isUuid($checkoutId) || ! is_string($tenantRef) || ! Str::isUuid($tenantRef) || ($payload['origin'] ?? null) !== 'api') {
            return;
        }

        /** @var BillingCheckoutSession|null $session */
        $session = BillingCheckoutSession::query()
            ->whereKey($checkoutId)
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->where('tenant_id', $tenantRef)
            ->first();

        if ($session === null || ($session->provider_session_ref !== null && $session->provider_session_ref !== $transactionRef)) {
            return;
        }

        $status = match ($payload['status'] ?? null) {
            'paid', 'completed' => BillingCheckoutSession::STATUS_CONFIRMED,
            'canceled' => 'cancelled',
            default => null,
        };
        $attributes = ['provider_session_ref' => $transactionRef];

        if ($status !== null && $session->status !== BillingCheckoutSession::STATUS_CONFIRMED) {
            $attributes['status'] = $status;
            $attributes['confirmed_at'] = $status === BillingCheckoutSession::STATUS_CONFIRMED ? Carbon::now() : null;
        } elseif ($session->status === BillingCheckoutSession::STATUS_UNKNOWN) {
            $attributes['status'] = BillingCheckoutSession::STATUS_PENDING;
            $attributes['error_code'] = null;
        }

        $session->forceFill($attributes)->save();
    }

    /**
     * @return 'processed'|'quarantined'
     */
    private function finish(BillingEventInbox $event, string $status, ?string $errorCode): string
    {
        $event->forceFill(['status' => $status, 'lease_until' => null, 'error_code' => $errorCode, 'processed_at' => Carbon::now()])->save();

        return $status === BillingEventInbox::STATUS_PROCESSED ? 'processed' : 'quarantined';
    }
}
