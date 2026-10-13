<?php

namespace App\Support\Billing\Paddle;

use App\Models\BillingEventInbox;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Stores a Paddle event (from the webhook or the events API) in the billing inbox with only the fields the projection
 * needs: ids, states, price ids and quantities, periods, totals and our own custom data. Customer names, emails,
 * addresses, payment details and tokens are never kept.
 */
class PaddleEventRecorder
{
    public const USED_TYPES = [
        'subscription.created', 'subscription.updated', 'subscription.activated', 'subscription.trialing',
        'subscription.past_due', 'subscription.paused', 'subscription.resumed', 'subscription.canceled',
        'subscription.imported', 'transaction.created', 'transaction.updated', 'transaction.ready',
        'transaction.paid', 'transaction.completed', 'transaction.payment_failed', 'transaction.past_due',
        'transaction.canceled', 'transaction.billed',
    ];

    /**
     * @param  array<string, mixed>  $event
     * @return 'stored'|'duplicate'|'conflict'|'invalid'
     */
    public function record(array $event, string $source): string
    {
        $eventRef = $event['event_id'] ?? null;
        $type = $event['event_type'] ?? null;
        $data = $event['data'] ?? null;
        $occurredAt = $event['occurred_at'] ?? null;

        if (! is_string($eventRef) || ! is_string($type) || ! is_array($data) || ! is_string($data['id'] ?? null) || ! is_string($occurredAt)) {
            return 'invalid';
        }

        $used = in_array($type, self::USED_TYPES, true);
        $payload = $used ? self::sanitize(Str::before($type, '.'), $data) : ['id' => $data['id']];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $existing = $this->existing($eventRef);

        if ($existing !== null) {
            return $existing->payload_hash === $hash ? 'duplicate' : 'conflict';
        }

        $tenantRef = $payload['custom_data']['tenant_id'] ?? null;

        try {
            BillingEventInbox::query()->create([
                'provider' => PaddleConfig::PROVIDER,
                'provider_account_ref' => PaddleConfig::accountRef(),
                'mode' => PaddleConfig::mode(),
                'provider_event_ref' => $eventRef,
                'event_type' => $type,
                'entity_type' => Str::before($type, '.'),
                'entity_ref' => $data['id'],
                'tenant_id' => is_string($tenantRef) && Str::isUuid($tenantRef) && Tenant::query()->whereKey($tenantRef)->exists() ? $tenantRef : null,
                'sanitized_payload' => $payload,
                'payload_hash' => $hash,
                'api_version' => (string) config('watchdog.billing.paddle.api_version'),
                'source' => $source,
                'status' => $used ? BillingEventInbox::STATUS_RECEIVED : BillingEventInbox::STATUS_IGNORED,
                'error_code' => $used ? null : 'event_type_not_used',
                'next_attempt_at' => Carbon::now(),
                'occurred_at' => Carbon::parse($occurredAt),
                'received_at' => Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->existing($eventRef)?->payload_hash === $hash ? 'duplicate' : 'conflict';
        }

        return 'stored';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitize(string $entity, array $data): array
    {
        $customData = is_array($data['custom_data'] ?? null) ? $data['custom_data'] : [];
        $items = [];

        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (is_array($item)) {
                $items[] = array_filter([
                    'price_id' => is_string($item['price']['id'] ?? null) ? $item['price']['id'] : ($item['price_id'] ?? null),
                    'quantity' => is_int($item['quantity'] ?? null) ? $item['quantity'] : null,
                    'status' => $item['status'] ?? null,
                ], fn (mixed $value): bool => $value !== null);
            }
        }

        $common = [
            'id' => $data['id'],
            'status' => self::string($data['status'] ?? null),
            'customer_id' => self::string($data['customer_id'] ?? null),
            'currency_code' => self::string($data['currency_code'] ?? null),
            'custom_data' => array_filter([
                'tenant_id' => self::string($customData['tenant_id'] ?? null),
                'checkout_id' => self::string($customData['checkout_id'] ?? null),
            ], fn (?string $value): bool => $value !== null),
            'items' => $items,
            'updated_at' => self::string($data['updated_at'] ?? null),
        ];

        if ($entity === 'subscription') {
            return [...$common,
                'current_billing_period' => self::period($data['current_billing_period'] ?? null),
                'next_billed_at' => self::string($data['next_billed_at'] ?? null),
                'scheduled_change' => is_array($data['scheduled_change'] ?? null)
                    ? ['action' => self::string($data['scheduled_change']['action'] ?? null), 'effective_at' => self::string($data['scheduled_change']['effective_at'] ?? null)]
                    : null,
                'paused_at' => self::string($data['paused_at'] ?? null),
                'canceled_at' => self::string($data['canceled_at'] ?? null),
            ];
        }

        $totals = is_array($data['details']['totals'] ?? null) ? $data['details']['totals'] : [];

        return [...$common,
            'subscription_id' => self::string($data['subscription_id'] ?? null),
            'origin' => self::string($data['origin'] ?? null),
            'invoice_number' => self::string($data['invoice_number'] ?? null),
            'billed_at' => self::string($data['billed_at'] ?? null),
            'created_at' => self::string($data['created_at'] ?? null),
            'billing_period' => self::period($data['billing_period'] ?? null),
            'totals' => array_filter([
                'subtotal' => self::string($totals['subtotal'] ?? null),
                'discount' => self::string($totals['discount'] ?? null),
                'tax' => self::string($totals['tax'] ?? null),
                'total' => self::string($totals['total'] ?? null),
                'grand_total' => self::string($totals['grand_total'] ?? null),
                'fee' => self::string($totals['fee'] ?? null),
                'earnings' => self::string($totals['earnings'] ?? null),
                'currency_code' => self::string($totals['currency_code'] ?? null),
            ], fn (?string $value): bool => $value !== null),
        ];
    }

    private function existing(string $eventRef): ?BillingEventInbox
    {
        return BillingEventInbox::query()
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->where('provider_event_ref', $eventRef)
            ->first();
    }

    /**
     * @return array{starts_at: ?string, ends_at: ?string}|null
     */
    private static function period(mixed $period): ?array
    {
        return is_array($period) ? ['starts_at' => self::string($period['starts_at'] ?? null), 'ends_at' => self::string($period['ends_at'] ?? null)] : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
