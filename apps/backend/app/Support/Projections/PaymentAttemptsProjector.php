<?php

namespace App\Support\Projections;

use App\Models\EventInbox;
use App\Models\PaymentAttemptWindow;
use App\Support\Checkout\PaymentAttemptMonitor;
use App\Support\Ingest\EventProjectionResult;
use Illuminate\Support\Carbon;

class PaymentAttemptsProjector
{
    public const ERROR_INVALID = 'payment_attempts_invalid';

    public const ERROR_REVISION_CONFLICT = 'payment_attempts_revision_conflict';

    public function __construct(
        private readonly PaymentAttemptMonitor $monitor,
    ) {}

    public function project(EventInbox $event): EventProjectionResult
    {
        if ($event->event_type !== EventInbox::EVENT_CHECKOUT_PAYMENT_ATTEMPTS || $event->aggregate_type !== EventInbox::AGGREGATE_CHECKOUT) {
            return EventProjectionResult::ok();
        }

        $data = is_array($event->payload['data'] ?? null) ? $event->payload['data'] : null;
        $revision = $event->aggregate_revision;

        if ($data === null || $revision === null || ! is_array($data['methods'] ?? null)) {
            return EventProjectionResult::failed(self::ERROR_INVALID);
        }

        $windowStart = Carbon::parse($data['window_start'])->utc();
        $windowEnd = Carbon::parse($data['window_end'])->utc();
        $dataHash = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $now = Carbon::now();
        $changedMethods = [];

        $existingByMethod = [];

        foreach ($data['methods'] as $method) {
            /** @var PaymentAttemptWindow|null $existing */
            $existing = PaymentAttemptWindow::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('store_id', $event->store_id)
                ->where('payment_method', $method['payment_method'])
                ->where('window_start', $windowStart)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->source_revision === $revision && $existing->payload_hash !== $dataHash) {
                return EventProjectionResult::failed(self::ERROR_REVISION_CONFLICT);
            }

            $existingByMethod[$method['payment_method']] = $existing;
        }

        foreach ($data['methods'] as $method) {
            $existing = $existingByMethod[$method['payment_method']];

            if ($existing !== null && $existing->source_revision >= $revision) {
                continue;
            }

            $attributes = [
                'integration_id' => $event->integration_id,
                'event_id' => $event->id,
                'window_end' => $windowEnd,
                'paid' => $method['paid'],
                'on_hold' => $method['on_hold'],
                'failed' => $method['failed'],
                'pending_stuck' => $method['pending_stuck'],
                'late_success' => $method['late_success'],
                'rejected_before_order' => $method['rejected_before_order'],
                'trailing_failures' => $method['trailing_failures'],
                'failure_classes' => $method['failure_classes'],
                'source_revision' => $revision,
                'payload_hash' => $dataHash,
                'updated_at' => $now,
            ];

            if ($existing === null) {
                PaymentAttemptWindow::query()->create(array_merge($attributes, [
                    'tenant_id' => $event->tenant_id,
                    'store_id' => $event->store_id,
                    'payment_method' => $method['payment_method'],
                    'window_start' => $windowStart,
                    'created_at' => $now,
                ]));
            } else {
                $existing->forceFill($attributes)->save();
            }

            $changedMethods[] = $method['payment_method'];
        }

        foreach ($changedMethods as $paymentMethod) {
            $this->monitor->evaluate($event->tenant_id, $event->store_id, $paymentMethod);
        }

        return EventProjectionResult::ok();
    }
}
